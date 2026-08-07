<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | The compliance paperwork the carrier hands over before signing: the
        | W-9 and the certificate of insurance.
        |
        | A table rather than columns on carrier_connect_requests (the shape the
        | factoring notice uses) because the broker side needs to enumerate
        | whatever the carrier uploaded, and because the list of required
        | documents is the kind of thing that grows.
        */
        Schema::create('carrier_connect_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('carrier_connect_request_id')
                ->constrained('carrier_connect_requests')
                ->cascadeOnDelete();

            // One of CarrierConnectDocument::TYPES.
            $table->string('type', 30);

            $table->string('disk', 30);
            $table->string('path');
            $table->string('name');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime')->nullable();

            $table->timestamps();

            // Re-uploading replaces, so a request holds at most one of each.
            $table->unique(
                ['carrier_connect_request_id', 'type'],
                'carrier_connect_documents_request_type_unique'
            );
        });

        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->timestamp('documents_completed_at')
                ->nullable()
                ->after('questionnaire_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropColumn('documents_completed_at');
        });

        Schema::dropIfExists('carrier_connect_documents');
    }
};
