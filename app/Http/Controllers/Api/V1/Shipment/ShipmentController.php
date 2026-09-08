<?php

namespace App\Http\Controllers\Api\V1\Shipment;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Shipment\CreateShipmentRequest;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Models\ShipmentTemplate;
use App\Services\DriverActivityService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentController extends BaseController
{
    public function __construct(
        protected ShipmentService $shipmentService,
        protected DriverActivityService $driverActivity,
        protected SubscriptionService $subscriptionService
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

        // Plans buy a monthly load allowance — 100 on Standard, 250 on Pro.
        // Checked before anything is written so a refused load leaves nothing
        // half-created behind it.
        $this->subscriptionService->assertCanCreateLoad($user->company);

        $shipment = $this->shipmentService->create($data, $user);

        if ($request->boolean('save_as_template')) {
            ShipmentTemplate::updateOrCreate(
                [
                    'company_id' => $user->company_id,
                    'tracking_number' => $data['tracking_number'],
                ],
                [
                    'user_id' => $user->id,
                    'template_name' => $request->input('template_name', 'Template '.$data['tracking_number']),
                    'template_data' => $data,
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

        if (! is_array($stopsArray) || count($stopsArray) < 2) {
            return $this->error('Invalid stops data. Minimum 2 stops required.', 422);
        }

        $shipment = Shipment::where('uuid', $uuid)
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
            ShipmentResource::collection($shipments),
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

        if (! $shipment) {
            return $this->error('Shipment not found.', 404);
        }

        $stops = DB::table('shipment_stops')
            ->where('shipment_id', $shipment->id)
            ->orderBy('stop_number')
            ->get();

        /*
        | What the driver replied to the custom events on each stop.
        |
        | The answers are written by the driver app, which keeps its own tables
        | in this database — hence the plain query rather than a relation. One
        | query for the whole load, then attached per stop, so a ten-stop trip
        | sheet does not cost ten round trips.
        */
        $answers = DB::table('stop_event_answers')
            ->where('shipment_id', $shipment->id)
            ->orderBy('id')
            ->get()
            ->groupBy('shipment_stop_id');

        $stops = $stops->map(function ($stop) use ($answers) {
            $stop->event_answers = $answers->get($stop->id, collect())->values();

            return $stop;
        });

        $shipment->stops = $stops;

        $shipment->send_updates_to = DB::table('shipment_tracking_updates')
            ->where('shipment_id', $shipment->id)
            ->orderBy('sequence')
            ->get(['date_time', 'tracking_days', 'interval']);

        /*
        | Everything the driver app recorded on this load: who drove it, the GPS
        | trail, equipment photos, and each stop's arrival / OTP / seal / POD.
        | The control tower is the one screen that shows the broker's plan and
        | the driver's execution side by side, so it is served in one response.
        */
        $this->driverActivity->attachTo($shipment);

        return $this->success(
            $shipment,
            'Shipment details retrieved successfully.',
            200
        );
    }

    public function checkProNumber(Request $request)
    {
        $request->validate([
            'pro_number' => 'required|string',
        ]);

        $exists = Shipment::where('pro_number', $request->pro_number)->exists();

        return response()->json([
            'status' => 'success',
            'exists' => $exists,
            'message' => $exists ? 'PRO number found.' : 'PRO number is available.',
        ]);
    }
}
