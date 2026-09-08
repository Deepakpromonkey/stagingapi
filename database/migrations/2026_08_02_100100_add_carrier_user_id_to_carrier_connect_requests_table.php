<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties a finished onboarding to the carrier login it provisioned.
     *
     * The portal_account_* columns are what CarrierConnectRequestResource
     * already reports to the broker, so a failed provisioning shows up on the
     * onboarding itself rather than only in the log.
     */
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('carrier_connect_requests', 'carrier_user_id')) {
                $table->foreignId('carrier_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('carrier_users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('carrier_connect_requests', 'portal_account_provisioned_at')) {
                $table->timestamp('portal_account_provisioned_at')->nullable()->after('signed_at');

                // The address the login was created on, kept beside the request
                // so the broker can see it without joining to carrier_users.
                $table->string('portal_account_email')->nullable()->after('portal_account_provisioned_at');

                // Why the carrier has no login yet, when they should have one.
                $table->string('portal_account_error')->nullable()->after('portal_account_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_user_id');
            $table->dropColumn([
                'portal_account_provisioned_at',
                'portal_account_email',
                'portal_account_error',
            ]);
        });
    }
};
