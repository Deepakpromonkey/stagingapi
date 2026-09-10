<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CarrierSeeder extends Seeder
{
    public function run()
    {
        $carriers = [];
        
        for ($i = 1; $i <= 10; $i++) {
            $carriers[] = [
                'row_id' => 'DUMMY-ROW-' . str_pad($i, 4, '0', STR_PAD_LEFT), // e.g. DUMMY-ROW-0001
                'dot_number' => '12345' . $i,
                'legal_name' => 'Demo Logistics ' . $i . ' LLC',
                'dba_name' => 'Demo Express ' . $i,
                'carrier_operation' => 'A',
                'hm_flag' => 'N',
                'pc_flag' => 'N',
                'phy_city' => 'Chicago',
                'phy_state' => 'IL',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        DB::table('carriers')->insert($carriers);
    }
}