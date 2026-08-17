<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dt_payments', function (Blueprint $table) {
            $table->dropIndex('dt_payments_broker_id_index');
        });

        DB::statement('ALTER TABLE dt_payments MODIFY broker_id CHAR(36) NOT NULL');

        Schema::table('dt_payments', function (Blueprint $table) {
            $table->foreign('broker_id', 'dt_payments_broker_id_foreign')
                  ->references('uuid')->on('users')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dt_payments', function (Blueprint $table) {
            $table->dropForeign('dt_payments_broker_id_foreign');
        });

        DB::statement('ALTER TABLE dt_payments MODIFY broker_id VARCHAR(50) NOT NULL');

        Schema::table('dt_payments', function (Blueprint $table) {
            $table->index('broker_id', 'dt_payments_broker_id_index');
        });
    }
};
