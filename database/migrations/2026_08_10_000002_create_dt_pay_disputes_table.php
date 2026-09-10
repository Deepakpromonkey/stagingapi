<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dt_pay_disputes', function (Blueprint $table) {
            $table->id();
            
            $table->uuid('uuid')->unique('dt_pay_disputes_row_id_unique');

            $table->string('dispute_ref', 50);
            $table->string('dispute_type', 10);
            $table->string('transaction_id', 50)->nullable();
            $table->string('dispute_code', 100)->nullable();
            $table->text('dispute_details')->nullable();
            $table->string('evidence', 255)->nullable();
            $table->string('added_by', 50);
            $table->string('added_by_type', 50);
            $table->string('status', 255);
            $table->timestamps();

            // Foreign Key Constraint
            $table->foreign('transaction_id', 'dt_pay_disputes_transaction_id_foreign')
                  ->references('uuid')
                  ->on('dt_payments')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dt_pay_disputes');
    }
};