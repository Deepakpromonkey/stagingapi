<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Who filed it. Reports belong to the company, like the shortlist,
            // so a teammate can see what a colleague reported.
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            /*
            | The carrier row lives in the external carrier database, so there is
            | no foreign key to hang this off. The identifiers are denormalised
            | alongside the id for the same reason: a report has to stay readable
            | even if the external record is later replaced or renumbered.
            */
            $table->unsignedBigInteger('carrier_id')->index();
            $table->string('carrier_row_id')->nullable()->index();
            $table->string('carrier_dot_number')->nullable()->index();
            $table->string('carrier_legal_name')->nullable();

            $table->date('incident_date');

            $table->string('origin_city');
            $table->string('origin_state', 100);
            $table->string('origin_country', 100);

            $table->string('destination_city');
            $table->string('destination_state', 100);
            $table->string('destination_country', 100);

            // Slugs from CarrierReport::INCIDENTS.
            $table->json('incidents');

            $table->text('comments')->nullable();

            // Private reports are only ever visible to the reporting company.
            $table->boolean('is_private')->default(false);

            $table->string('carrier_email')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->text('email_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_reports');
    }
};
