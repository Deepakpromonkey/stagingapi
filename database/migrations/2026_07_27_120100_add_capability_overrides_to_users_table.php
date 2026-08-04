<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Override capability is granted independently of the seat type, so each
     * user carries a nullable override on top of the role default:
     *
     *   null  => inherit the role's capability
     *   true  => granted for this user regardless of role
     *   false => revoked for this user regardless of role
     */
    public function up(): void
    {
        $hadSoft = Schema::hasColumn('users', 'can_override_soft');
        $hadGate = Schema::hasColumn('users', 'can_override_gate');

        Schema::table('users', function (Blueprint $table) use ($hadSoft, $hadGate) {

            if ($hadSoft) {
                $table->boolean('can_override_soft')->nullable()->default(null)->change();
            } else {
                $table->boolean('can_override_soft')->nullable()->default(null)->after('status');
            }

            if ($hadGate) {
                $table->boolean('can_override_gate')->nullable()->default(null)->change();
            } else {
                $table->boolean('can_override_gate')->nullable()->default(null)->after('can_override_soft');
            }

            if (! Schema::hasColumn('users', 'payment_release_limit')) {
                $table->decimal('payment_release_limit', 12, 2)->nullable()->after('can_override_gate');
            }
        });

        // Columns previously existed as NOT NULL DEFAULT 0, which reads as an
        // explicit revoke for every user. Reset them so the role default applies.
        if ($hadSoft || $hadGate) {
            DB::table('users')->update([
                'can_override_soft' => null,
                'can_override_gate' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_override_soft', 'can_override_gate', 'payment_release_limit']);
        });
    }
};
