<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The DT score is expensive to compute (it needs the full inspection,
     * crash and authority picture), so it is captured when the profile is
     * opened rather than recalculated for every history row.
     */
    public function up(): void
    {
        Schema::table('search_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('search_histories', 'dt_score')) {
                $table->decimal('dt_score', 5, 2)->nullable()->after('carrier_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('search_histories', function (Blueprint $table) {
            $table->dropColumn('dt_score');
        });
    }
};
