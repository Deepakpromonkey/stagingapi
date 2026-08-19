<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CarrierShortlist;
use App\Models\CarrierBlocked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarrierExportController extends Controller
{
    public function export(Request $request)
    {
        // 1. Validate the request (expecting ?type=monitored or ?type=blocked)
        $request->validate([
            'type' => 'required|string|in:monitored,blocked',
        ]);

        $type = $request->type;
        $companyId = $request->user()->company_id;

        // 2. Fetch the correct list exactly like our GET APIs, grouped perfectly by company
        if ($type === 'monitored') {
            $records = CarrierShortlist::where('company_id', $companyId)
                ->with(['carrier', 'user'])
                ->get();
            $filename = 'monitored_carriers.csv';
        } else {
            $records = CarrierBlocked::where('company_id', $companyId)
                ->with(['carrier', 'user'])
                ->get();
            $filename = 'blocked_carriers.csv';
        }

        // 3. Define the headers telling the browser to download a CSV file
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        // 4. Define the CSV columns
        $columns = ['Carrier Name', 'DOT Number', 'Email', 'Phone', 'Added By', 'Date Added'];

        // 5. Stream the response
        $callback = function() use($records, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns); // Write the headers first

            foreach ($records as $record) {
                // Failsafe in case a carrier was completely deleted from the DB
                if (!$record->carrier) continue; 

                // Get the name of the user who added/blocked them
                $addedBy = $record->user 
                    ? trim($record->user->first_name . ' ' . $record->user->last_name) 
                    : 'Unknown';

                // Map the data to the columns (adjust carrier fields if your DB uses different names)
                $row = [
                    $record->carrier->name ?? 'N/A', 
                    $record->carrier->dot_number ?? '',
                    $record->carrier->email ?? 'N/A',
                    $record->carrier->phone ?? 'N/A',
                    $addedBy,
                    $record->created_at ? $record->created_at->format('Y-m-d H:i:s') : ''
                ];

                fputcsv($file, $row); 
            }

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }
}