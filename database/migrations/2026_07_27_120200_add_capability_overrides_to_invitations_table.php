<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Override capability can be granted at invite time, independently of the
     * seat type. Null means the invited user inherits the role default.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            if (! Schema::hasColumn('invitations', 'can_override_soft')) {
                $table->boolean('can_override_soft')->nullable()->after('role_id');
            }

            if (! Schema::hasColumn('invitations', 'can_override_gate')) {
                $table->boolean('can_override_gate')->nullable()->after('can_override_soft');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['can_override_soft', 'can_override_gate']);
        });
    }
};
