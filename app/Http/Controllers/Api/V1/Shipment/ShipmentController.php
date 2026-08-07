<?php
 
namespace App\Http\Controllers\Api\V1\Shipment;
 
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Shipment\CreateShipmentRequest;

use App\Http\Resources\ShipmentResource;
use App\Services\ShipmentService;
use App\Models\ShipmentTemplate;
use App\Models\Shipment;
// use App\Models\DriverLocation;
use Illuminate\Support\Facades\DB;
 
class ShipmentController extends BaseController
{
    public function __construct(
        protected ShipmentService $shipmentService
    ) {}
 
    public function getCarrier()
{
   $carriers = DB::connection('external_db')
    ->table('carrier_connect_requests as ccr')
    ->join('carriers as c', 'ccr.receiver_id', '=', 'c.row_id')
    ->leftJoin('carrier_authorities as ca', 'c.dot_number', '=', 'ca.dot_number')
    ->select(
        'ccr.*',
        'c.id as carrier_id',
        'c.row_id',
        'c.dot_number',
        'c.legal_name',
        'c.dba_name',
        'ca.docket_number as mc_number'
    )
    ->get();

 
    return response()->json($carriers);
}
 
    public function store(CreateShipmentRequest $request)
    {
        $data = $request->validated();
        $user = auth()->user();
 
        $shipment = $this->shipmentService->create($data, $user);
 
        if ($request->boolean('save_as_template')) {
            ShipmentTemplate::updateOrCreate(
                [
                    'company_id' => $user->company_id,
                    'tracking_number' => $data['tracking_number']
                ],
                [
                    'user_id' => $user->id,
                    'template_name' => $request->input('template_name', 'Template ' . $data['tracking_number']),
                    'template_data' => $data  
                ]
            );
        }
 
        return $this->success(
            new ShipmentResource($shipment),
            'Shipment created successfully.',
            201
        );
    }
 



public function addStops(Request $request, $uuid)
    {
        $request->validate([
            'stops_data' => 'required|string',
        ]);
 
        $stopsArray = json_decode($request->stops_data, true);
 
        if (!is_array($stopsArray) || count($stopsArray) < 2) {
            return $this->error('Invalid stops data. Minimum 2 stops required.', 422);
        }
 
        $shipment = \App\Models\Shipment::where('uuid', $uuid)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
 
        $updatedShipment = $this->shipmentService->addStops($shipment, $stopsArray, $request);
 
        return $this->success(
            $updatedShipment,
            'Trip Sheet stops saved successfully.',
            200
        );
    }




 
    public function index()
    {
        $shipments = $this->shipmentService->getAllForUser(auth()->user());
 
        return $this->success(
            \App\Http\Resources\ShipmentResource::collection($shipments),
            'Shipments retrieved successfully.',
            200
        );
    }



    public function detail($uuid)
{
    $shipment = DB::table('shipments')
        ->where('uuid', $uuid)
        ->where('company_id', auth()->user()->company_id)
        ->first();

    if (!$shipment) {
        return $this->error('Shipment not found.', 404);
    }

    $stops = DB::table('shipment_stops')
        ->where('shipment_id', $shipment->id)
        ->orderBy('stop_number')
        ->get();

    $shipment->stops = $stops;

    return $this->success(
        $shipment,
        'Shipment details retrieved successfully.',
        200
    );
}




public function getLocations($uuid)
    {
        $shipment = Shipment::where('uuid', $uuid)->first();

        if (!$shipment) {
            return response()->json([
                'status' => false,
                'message' => 'Shipment not found.'
            ], 404);
        }

        // USING DB::table() INSTEAD OF THE MODEL!
        $locations = DB::table('driver_locations')
            ->where('shipment_uuid', $uuid)
            ->orderBy('device_timestamp', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Shipment locations retrieved successfully.',
            'data' => [
                'shipment_no' => $shipment->shipment_no,
                'total_pings' => $locations->count(),
                'locations' => $locations
            ]
        ]);
    }









    public function checkProNumber(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'pro_number' => 'required|string'
        ]);

        $exists = \App\Models\Shipment::where('pro_number', $request->pro_number)->exists();

        return response()->json([
            'status' => 'success',
            'exists' => $exists,
            'message' => $exists ? 'PRO number found.' : 'PRO number is available.'
        ]);
    }

}