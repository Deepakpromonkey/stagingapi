<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_agreement_documents', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            // Scoped to the company, like every other broker record.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Who uploaded it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');

            $table->text('description')->nullable();

            // Storage
            $table->string('disk')->default('s3');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Only active agreements are sent to carriers.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_agreement_documents');
    }
};
