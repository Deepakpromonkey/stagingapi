<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {

            // What the company does — broker, carrier, shipper and so on.
            // Asked on signup, see config/subscriptions.php for the list.
            $table->string('business_type', 50)
                ->nullable()
                ->after('industry');

            // USDOT number. Nullable on purpose: a brand new brokerage often
            // has no authority yet, and shippers never have one at all.
            $table->string('dot_number', 20)
                ->nullable()
                ->after('business_type');

            $table->index('business_type');
            $table->index('dot_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['business_type']);
            $table->dropIndex(['dot_number']);

            $table->dropColumn(['business_type', 'dot_number']);
        });
    }
};
