<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create the table
        Schema::create('dt_pay_increments', function (Blueprint $table) {
            $table->integer('id')->autoIncrement();
            $table->string('type', 15)->default('');
            $table->string('prefix', 20)->default('');
            $table->integer('increment');
            $table->dateTime('added_on');
            $table->dateTime('updated_on');
        });

        // 2. Insert initial data
        DB::table('dt_pay_increments')->insert([
            [
                'id'         => 1,
                'type'       => 'shipment',
                'prefix'     => 'SH',
                'increment'  => 0,
                'added_on'   => '2025-02-18 06:13:21',
                'updated_on' => '2026-05-20 10:55:03',
            ],
            [
                'id'         => 2,
                'type'       => 'dtpay',
                'prefix'     => 'DTPAY',
                'increment'  => 0,
                'added_on'   => '2026-07-04 15:04:56',
                'updated_on' => '2026-07-24 16:18:35',
            ],
            [
                'id'         => 3,
                'type'       => 'dtpay_dispute',
                'prefix'     => 'DP',
                'increment'  => 0,
                'added_on'   => '2026-07-05 18:29:31',
                'updated_on' => '2026-07-05 16:44:08',
            ],
            [
                'id'         => 4,
                'type'       => 'dtpay_appeal',
                'prefix'     => 'AP',
                'increment'  => 0,
                'added_on'   => '2026-07-05 18:29:31',
                'updated_on' => '2026-07-08 11:25:05',
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dt_pay_increments');
    }
};