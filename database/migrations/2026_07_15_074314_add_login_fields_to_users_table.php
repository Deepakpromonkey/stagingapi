<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            
            // $table->string('status')->default('active');

            $table->boolean('two_factor_enabled')
                ->default(true);

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('two_factor_enabled');

            $table->ipAddress('last_login_ip')
                ->nullable()
                ->after('last_login_at');

            $table->timestamp('last_password_changed_at')
                ->nullable()
                ->after('last_login_ip');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'two_factor_enabled',
                'last_login_at',
                'last_login_ip',
                'last_password_changed_at'
            ]);
        });
    }
};