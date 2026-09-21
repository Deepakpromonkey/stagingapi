<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The public tracking link.
 *
 * Deliberately a separate token from `uuid`, not a reuse of it. `uuid` is
 * already the identifier every authenticated broker route accepts — anything
 * that ever saw it (a browser network tab, a log line, an email header) would
 * otherwise gain permanent, unauthenticated read access to the shipment the
 * moment this feature shipped. A dedicated token keeps the public surface
 * revocable on its own: rotating it is a single column update and does not
 * touch anything the broker-side API depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->after('uuid');
        });

        /*
        | Every row that predates this column gets a token too, not just
        | shipments created from here on — otherwise every load already
        | booked stays unshareable until someone notices. Done row by row in
        | PHP rather than a single UPDATE because the token has to be unique
        | per row and MySQL has no server-side random-string function to
        | generate that in bulk.
        */
        DB::table('shipments')->whereNull('tracking_token')->orderBy('id')->pluck('id')->each(
            fn ($id) => DB::table('shipments')->where('id', $id)->update(['tracking_token' => Str::random(48)])
        );

        Schema::table('shipments', function (Blueprint $table) {
            $table->unique('tracking_token');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
