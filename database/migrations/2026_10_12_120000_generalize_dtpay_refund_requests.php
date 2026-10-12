<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Releases now go through DollarTraq too: a broker (or guest payer) asks,
| an admin releases. Refund and release requests share one table, told
| apart by `type`; existing rows are refund requests.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('dtpay_refund_requests', 'dtpay_requests');

        Schema::table('dtpay_requests', function (Blueprint $table) {
            $table->string('type', 16)->default('refund')->after('uuid')->index();
            $table->string('requested_by_type', 16)->default('broker')->after('requested_by');   // broker | guest
            $table->string('reason', 500)->nullable()->change();                                 // a release note is optional
            $table->unsignedBigInteger('company_id')->nullable()->change();                      // guest payments have none
        });
    }

    public function down(): void
    {
        Schema::table('dtpay_requests', function (Blueprint $table) {
            $table->dropColumn(['type', 'requested_by_type']);
        });

        Schema::rename('dtpay_requests', 'dtpay_refund_requests');
    }
};
