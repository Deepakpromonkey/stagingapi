<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A carrier owner inviting someone into their own account.
     *
     * Mirrors `invitations` on the broker side, including the fact that the
     * account is created immediately and the row is stamped accepted — the
     * invitee gets working credentials by email rather than a link to accept.
     */
    public function up(): void
    {
        Schema::create('carrier_invitations', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('carrier_company_id')
                ->constrained('carrier_companies')
                ->cascadeOnDelete();

            // The login created for the invitee.
            $table->foreignId('carrier_user_id')
                ->nullable()
                ->constrained('carrier_users')
                ->nullOnDelete();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email');

            $table->string('token', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('carrier_users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['carrier_company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_invitations');
    }
};
