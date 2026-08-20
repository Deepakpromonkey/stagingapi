<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::create('carrier_blockeds', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        /*
        | Deliberately NOT a foreign key, matching carrier_shortlists.
        |
        | Carriers are not local: App\Models\Carriers\Carrier reads the
        | `external_db` connection, where `carriers` is a view over
        | company_census_file in the separate `carrier` database. `constrained()`
        | resolved against the LOCAL newbrokerapi.carriers instead — an unrelated,
        | empty table — so every insert died with a 1452 constraint violation.
        | MySQL cannot enforce a key across databases, let alone against a view.
        */
        $table->unsignedBigInteger('carrier_id');
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); 
        $table->timestamps();
        
        $table->unique(['company_id', 'carrier_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_blockeds');
    }
};
