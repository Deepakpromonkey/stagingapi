<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('row_id')->nullable()->unique();
            $table->string('dot_number')->unique();
            $table->string('legal_name')->nullable()->index();
            $table->string('dba_name')->nullable()->index();
            $table->string('carrier_operation', 1)->nullable();
            $table->string('hm_flag')->default('N');
            $table->string('pc_flag')->default('N');
            $table->string('phy_street')->nullable()->index();
            $table->string('phy_city')->nullable()->index();
            $table->string('phy_state', 10)->nullable()->index();
            $table->string('phy_zip', 20)->nullable();
            $table->string('phy_country', 10)->nullable();
            $table->string('mailing_street')->nullable()->index();
            $table->string('mailing_city')->nullable();
            $table->string('mailing_state', 10)->nullable();
            $table->string('mailing_zip', 20)->nullable();
            $table->string('mailing_country', 10)->nullable();
            $table->string('telephone', 30)->nullable()->index();
            $table->string('fax', 30)->nullable()->index();
            $table->string('email_address')->nullable()->index();
            $table->string('mcs150_date')->nullable();
            $table->string('mcs150_mileage')->nullable();
            $table->string('mcs150_mileage_year')->nullable();
            $table->string('add_date')->nullable();
            $table->string('oic_state', 10)->nullable();
            $table->integer('nbr_power_unit')->nullable();
            $table->integer('driver_total')->nullable();
            $table->string('recent_mileage')->nullable();
            $table->string('recent_mileage_year')->nullable();
            $table->string('vmt_source_id')->nullable();
            $table->string('private_only')->nullable();
            $table->string('authorized_for_hire')->nullable();
            $table->string('exempt_for_hire')->nullable();
            $table->string('private_property')->nullable();
            $table->string('private_passenger_business')->nullable();
            $table->string('private_passenger_nonbusiness')->nullable();
            $table->string('migrant')->nullable();
            $table->string('us_mail')->nullable();
            $table->string('federal_government')->nullable();
            $table->string('state_government')->nullable();
            $table->string('local_government')->nullable();
            $table->string('indian_tribe')->nullable();
            $table->string('op_other')->nullable();
            $table->string('crgo_utility')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('carriers');
    }
};