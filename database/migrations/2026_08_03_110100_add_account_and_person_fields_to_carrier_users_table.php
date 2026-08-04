<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A carrier_user was the trucking company. It is now a person inside one.
     *
     * `legal_name` / `dot_number` stay on the row as the snapshot they always
     * were; the authoritative company record moves to carrier_companies.
     */
    public function up(): void
    {
        Schema::table('carrier_users', function (Blueprint $table) {

            $table->foreignId('carrier_company_id')
                ->nullable()
                ->after('uuid')
                ->constrained('carrier_companies')
                ->cascadeOnDelete();

            // Invited users are people, not companies, so they have names.
            $table->string('first_name', 100)->nullable()->after('carrier_company_id');
            $table->string('last_name', 100)->nullable()->after('first_name');

            // The account holder: created by onboarding, cannot be removed by
            // the users they invite.
            $table->boolean('is_owner')->default(false)->after('status');

            $table->foreignId('invited_by')
                ->nullable()
                ->after('is_owner')
                ->constrained('carrier_users')
                ->nullOnDelete();
        });

        $this->backfillAccounts();
    }

    /**
     * Every login that predates this migration is the owner of its own
     * account. Logins that share a DOT number are folded into one.
     */
    protected function backfillAccounts(): void
    {
        $existing = DB::table('carrier_users')
            ->whereNull('carrier_company_id')
            ->orderBy('id')
            ->get();

        // DOT number => account id, for folding shared logins together.
        $accountsByDot = [];

        // Account ids that already have an owner.
        $owned = [];

        foreach ($existing as $carrierUser) {

            $dot = $carrierUser->dot_number ?: null;

            $accountId = $dot ? ($accountsByDot[$dot] ?? null) : null;

            if (! $accountId) {

                $accountId = DB::table('carrier_companies')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'legal_name' => $carrierUser->legal_name,
                    'dot_number' => $dot,
                    'phone' => $carrierUser->phone,
                    'status' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($dot) {
                    $accountsByDot[$dot] = $accountId;
                }
            }

            // First login onto an account owns it; anything folded in behind
            // it joins as a member.
            $isOwner = ! isset($owned[$accountId]);

            $owned[$accountId] = true;

            DB::table('carrier_users')
                ->where('id', $carrierUser->id)
                ->update([
                    'carrier_company_id' => $accountId,
                    'is_owner' => $isOwner,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('carrier_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropConstrainedForeignId('carrier_company_id');
            $table->dropColumn(['first_name', 'last_name', 'is_owner']);
        });
    }
};
