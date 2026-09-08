<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Who created or last edited it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');

            // Which outgoing mail this template is for (invitation, otp, …).
            $table->string('type', 50)->default('custom');

            $table->string('subject');

            // Rich text from the editor, signature included.
            $table->longText('body_html');

            $table->boolean('is_active')->default(true);

            // The template actually used when this type of mail is sent.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
