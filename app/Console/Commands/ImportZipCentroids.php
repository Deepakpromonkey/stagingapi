<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportZipCentroids extends Command
{
    protected $signature = 'import:zips';
    protected $description = 'Import ZIP code centroids from uszips.csv into the database';

    public function handle()
    {
        $filePath = storage_path('app/uszips.csv');

        if (!file_exists($filePath)) {
            $this->error('Could not find the file! Make sure it is at storage/app/uszips.csv');
            return;
        }

        $this->info('Starting import...');

        $file = fopen($filePath, 'r');
        
        // Skip the header row so we don't insert "zip, lat, lng" into the database
        fgetcsv($file); 

        $chunk = [];
        $count = 0;

        while (($data = fgetcsv($file)) !== false) {
            // Updated mapping for your new master file layout!
            // 0: zip, 1: lat, 2: lng, 3: city, 4: state_id
            $chunk[] = [
                'zip'   => str_pad($data[0], 5, '0', STR_PAD_LEFT), // Keep leading zeros
                'lat'   => (float) $data[1],
                'lng'   => (float) $data[2],
                'city'  => $data[3],
                'state' => $data[4],
            ];

            // Insert into DB in batches of 1000
            if (count($chunk) >= 1000) {
                DB::connection('external_db')->table('zip_centroids')->insert($chunk);
                $count += 1000;
                $this->info("Inserted {$count} rows...");
                $chunk = []; 
            }
        }

        // Insert whatever is left over at the end
        if (!empty($chunk)) {
            DB::connection('external_db')->table('zip_centroids')->insert($chunk);
            $count += count($chunk);
        }

        fclose($file);

        $this->info("Success! Inserted a total of {$count} ZIP codes.");
    }
}