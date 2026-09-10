<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Carriers\Carrier;
use App\Models\CarrierShortlist;
use App\Models\CarrierBlocked;
use Illuminate\Support\Facades\DB;

class CarrierImportController extends Controller
{
    public function bulkImport(Request $request)
    {
        // 1. Validate the request from the popup
        $request->validate([
            'type' => 'required|string|in:monitored,blocked', // Must be one of these two
            'file' => 'required|file|mimes:csv,txt|max:10240', // Max 10MB CSV
        ]);

        $type = $request->type;
        $file = $request->file('file');
        $fileStream = fopen($file->getRealPath(), 'r');

        // 2. Read the first row to get the column headers
        $headers = fgetcsv($fileStream);
        $headers = array_map('strtolower', array_map('trim', $headers));

        // 3. Smart check: Figure out which column contains the DOT number
        $dotColumnIndex = -1;
        $possibleDotNames = ['dot', 'dot number', 'dot_number', 'usdot'];
        
        foreach ($possibleDotNames as $name) {
            $index = array_search($name, $headers);
            if ($index !== false) {
                $dotColumnIndex = $index;
                break;
            }
        }

        if ($dotColumnIndex === -1) {
            fclose($fileStream);
            return response()->json([
                'status' => 'error', 
                'message' => 'Could not find a DOT Number column in the CSV. Please ensure you have a column named "DOT" or "DOT Number".'
            ], 400);
        }

        // 4. Extract ALL DOT numbers from the CSV into a simple array
        $csvDotNumbers = [];
        while (($row = fgetcsv($fileStream)) !== false) {
            $dot = trim($row[$dotColumnIndex] ?? '');
            if (!empty($dot)) {
                $csvDotNumbers[] = $dot;
            }
        }
        fclose($fileStream);

        if (empty($csvDotNumbers)) {
            return response()->json(['status' => 'error', 'message' => 'The CSV file was empty or contained no valid DOT numbers.'], 400);
        }

        // 5. BULK QUERY: Find all carriers in our DB that match these DOTs (Super fast!)
        // Assuming your column in the 'carriers' table is named 'dot_number' (change if it's just 'dot')
        $matchedCarriers = Carrier::whereIn('dot_number', $csvDotNumbers)->get();

        if ($matchedCarriers->isEmpty()) {
            return response()->json([
                'status' => 'success', 
                'message' => 'No matching carriers found in our database to import.'
            ]);
        }

        $companyId = $request->user()->company_id;
        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            // 6. Loop through matches and insert into the correct table
            foreach ($matchedCarriers as $carrier) {
                if ($type === 'monitored') {
                    // Add to Shortlist
                    CarrierShortlist::updateOrCreate(
                        ['company_id' => $companyId, 'carrier_id' => $carrier->id],
                        ['user_id' => $userId]
                    );
                } else if ($type === 'blocked') {
                    // Add to Blocked List
                    CarrierBlocked::updateOrCreate(
                        ['company_id' => $companyId, 'carrier_id' => $carrier->id],
                        ['user_id' => $userId]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => count($matchedCarriers) . " carriers successfully added to your {$type} list.",
                'total_matched' => count($matchedCarriers)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while saving the data: ' . $e->getMessage()
            ], 500);
        }
    }
}