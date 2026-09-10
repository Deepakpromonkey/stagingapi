<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {

            // Denormalised out of the Didit payload so flagged onboardings can be
            // filtered without unpacking the JSON column.
            $table->boolean('didit_risk_flagged')->default(false)->after('didit_response');
            $table->string('didit_registration_ip', 45)->nullable()->after('didit_risk_flagged');

            // Factoring, collected alongside bank verification. Null means the
            // carrier has not answered yet, which is distinct from answering no.
            $table->boolean('uses_factoring_company')->nullable()->after('stripe_verified_at');
            $table->string('factoring_company_name')->nullable()->after('uses_factoring_company');
            $table->string('factoring_document_disk', 30)->nullable()->after('factoring_company_name');
            $table->string('factoring_document_path')->nullable()->after('factoring_document_disk');
            $table->string('factoring_document_name')->nullable()->after('factoring_document_path');
            $table->timestamp('factoring_answered_at')->nullable()->after('factoring_document_name');

            $table->timestamp('questionnaire_completed_at')->nullable()->after('factoring_answered_at');

            $table->index('didit_risk_flagged');
        });

        // The broker's own questions, answered by the carrier. Normalised rather
        // than the old single `broker_questionnaire` text blob, so an answer can
        // carry an uploaded file and stay linked to the question it belongs to.
        Schema::create('carrier_connect_answers', function (Blueprint $table) {

            $table->id();

            $table->foreignId('carrier_connect_request_id')
                ->constrained('carrier_connect_requests')
                ->cascadeOnDelete();

            $table->foreignId('carrier_question_id')
                ->constrained('carrier_questions')
                ->cascadeOnDelete();

            // Snapshot of the question as asked. The broker can edit or delete a
            // question later, and the answer must still make sense on its own.
            $table->text('question_text');
            $table->string('answer_type');

            $table->text('answer')->nullable();

            $table->string('answer_document_disk', 30)->nullable();
            $table->string('answer_document_path')->nullable();
            $table->string('answer_document_name')->nullable();

            $table->timestamps();

            $table->unique(
                ['carrier_connect_request_id', 'carrier_question_id'],
                'carrier_connect_answers_request_question_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_connect_answers');

        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropIndex(['didit_risk_flagged']);

            $table->dropColumn([
                'didit_risk_flagged',
                'didit_registration_ip',
                'uses_factoring_company',
                'factoring_company_name',
                'factoring_document_disk',
                'factoring_document_path',
                'factoring_document_name',
                'factoring_answered_at',
                'questionnaire_completed_at',
            ]);
        });
    }
};
