<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The files that came attached to a reply — usually the certificate itself.
 *
 * Kept as rows pointing at the disk rather than as blobs in the responses
 * table: a certificate is a few hundred kilobytes, the reply row is read on
 * every poll of the card, and a `longblob` on that row would be dragged along
 * every time. The bytes are on the filesystem disk; this table is the index,
 * and the sha-256 is what makes "the agency sent the same PDF three times"
 * answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coi_insurance_response_attachments', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('coi_insurance_response_id')
                ->constrained('coi_insurance_responses')
                ->cascadeOnDelete();

            // As the agency named it. Shown to the broker, never used to build
            // the storage path — the path is the uuid, so a hostile filename
            // has nothing to traverse.
            $table->string('filename');

            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Which disk the path is on, recorded rather than assumed: an
            // install that stored locally before S3 was configured still has
            // to be able to find its own files.
            $table->string('disk', 32);
            $table->string('path');

            $table->string('sha256', 64)->nullable();

            $table->timestamps();

            $table->index('coi_insurance_response_id', 'coi_attachments_response_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coi_insurance_response_attachments');
    }
};
