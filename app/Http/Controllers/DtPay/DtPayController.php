<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayLogsModel;
use App\Models\DtPay\DtPayPaymentLoadsModel;

use App\Models\Customers\CustomersModel;
use App\Models\Shipments\ShipmentsModel;

use App\Models\Carriers\CarriersModel;
use App\Models\Shipments\ShipmentsTrackingMethodsModel;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

use Illuminate\Support\Number;
use Illuminate\Support\Str;

class DtPayController extends Controller
{
    
    public function fetchLoads(Request $request, ShipmentsModel $shipments_model, CarriersModel $carriers_model, ShipmentsTrackingMethodsModel $shipments_tracking_methods_model){

        $user = $request->user();

        $shipments = [];

        if($user){

            $statuses = array_column($shipments_model->status(), 'value', 'key');
            $status_color = $shipments_model->status_colors();

            /*
            Fetch all loads
            */
            $_shipments = $shipments_model
                            ->select("shipments.*", "carriers.legal_name", "shipment_tracking_methods.title as tracking_method_label")
                            ->where('shipments.customer', $user->row_id)
                            ->whereIn('shipments.status', [$shipments_model::STATUS_IN_TRANSIT, $shipments_model::STATUS_DELIVERED])
                            ->join($carriers_model->getTable(), "shipments.shippment_carrier", "=", "carriers.row_id")
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

    public function initFunding(
        Request $request,
        DtPayModel $dt_pay_model,
        ShipmentsModel $shipments_model,
        CarriersModel $carriers_model,
        ShipmentsTrackingMethodsModel $shipments_tracking_methods_model
    ){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model->find($transaction_id);

                if($transaction){

                    $load = $shipments_model
                            ->select("shipments.*", "carriers.legal_name", "shipment_tracking_methods.title as tracking_method_label")
                            ->where('shipments.row_id', $transaction->load_id)
                            ->join($carriers_model->getTable(), "shipments.shippment_carrier", "=", "carriers.row_id")
                            ->leftJoin($shipments_tracking_methods_model->getTable(), "shipments.tracking_method", "=", "shipment_tracking_methods.row_id")
                            ->first();
                    
                    if($load){

                        $load->pickup = null;
                        $load->drop_off = null;

                        $stops = DB::table('shipment_stops')
                            ->where('shipment_id', $load->row_id)
                            ->whereIn('stop_type', ['pickup', 'drop_off'])
                            ->orderBy('sort_order', 'asc')
                            ->get();

                        foreach($stops as $stop){

                            if($stop->stop_type == 'pickup' && !isset($first_pickups[$stop->shipment_id])){

                                $load->pickup = $stop;
                            }

                            if($stop->stop_type == 'drop_off'){

                                $load->drop_off = $stop;
                            }
                        }

                        $load->amount_formatted = Number::currency($load->amount);

                        /*
                        Stripe
                        */
                        $stripe_sources = $dt_pay_model->stripePaymentSources($user);

                        $amount = $load->amount;

                        $amounts = $dt_pay_model->calculations($amount);

                        return response()->json(['status' => true, 'load' => $load, 'sources' => $stripe_sources, 'amounts' => $amounts], 200);
                    }
                }
            }
        }

        return response()->json(['status' => false, 'load' => null, 'sources' => []], 200);
    }

