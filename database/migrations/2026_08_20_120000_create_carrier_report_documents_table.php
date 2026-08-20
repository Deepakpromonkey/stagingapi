<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence attached to an incident report — a rate confirmation, a photo of
     * damaged freight, an email thread.
     *
     * Its own table rather than a json column on carrier_reports: a report can
     * carry several files, and each needs a disk and path the download route
     * can resolve without unpacking a blob.
     */
    public function up(): void
    {
        Schema::create('carrier_report_documents', function (Blueprint $table) {
            $table->id();

            // Addressed by uuid rather than id, so the download route cannot be
            // walked through one file at a time.
            $table->uuid('uuid')->unique();

            $table->foreignId('carrier_report_id')
                ->constrained('carrier_reports')
                ->cascadeOnDelete();

            // Where the file actually landed. Recorded per row because the
            // default disk can change, and an old row must still resolve.
            $table->string('disk', 50);
            $table->string('path');

            $table->string('name');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_report_documents');
    }
};
