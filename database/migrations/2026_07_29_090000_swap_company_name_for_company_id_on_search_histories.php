<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Search history is scoped to the broker's company, so the company is
     * stored by id rather than repeating the carrier's name on every row.
     */
    public function up(): void
    {
        Schema::table('search_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('search_histories', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained()
                    ->cascadeOnDelete();
            }

            if (Schema::hasColumn('search_histories', 'company_name')) {
                $table->dropColumn('company_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('search_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');

            $table->string('company_name')->nullable();
        });
    }
};
