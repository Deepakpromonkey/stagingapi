<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayLogsModel;
use App\Models\DtPay\DtPayPaymentLoadsModel;
use App\Models\Carriers\CarriersModel;
use App\Models\Customers\CustomersModel;

use App\Models\Shipments\ShipmentsModel;
use App\Models\Shipments\ShipmentsTrackingMethodsModel;

use Illuminate\Support\Facades\Log;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

class DtPayCarriersController extends Controller
{

    public function transactionalLoads(Request $request, DtPayModel $dt_pay_model, ShipmentsModel $shipments_model, CustomersModel $customers_model){

        $user = $request->user();

        $shipments = [];

        if($user){

            $_shipments = $shipments_model
                            ->select("shipments.*", "customers.first_name", "customers.last_name", "dt_payments.row_id as payment_id", "dt_payments.status as payment_status")
                            ->where('shipments.shippment_carrier', $user->row_id)
                            ->join($customers_model->getTable(), "shipments.customer", "=", "customers.row_id")
                            ->join($dt_pay_model->getTable(), "shipments.row_id", "=", "dt_payments.load_id")
                            ->get();

            if($_shipments->count()){

                foreach($_shipments as $_shipment){

                    $_shipment->amount_formatted = Number::currency($_shipment->amount);

                    $state_label = "Cleared · in hold";

                    if($_shipment->payment_status == 'init'){

                        $state_label = "Cleared · in hold";
                    }

                    if($_shipment->payment_status == 'review'){

                        $state_label = "Under review";
                    }

                    if($_shipment->payment_status == 'hold'){

                        $state_label = "Manual hold";
                    }

                    $_shipment->state_label = $state_label;

                    $shipments[] = $_shipment;
                }
            }

            return response()->json(['status' => true, 'loads' => $shipments], 200);
        }

        return response()->json(['error' => 'Unauthorized access'], 400);
    }

    public function initPod(Request $request, ShipmentsModel $shipments_model, ShipmentsTrackingMethodsModel $shipments_tracking_methods_model, CustomersModel $customers_model){

        $user = $request->user();

        $shipments = [];

        if($user){

            $statuses = array_column($shipments_model->status(), 'value', 'key');
            $status_color = $shipments_model->status_colors();

            /*
            Fetch all loads
            */
            $_shipments = $shipments_model
                            ->select("shipments.*", "customers.first_name", "customers.last_name", "shipment_tracking_methods.title as tracking_method_label")
                            ->where('shipments.shippment_carrier', $user->row_id)
                            ->where('shipments.pod', null)
                            ->whereIn('shipments.status', [$shipments_model::STATUS_IN_TRANSIT, $shipments_model::STATUS_DELIVERED])
                            ->join($customers_model->getTable(), "shipments.customer", "=", "customers.row_id")
                            ->leftJoin($shipments_tracking_methods_model->getTable(), "shipments.tracking_method", "=", "shipment_tracking_methods.row_id")
                            ->get();

            if($_shipments->count()){

                $shipment_ids = $_shipments->pluck('row_id')->all();

                $first_pickups = [];
                $last_drop_offs = [];

                $stops = DB::table('shipment_stops')
                            ->whereIn('shipment_id', $shipment_ids)
                            ->whereIn('stop_type', ['pickup', 'drop_off'])
                            ->orderBy('sort_order', 'asc')
                            ->get();

                foreach($stops as $stop){

                    if($stop->stop_type == 'pickup' && !isset($first_pickups[$stop->shipment_id])){
                        $first_pickups[$stop->shipment_id] = $stop;
                    }

                    if($stop->stop_type == 'drop_off'){
                        $last_drop_offs[$stop->shipment_id] = $stop;
                    }
                }

                foreach($_shipments as $_shipment){

                    $_shipment->pickup = $first_pickups[$_shipment->row_id] ?? null;
                    $_shipment->drop_off = $last_drop_offs[$_shipment->row_id] ?? null;

                    $_shipment->amount_formatted = Number::currency($_shipment->amount);

                    $_shipment->status_label = $statuses[$_shipment->status] ?? 'NA';
                    $_shipment->status_color = $status_color[$_shipment->status] ?? 'default';

                    $_shipment->pod_label = '';

                    $shipments[] = $_shipment;
                }
            }

            return response()->json(['status' => true, 'loads' => $shipments], 200);
        }

        return response()->json(['error' => 'Unauthorized access'], 404);
    }

