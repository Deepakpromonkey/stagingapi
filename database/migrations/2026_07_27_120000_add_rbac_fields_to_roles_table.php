<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $blueprint) use ($table) {

            if (! Schema::hasColumn($table, 'slug')) {
                $blueprint->string('slug', 50)->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn($table, 'description')) {
                $blueprint->string('description', 500)->nullable()->after('guard_name');
            }

            // Seniority. Higher level = more authority.
            if (! Schema::hasColumn($table, 'level')) {
                $blueprint->unsignedSmallInteger('level')->default(0)->after('description');
            }

            // Default risk authority for the seat. Can be overridden per user.
            if (! Schema::hasColumn($table, 'can_override_soft')) {
                $blueprint->boolean('can_override_soft')->default(false)->after('level');
            }

            if (! Schema::hasColumn($table, 'can_override_gate')) {
                $blueprint->boolean('can_override_gate')->default(false)->after('can_override_soft');
            }

            // Per-payment conditional release cap. Null = no cap.
            if (! Schema::hasColumn($table, 'payment_release_limit')) {
                $blueprint->decimal('payment_release_limit', 12, 2)->nullable()->after('can_override_gate');
            }

            // Legacy roles are deactivated instead of deleted so historic
            // assignments keep resolving.
            if (! Schema::hasColumn($table, 'is_active')) {
                $blueprint->boolean('is_active')->default(true)->after('payment_release_limit');
            }
        });
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            foreach (['description', 'level', 'can_override_soft', 'can_override_gate', 'payment_release_limit', 'is_active'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }

            if (Schema::hasColumn($table, 'slug')) {
                $blueprint->dropUnique([$table.'_slug_unique']);
                $blueprint->dropColumn('slug');
            }
        });
    }
};
