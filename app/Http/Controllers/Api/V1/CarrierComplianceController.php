<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CarrierCompliance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CarrierComplianceController extends Controller
{
   public function importCsv(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt,xlsx,xls', // Added excel mimes just in case, though CSV is best
            'type' => 'required|in:carb,phmsa,smartway',
        ]);

        $type = $request->type;
        $file = $request->file('file');
        
        $handle = fopen($file->getRealPath(), 'r');
        
        $motorCarrierIndex = false;
        $headerFound = false;

        // 1. SMART SCANNER: Loop through the top rows until we find the actual headers
        while (($row = fgetcsv($handle)) !== false) {
            // Trim all spaces from the current row
            $row = array_map('trim', $row);
            
            // Check if this row contains either of our target column names
            $idxMotorCarrier = array_search('Motor Carrier', $row);
            $idxUsDot = array_search('US DOT', $row);

            if ($idxMotorCarrier !== false) {
                $motorCarrierIndex = $idxMotorCarrier;
                $headerFound = true;
                break; // Stop scanning! We found the CARB/PHMSA header.
            } elseif ($idxUsDot !== false) {
                $motorCarrierIndex = $idxUsDot;
                $headerFound = true;
                break; // Stop scanning! We found the SmartWay header.
            }
        }

        // If we went through the whole file and found neither, throw an error
        if (!$headerFound || $motorCarrierIndex === false) {
            fclose($handle);
            return response()->json(['error' => 'Could not find "Motor Carrier" or "US DOT" column in the file.'], 400);
        }

        $records = [];
        
        // 2. DATA PROCESSING: The file pointer is now perfectly resting right below the headers.
        while (($row = fgetcsv($handle)) !== false) {

            $rawDotNumber = trim($row[$motorCarrierIndex] ?? '');

            // Skip empty rows, junk text, or anything that isn't a strict number
            if (empty($rawDotNumber) || !is_numeric($rawDotNumber)) {
                continue;
            }

            // Strip hidden leading zeros and cast to string for DB consistency
            $dotNumber = (string) (int) $rawDotNumber;

            $records[] = [
                'dot_number' => $dotNumber,
                $type => true,
            ];

            // Chunk inserts for memory efficiency
            if (count($records) >= 500) {
                $this->upsertRecords($records, $type);
                $records = [];
            }
        }

        // Push any remaining records
        if (count($records) > 0) {
            $this->upsertRecords($records, $type);
        }

        fclose($handle);

        return response()->json([
            'message' => "Successfully imported $type compliance list!"
        ]);
    }

    private function upsertRecords(array $records, string $type)
    {
         
        CarrierCompliance::upsert(
            $records,
            ['dot_number'], 
            [$type]         
        );
    }


    public function checkCompliance(Request $request)
    {
        $request->validate([
            'dot_number' => 'required',
        ]);

        $dotNumber = $request->input('dot_number');

        $compliance = CarrierCompliance::where('dot_number', $dotNumber)->first();

        if ($compliance) {
            return response()->json([
                'success' => true,
                'data' => [
                    'dot_number' => $dotNumber,
                    'phmsa'      => (bool) $compliance->phmsa,
                    'carb'       => (bool) $compliance->carb,
                    'smartway'   => (bool) $compliance->smartway,
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'dot_number' => $dotNumber,
                'phmsa'      => false,
                'carb'       => false,
                'smartway'   => false,
            ]
        ]);
    }


}