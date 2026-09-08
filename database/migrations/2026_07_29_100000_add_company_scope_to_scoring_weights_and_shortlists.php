<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scoring weights and carrier shortlists belong to the company, not to the
     * individual who happened to create them — colleagues need to see the same
     * data. `user_id` is kept to record who last touched the row.
     */
    public function up(): void
    {
        Schema::table('scoring_weights', function (Blueprint $table) {
            // The table predates the shared schema on some installs.
            if (Schema::hasColumn('scoring_weights', 'customer_id') && ! Schema::hasColumn('scoring_weights', 'user_id')) {
                $table->renameColumn('customer_id', 'user_id');
            }
        });

        Schema::table('scoring_weights', function (Blueprint $table) {
            if (! Schema::hasColumn('scoring_weights', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasColumn('scoring_weights', 'is_template')) {
                $table->boolean('is_template')->default(false)->after('user_id');
            }

            if (! Schema::hasColumn('scoring_weights', 'template_name')) {
                $table->string('template_name')->nullable()->after('is_template');
            }
        });

        Schema::table('carrier_shortlists', function (Blueprint $table) {
            if (! Schema::hasColumn('carrier_shortlists', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            }
        });

        // One shortlist per company, not per user, so two colleagues adding the
        // same carrier do not create duplicate entries.
        $this->swapShortlistUnique();

        $this->backfillCompanyIds();
    }

    protected function swapShortlistUnique(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM carrier_shortlists'))->pluck('Key_name')->unique();

        if ($indexes->contains('carrier_shortlists_user_id_carrier_id_unique')) {
            Schema::table('carrier_shortlists', function (Blueprint $table) {
                $table->dropUnique('carrier_shortlists_user_id_carrier_id_unique');
            });
        }

        if (! $indexes->contains('carrier_shortlists_company_id_carrier_id_unique')) {
            Schema::table('carrier_shortlists', function (Blueprint $table) {
                $table->unique(['company_id', 'carrier_id']);
            });
        }
    }

    /**
     * Existing rows predate the column, so inherit the creator's company.
     */
    protected function backfillCompanyIds(): void
    {
        foreach (['scoring_weights', 'carrier_shortlists'] as $table) {
            DB::table($table)
                ->whereNull('company_id')
                ->update([
                    'company_id' => DB::raw("(select company_id from users where users.id = {$table}.user_id)"),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('scoring_weights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('carrier_shortlists', function (Blueprint $table) {
            $table->dropUnique('carrier_shortlists_company_id_carrier_id_unique');
            $table->dropConstrainedForeignId('company_id');
            $table->unique(['user_id', 'carrier_id']);
        });
    }
};