    public function uploadPod(Request $request, ShipmentsModel $shipments_model){

        $user = $request->user();

        if($user){

            $validator = Validator::make($request->all(), [
                'load' => 'required|string|exists:shipments,row_id',
                'pod' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            ]);

            if($validator->fails()){

                return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
            }

            $data = $validator->validated();

            $load = $shipments_model->where('row_id', $data['load'])->where('shippment_carrier', $user->row_id)->first();

            if(!$load){

                return response()->json(['status' => false, 'message' => 'Load not found.'], 404);
            }

            $pod_path = $this->storePodDocument($request->file('pod'));

            ShipmentsModel::where('row_id', $load->row_id)->update([
                'pod' => $pod_path,
                'pod_date' => now(),
                'pod_uploaded_by' => $user->row_id,
            ]);

            $transaction = DtPayModel::where('load_id', $load->row_id)->first();

            if($transaction){

                DtPayModel::where('load_id', $load->row_id)->update([
                    'status' => DtPayModel::STATUS_REVIEW
                ]);

                DtPayLogsModel::create([
                    'payment_id' => $transaction->row_id,
                    'transaction_label' => "POD Uploaded (carrier)",
                    'sub_label' => "by " . $user->legal_name,
                    'added_by' => $user->row_id,
                    'added_by_type' => 'carrier',
                    'transaction_date' => now()
                ]);
            }

            return response()->json(['status' => true, 'message' => 'POD Uploaded successfully.'], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function fetchTransaction(Request $request, ShipmentsModel $shipments_model, DtPayModel $dt_pay_model, CustomersModel $customers_model){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){

                $shipment = $shipments_model
                            ->select("shipments.*", "customers.first_name", "customers.last_name")
                            ->where('shipments.row_id', $transaction->load_id)
                            ->join($customers_model->getTable(), "shipments.customer", "=", "customers.row_id")
                            ->first();

                $transaction->shipment_number = '';
                $transaction->broker = '';

                if($shipment){

                    $transaction->shipment_number = $shipment->shipment_number;
                    $transaction->broker = $shipment->first_name . " " . $shipment->last_name;

                    $first_pickups = [];
                    $last_drop_offs = [];

                    $stops = DB::table('shipment_stops')
                            ->where('shipment_id', $shipment->row_id)
                            ->whereIn('stop_type', ['pickup', 'drop_off'])
                            ->orderBy('sort_order', 'asc')
                            ->get();

                    foreach($stops as $stop){

                        if($stop->stop_type == 'pickup' && !isset($first_pickups[$stop->shipment_id])){
                            $first_pickups[$stop->shipment_id] = $stop;
                        }

                        if($stop->stop_type == 'drop_off'){
                            $last_drop_offs[$stop->shipment_id] = $stop;
                        }
                    }

                    $transaction->pickup = $first_pickups[$shipment->row_id] ?? null;
                    $transaction->drop_off = $last_drop_offs[$shipment->row_id] ?? null;
                }

                $amounts = $dt_pay_model->calculations($transaction->amount, $transaction->payment_method);

                $timeline = [];

                if($transaction->payment_id != ''){

                    $timeline[] = ['label' => 'Broker funded · hold active', 'date' => date("m D", strtotime($transaction->payment_date)), 'text' => 'Your money is secured before you haul'];
                }else{

                    $timeline[] = ['label' => 'Broker funded not initiated', 'date' => '-', 'text' => 'Your money is secure.'];
                }

                if($shipment->pod !== ''){

                    $timeline[] = ['label' => 'Your POD under verification', 'date' => date("m D", strtotime($transaction->pod_date)), 'text' => ''];
                }else{

                    $timeline[] = ['label' => 'Upload POD', 'date' => '', 'text' => ''];
                }

                $transaction->amount_formatted = Number::currency($transaction->amount);

                return response()->json(['status' => true, 'amounts' => $amounts, 'timeline' => $timeline, 'transaction' => $transaction], 200);
            }
        }

        return response()->json(['status' => true, 'amounts' => [], 'timeline' => []], 200);
    }

    private function storePodDocument($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/carriers/pod/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }
}
