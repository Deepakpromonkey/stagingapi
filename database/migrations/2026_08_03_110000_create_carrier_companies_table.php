<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trucking company behind a set of portal logins.
     *
     * Until now a carrier portal account was a single login and nothing else.
     * Once a carrier can invite their own dispatchers and drivers there has to
     * be something for those users to belong to — this is the carrier-side
     * equivalent of `companies`.
     *
     * Distinct from `carriers`, which is the FMCSA directory we sync and read
     * from: this table only exists for carriers who actually hold a login, and
     * `dot_number` is the loose link between the two.
     */
    public function up(): void
    {
        Schema::create('carrier_companies', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            $table->string('legal_name')->nullable();

            // One account per DOT number. Nullable because an account can be
            // provisioned from an onboarding that never carried one.
            $table->string('dot_number', 50)->nullable()->unique();

            $table->string('phone', 30)->nullable();

            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_companies');
    }
};