    public function paymentFinish(
        Request $request,
        DtPayModel $dt_pay_model,
        ShipmentsModel $shipments_model,
        CarriersModel $carriers_model,
        ShipmentsTrackingMethodsModel $shipments_tracking_methods_model
    ){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model->find($transaction_id);

                if($transaction){

                    $load = $shipments_model
                            ->select("shipments.*", "carriers.legal_name", "shipment_tracking_methods.title as tracking_method_label")
                            ->where('shipments.row_id', $transaction->load_id)
                            ->join($carriers_model->getTable(), "shipments.shippment_carrier", "=", "carriers.row_id")
                            ->leftJoin($shipments_tracking_methods_model->getTable(), "shipments.tracking_method", "=", "shipment_tracking_methods.row_id")
                            ->first();
                    
                    if($load){

                        $load->pickup = null;
                        $load->drop_off = null;

                        $stops = DB::table('shipment_stops')
                            ->where('shipment_id', $load->row_id)
                            ->whereIn('stop_type', ['pickup', 'drop_off'])
                            ->orderBy('sort_order', 'asc')
                            ->get();

                        foreach($stops as $stop){

                            if($stop->stop_type == 'pickup' && !isset($first_pickups[$stop->shipment_id])){

                                $load->pickup = $stop;
                            }

                            if($stop->stop_type == 'drop_off'){

                                $load->drop_off = $stop;
                            }
                        }

                        $load->amount_formatted = Number::currency($load->amount);

                        $amounts = $dt_pay_model->calculations($load->amount, $transaction->payment_method);

                        $progress = [];

                        $final_amount = round(((float) $transaction->amount + (float) $transaction->card_fee + (float) $transaction->fee), 2, PHP_ROUND_HALF_UP);

                        $progress['label'] = Number::currency($final_amount) . " debited via " . ($transaction->payment_method == 'card' ? 'Card' : 'ACH');

                        if($transaction->payment_method_id != ''){
                        
                            $stripe_model = new StripeModel;

                            list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                            $stripe = new StripeClient($api_secret_key);

                            $paymentMethod = $stripe->paymentMethods->retrieve($transaction->payment_method_id);

                            if($paymentMethod){

                                if($transaction->payment_method == 'card'){

                                    $payment_method_label = strtoupper($paymentMethod->card->display_brand) . "...-" . $paymentMethod->card->last4;
                                }else{

                                    $payment_method_label = "ACH " . $paymentMethod->us_bank_account->bank_name . "...-" . $paymentMethod->us_bank_account->last4;
                                }

                                $progress['steps'][] = ['step' => 'Now', 'label' => 'ACH debit initiated', 'text' => $payment_method_label . ' · clears in 1-2 business days'];
                            }
                        }

                        $progress['steps'][] = ['step' => 'Next', 'label' => 'Funds clear → hold active', 'text' => 'Release unlocks once settled'];
                        $progress['steps'][] = ['step' => 'Then', 'label' => 'POD verified by AI', 'text' => 'Carrier uploads · Vault agent matches against the load'];
                        $progress['steps'][] = ['step' => 'Finally', 'label' => 'You release → carrier paid', 'text' => 'Next ACH batch 6:00 PM CT, or instant for 1%'];

                        return response()->json(['status' => true, 'load' => $load, 'amounts' => $amounts, 'progress' => $progress], 200);
                    }
                }
            }
        }

        return response()->json(['status' => false, 'load' => null, 'sources' => []], 200);
    }

    public function paymentManualFinish(
        Request $request,
        DtPayModel $dt_pay_model,
        ShipmentsModel $shipments_model,
        CarriersModel $carriers_model,
        ShipmentsTrackingMethodsModel $shipments_tracking_methods_model,
        DtPayPaymentLoadsModel $dt_pay_payment_loads_model
    ){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model
                                ->select(
                                    "dt_payments.*",
                                    "dt_payments_loads.load_ref",
                                    "dt_payments_loads.carrier_invoice",
                                    "dt_payments_loads.origin",
                                    "dt_payments_loads.destination",
                                    "dt_payments_loads.pickup_date",
                                    "dt_payments_loads.delivery_date",
                                    "dt_payments_loads.equipment",
                                    "dt_payments_loads.commodity",
                                    "dt_payments_loads.weight",
                                    "dt_payments_loads.linehaul_rate",
                                    "dt_payments_loads.accessorials",
                                    "dt_payments_loads.total_to_carrier",
                                    "dt_payments_loads.rate_confirmation",
                                    "dt_payments_loads.pod",
                                    "carriers.legal_name",
                                    "carriers.dba_name",
                                    "carriers.dot_number",
                                    "carriers.email_address as carrier_email_address"
                                )
                                ->where('dt_payments.row_id', $transaction_id)
                                ->join($dt_pay_payment_loads_model->getTable(), "dt_payments.row_id", "=", "dt_payments_loads.transaction_id")
                                ->join($carriers_model->getTable(), "dt_payments.carrier_id", "=", "carriers.row_id")
                                ->first();

                if($transaction){

                    $transaction->amount_formatted = Number::currency($transaction->total_to_carrier);

                    /*
                    Stripe sources
                    */
                    $stripe_sources = $dt_pay_model->stripePaymentSources($user);

                    $amount = $transaction->amount;

                    $amounts = $dt_pay_model->calculations($amount, $transaction->payment_method);

                    $progress = [];

                    $final_amount = round(((float) $transaction->amount + (float) $transaction->card_fee + (float) $transaction->fee), 2, PHP_ROUND_HALF_UP);

                    $progress['label'] = Number::currency($final_amount) . " debited via " . ($transaction->payment_method == 'card' ? 'Card' : 'ACH');

                    if($transaction->payment_method_id != ''){
                    
                        $stripe_model = new StripeModel;

                        list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                        $stripe = new StripeClient($api_secret_key);

                        $paymentMethod = $stripe->paymentMethods->retrieve($transaction->payment_method_id);

                        if($paymentMethod){

                            if($transaction->payment_method == 'card'){

                                $payment_method_label = strtoupper($paymentMethod->card->display_brand) . "...-" . $paymentMethod->card->last4;
                            }else{

                                $payment_method_label = "ACH " . $paymentMethod->us_bank_account->bank_name . "...-" . $paymentMethod->us_bank_account->last4;
                            }

                            $progress['steps'][] = ['step' => 'Now', 'label' => 'ACH debit initiated', 'text' => $payment_method_label . ' · clears in 1-2 business days'];
                        }
                    }

                    $progress['steps'][] = ['step' => 'Next', 'label' => 'Funds clear → hold active', 'text' => 'Release unlocks once settled'];
                    $progress['steps'][] = ['step' => 'Then', 'label' => 'POD verified by AI', 'text' => 'Carrier uploads · Vault agent matches against the load'];
                    $progress['steps'][] = ['step' => 'Finally', 'label' => 'You release → carrier paid', 'text' => 'Next ACH batch 6:00 PM CT, or instant for 1%'];

                    return response()->json(['status' => true, 'transaction' => $transaction, 'amounts' => $amounts, 'progress' => $progress], 200);
                }
            }
        }

        return response()->json(['status' => false, 'load' => null, 'sources' => []], 200);
    }

    public function initTransaction(Request $request, DtPayModel $dt_pay_model, ShipmentsModel $shipments_model, DtPayLogsModel $dt_pay_logs_model){

        $user = $request->user();

        if($user){

            $load_id = $request->post('load_id');

            if($load_id){

                /*
                Fetch load
                */
                $load = $shipments_model->where('row_id', $load_id)->first();

                if($load){

                    DB::beginTransaction();

                    try {
                    
                        $row_id = (string) Str::ulid();
                        $payment_ref = $dt_pay_model->create_payment_number('AUTO');

                        $amount = $load->amount;
                        $platform_fee = $dt_pay_model->calculatePercentage($amount, $dt_pay_model->platform_fees());

                        DtPayModel::create([
                            'row_id' => $row_id,
                            'broker_id' => $user->row_id,
                            'carrier_id' => $load->shippment_carrier,
                            'load_id' => $load->row_id,
                            'source' => DtPayModel::SOURCE_AUTO,
                            'payment_ref' => $payment_ref,
                            'amount' => $load->amount,
                            'fee' => $platform_fee,
                            'payment_date' => date('Y-m-d H:i:s'),
                            'status' => DtPayModel::STATUS_INIT,
                        ]);

                        /*
                        Generate logs
                        */
                        DtPayLogsModel::create([
                            'payment_id' => $row_id,
                            'transaction_label' => "Payment created (auto)",
                            'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                            'added_by' => $user->row_id,
                            'added_by_type' => 'broker',
                            'transaction_date' => now(),
                            'load_ref' => $load_id
                        ]);

                        DB::commit();

                        $dt_pay_model->update_payment_number();

                        return response()->json(['status' => true, 'row_id' => $row_id], 200);

                    }catch(\Exception $e){

                        Log::error('DTPay Transaction Error: ' . $e->getMessage());
                        DB::rollback();
                    }
                }
            }
        }

        return response()->json(['status' => false, 'message' => 'Load not found!'], 404);
    }

    public function calculateAmounts(Request $request, DtPayModel $dt_pay_model){

        $user = $request->user();

        if($user){

            $method_type = $request->post('method_type');
            $mode = $request->post('mode');
            $method_id = $request->post('method_id');
            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){

                $amounts = $dt_pay_model->calculations($transaction->amount, $method_type);

                $stripe_model = new StripeModel;

                list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                $stripe = new StripeClient($api_secret_key);

                $paymentMethod = $stripe->paymentMethods->retrieve($method_id);

                $payment_method_label = '';

                if($paymentMethod){

                    if($method_type == 'card'){

                        $payment_method_label = strtoupper($paymentMethod->card->display_brand) . "...-" . $paymentMethod->card->last4;
                    }else{

                        $payment_method_label = "ACH " . $paymentMethod->us_bank_account->bank_name . "...-" . $paymentMethod->us_bank_account->last4;
                    }
                }

                /*
                Update transaction
                */
                $transaction->update([
                    'card_fee' => $amounts['ach_funding_fee'],
                    'fee' => $amounts['platform_fee'],
                    'payment_method' => $method_type,
                    'payment_method_id' => $method_id,
                    'payment_method_label' => $payment_method_label
                ]);

                return response()->json(['status' => true, 'amounts' => $amounts], 200);
            }
        }

        return response()->json(['status' => false, 'message' => 'Invalid request!', 'amounts' => []], 500);
    }

    public function paymentIntent(Request $request, StripeModel $stripe_model, DtPayModel $dt_pay_model){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){
            
                try{
                    
                    list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                    $stripe = new StripeClient($api_secret_key);

                    $final_amount = $dt_pay_model->transactionTotal($transaction);

                    $intent = $stripe->paymentIntents->create([
                        'amount' => (int) round($final_amount * 100),
                        'currency' => 'usd',

                        'automatic_payment_methods' => [
                            'enabled' => true
                        ],
                    ]);

                    return response()->json(['status' => true, 'client_secret' => $intent->client_secret], 200);

                }catch(\Exception $e){

                    return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
                }
            }
        }

        return response()->json(['status' => false, 'message' => "There was an error while processing your request."], 400);
    }

    public function makePayment(Request $request, DtPayModel $dt_pay_model, StripeModel $stripe_model){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){

                try{
                
                    list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                    $stripe = new StripeClient($api_secret_key);

                    $final_amount = round(((float) $transaction->amount + (float) $transaction->card_fee + (float) $transaction->fee), 2, PHP_ROUND_HALF_UP);

                    $final_amount = (int) round($final_amount * 100);

                    $intent = $stripe->paymentIntents->create([
                        'amount' => $final_amount,
                        'currency' => 'usd',
                        'customer' => $user->stripe_customer_id,
                        'payment_method' => $transaction->payment_method_id,
                        'confirm' => true,

                        'automatic_payment_methods' => [
                            'enabled' => true,
                            'allow_redirects' => 'never',
                        ],
                    ]);

                    $message = "Payment successful.";

                    if($intent->status === 'succeeded'){

                        $message = "Payment successful.";
                    }

                    if($intent->status === 'processing'){

                        $message = "Your bank transfer has been initiated. We'll notify you once it completes.";
                    }

                    if($intent->status === 'requires_action'){

                        $message = "Payment is under processing.";
                    }

                    $transaction->update([
                        'payment_id' => $intent->id,
                        'payment_date' => now(),
                    ]);
                
                    return response()->json(['status' => true, 'payment_intent' => $intent, 'message' => $message]);

                }catch(\Exception $e){

                    return response()->json(['status' => false, 'message' => $e->getMessage()],400);
                }
            }
        }

        return response()->json(['status' => false, 'message' => "There was an error while processing your request."],400);
    }

    /*
    Manual transactions
    */

    public function initManualTransaction(Request $request, DtPayPaymentLoadsModel $dt_pay_payment_loads_model){

        return response()->json(['status' => true, 'conditions' => $dt_pay_payment_loads_model->releas_conditions()], 200);
    }

    public function submitManualTransaction(Request $request, DtPayModel $dt_pay_model, DtPayPaymentLoadsModel $dt_pay_payment_loads_model, DtPayLogsModel $dt_pay_logs_model){

        $user = $request->user();

        if($user){

            $validator = Validator::make($request->all(), [
                'load_ref' => 'required|string|max:100',
                'carrier_invoice' => 'nullable|string|max:255',
                'origin' => 'required|string|max:255',
                'destination' => 'required|string|max:255',
                'pickup_date' => 'required|date',
                'delivery_date' => 'required|date|after_or_equal:pickup_date',
                'equipment' => 'required|string|max:255',
                'weight' => 'required|numeric|min:0.01',
                'linehaul_rate' => 'required|numeric|min:0.01',
                'total_to_carrier' => 'required|numeric|min:0.01',
                'rate_confirmation' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
                'pod' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            ]);

            if($validator->fails()){

                return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
            }

            $data = $validator->validated();

            $rate_confirmation_path = $request->hasFile('rate_confirmation') ? $this->storeManualLoadDocument($request->file('rate_confirmation')) : '';
            $pod_path = $request->hasFile('pod') ? $this->storeManualLoadDocument($request->file('pod')) : '';

            $row_id = (string) Str::ulid();
            $payment_ref = $dt_pay_model->create_payment_number('MANUAL');

            $amount = $data['total_to_carrier'];

            $platform_fee = $dt_pay_model->calculatePercentage($amount, $dt_pay_model->platform_fees());
            
            DB::beginTransaction();

            try {
            
                /*
                Persist the manually entered load
                */
                $load = DtPayPaymentLoadsModel::create([
                    'transaction_id' => $row_id,
                    'load_ref' => $data['load_ref'],
                    'carrier_invoice' => $data['carrier_invoice'] ?? '',
                    'origin' => $data['origin'],
                    'destination' => $data['destination'],
                    'pickup_date' => $data['pickup_date'],
                    'delivery_date' => $data['delivery_date'],
                    'equipment' => $data['equipment'],
                    'commodity' => '',
                    'weight' => $data['weight'],
                    'linehaul_rate' => $data['linehaul_rate'],
                    'accessorials' => '',
                    'total_to_carrier' => $data['total_to_carrier'],
                    'rate_confirmation' => $rate_confirmation_path,
                    'pod' => $pod_path,
                    'added_by' => $user->row_id,
                    'added_by_type' => 'broker',
                ]);

                DtPayModel::create([
                    'row_id' => $row_id,
                    'broker_id' => $user->row_id,
                    'carrier_id' => '',
                    'source' => DtPayModel::SOURCE_MANUAL,
                    'payment_ref' => $payment_ref,
                    'amount' => $amount,
                    'fee' => $platform_fee,
                    'payment_date' => date('Y-m-d H:i:s'),
                    'status' => DtPayModel::STATUS_INIT,
                    'notes' => $request->post('release_condition', null)
                ]);

                /*
                Generate logs
                */
                DtPayLogsModel::create([
                    'payment_id' => $row_id,
                    'transaction_label' => "Payment created (manual)",
                    'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                    'added_by' => $user->row_id,
                    'added_by_type' => 'broker',
                    'transaction_date' => now(),
                ]);

                DB::commit();

                $dt_pay_model->update_payment_number();

                return response()->json(['status' => true, 'row_id' => $row_id], 200);

            }catch(\Exception $e){
	
                Log::error('DTPay Transaction Error: ' . $e->getMessage());
	            DB::rollback();
            }   
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function manualCarrierSearch(Request $request, CarriersModel $carriers_model){

        $user = $request->user();

        if($user){

            $dot_number = trim((string) $request->post('dot_number'));

            if($dot_number === ''){

                return response()->json(['status' => false, 'message' => 'Please enter a DOT number to search.'], 200);
            }

            $carriers = $carriers_model->where('dot_number', 'like', '%' . $dot_number . '%')->limit(10)->get();

            $results = [];

            foreach($carriers as $carrier){

                $results[] = $carriers_model->format($carrier);
            }

            return response()->json(['status' => true, 'carriers' => $results], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function manualCarrierUpdate(Request $request){

        $transaction_id = $request->post('transaction_id');
        $carrier_id = $request->post('carrier');

        if($transaction_id && $carrier_id){

            DtPayModel::where('row_id', $transaction_id)->update(['carrier_id' => $carrier_id]);
        }
        
        return response()->json(['status' => true, 'message' => 'Carrier updated successfully.'], 200);
    }

    public function initManualFunding(Request $request, DtPayModel $dt_pay_model, DtPayPaymentLoadsModel $dt_pay_payment_loads_model, CarriersModel $carriers_model){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model
                                ->select(
                                    "dt_payments.*",
                                    "dt_payments_loads.load_ref",
                                    "dt_payments_loads.carrier_invoice",
                                    "dt_payments_loads.origin",
                                    "dt_payments_loads.destination",
                                    "dt_payments_loads.pickup_date",
                                    "dt_payments_loads.delivery_date",
                                    "dt_payments_loads.equipment",
                                    "dt_payments_loads.commodity",
                                    "dt_payments_loads.weight",
                                    "dt_payments_loads.linehaul_rate",
                                    "dt_payments_loads.accessorials",
                                    "dt_payments_loads.total_to_carrier",
                                    "dt_payments_loads.rate_confirmation",
                                    "dt_payments_loads.pod",
                                    "carriers.legal_name",
                                    "carriers.dba_name",
                                    "carriers.dot_number",
                                    "carriers.email_address as carrier_email_address"
                                )
                                ->where('dt_payments.row_id', $transaction_id)
                                ->join($dt_pay_payment_loads_model->getTable(), "dt_payments.row_id", "=", "dt_payments_loads.transaction_id")
                                ->join($carriers_model->getTable(), "dt_payments.carrier_id", "=", "carriers.row_id")
                                ->first();

                if($transaction){

                    $transaction->amount_formatted = Number::currency($transaction->total_to_carrier);

                    /*
                    Stripe sources
                    */
                    $stripe_sources = $dt_pay_model->stripePaymentSources($user);

                    $amount = $transaction->amount;

                    $amounts = $dt_pay_model->calculations($amount);

                    return response()->json(['status' => true, 'transaction' => $transaction, 'sources' => $stripe_sources, 'amounts' => $amounts], 200);
                }

                return response()->json(['status' => false, 'message' => 'Transaction not found.'], 200);
            }

            return response()->json(['status' => false, 'message' => 'Transaction ID is required.'], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    private function storeManualLoadDocument($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/manual-loads/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }
}
