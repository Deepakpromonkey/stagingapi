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
use App\Models\DtPay\DTPayBrokerStats;

use App\Models\Shipment;

use App\Models\Carriers\Carrier;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

use App\Services\ShipmentService;

use Illuminate\Support\Number;
use Illuminate\Support\Str;

class DtPayController extends Controller
{

    public function __construct(
        protected ShipmentService $shipmentService
    ) {}
    
    public function fetchLoads(Request $request){

        $user = $request->user();

        $shipments = [];

        if($user){

            $shipments = $this->shipmentService->getAllForUser($user);

            return response()->json(['status' => true, 'loads' => $shipments], 200);
        }

        return response()->json(['error' => 'Unauthorized access'], 200);
    }

    public function initFunding(
        Request $request,
        DtPayModel $dt_pay_model
    ){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model->find($transaction_id);

                if($transaction){

                    $load = Shipment::where('company_id', $user->company_id)->where('uuid', $transaction->load_id)
                                ->with('stops')->first(); 
                    
                    if($load){

                        $load->amount_formatted = Number::currency($transaction->amount);

                        /*
                        Stripe
                        */
                        $stripe_sources = $dt_pay_model->stripePaymentSources($user);

                        $amount = $transaction->amount;

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
        DtPayModel $dt_pay_model
    ){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model->find($transaction_id);

                if($transaction){

                    $load = Shipment::where('company_id', $user->company_id)->where('uuid', $transaction->load_id)
                                ->with('stops')->first(); 
                    
                    if($load){

                        $load->amount_formatted = Number::currency($transaction->amount);

                        $amounts = $dt_pay_model->calculations($transaction->amount, $transaction->payment_method);

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
        DtPayLogsModel $dt_pay_logs_model
    ){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model->with([
                    'carrier', 
                    'payment_load', 
                    'payment_logs' => function ($query) {
                        $query->orderBy('sequence', 'asc');
                    }
                ])->find($transaction_id);

                if($transaction){

                    $transaction_amount_formatted = Number::currency($transaction->payment_load->total_to_carrier);

                    $amount = $transaction->amount;

                    $amounts = $dt_pay_model->calculations($amount, $transaction->payment_method);

                    $progress = [];

                    $final_amount = round(((float) $transaction->amount + (float) $transaction->card_fee + (float) $transaction->fee), 2, PHP_ROUND_HALF_UP);

                    $progress['label'] = Number::currency($final_amount) . " debited via " . ($transaction->payment_method == 'card' ? 'Card' : 'ACH');

                    $progress['steps'] = $dt_pay_logs_model->transactionProgress($transaction, 'broker');

                    return response()->json(['status' => true, 'transaction' => $transaction, 'amounts' => $amounts, 'progress' => $progress], 200);
                }
            }
        }

        return response()->json(['status' => false, 'message' => 'There was an error while processing your request.', 'load' => null, 'sources' => []], 200);
    }

    public function initTransaction(Request $request, DtPayModel $dt_pay_model, DTPayBrokerStats $dt_pay_broker_stats){

        $user = $request->user();

        if($user){

            $load_id = $request->post('load_id');
            $transaction_id = $request->post('transaction_id');

            if($load_id){

                /*
                Fetch load
                */
                $load = DB::table('shipments')
                                ->where('uuid', $load_id)
                                ->where('company_id', auth()->user()->company_id)
                                ->first();

                if($load){

                    DB::beginTransaction();

                    try {
                    
                        $payment_ref = $dt_pay_model->create_payment_number('AUTO');

                        $amount = 3000; // $load->amount;
                        $platform_fee = $dt_pay_model->calculatePercentage($amount, $dt_pay_model->platform_fees());

                        $update = false;

                        if($transaction_id){

                            /*
                            Check if transaction exists
                            */
                            $transaction = $dt_pay_model->find($transaction_id);

                            if($transaction){

                                $update = true;
                            }
                        }

                        if($update){

                            $transaction->update([
                                'broker_id' => $user->uuid,
                                'carrier_id' => $load->carrier_dot,
                                'load_id' => $load->uuid,
                                'source' => DtPayModel::SOURCE_AUTO,
                                'payment_ref' => $payment_ref,
                                'amount' => $amount,
                                'fee' => $platform_fee,
                                'payment_date' => date('Y-m-d H:i:s'),
                                'status' => DtPayModel::STATUS_INIT,
                            ]);
                        }else{

                            $transaction = DtPayModel::create([
                                'broker_id' => $user->uuid,
                                'carrier_id' => $load->carrier_dot,
                                'load_id' => $load->uuid,
                                'source' => DtPayModel::SOURCE_AUTO,
                                'payment_ref' => $payment_ref,
                                'amount' => $amount,
                                'fee' => $platform_fee,
                                'payment_date' => date('Y-m-d H:i:s'),
                                'status' => DtPayModel::STATUS_INIT,
                            ]);
                        }

                        $row_id = $transaction->uuid;

                        /*
                        Add stats
                        */
                        $dt_pay_broker_stats->addStat($user->uuid, 'hold', $amount);

                        /*
                        Generate logs
                        */
                        DtPayLogsModel::create([
                            'payment_id' => $row_id,
                            'transaction_label' => "Payment created (auto)",
                            'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                            'added_by' => $user->uuid,
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

                        return response()->json(['status' => false, 'message' => $e->getMessage()], 200);
                    }
                }
            }
        }

        return response()->json(['status' => false, 'message' => 'Load not found!'], 200);
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

                        'metadata' => [
                            'transaction_id' => $transaction->uuid,
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
                        'payment_status' => 'hold',

                        'stripe_payment_date' => now(),
                        'stripe_transaction_id' => $intent->id,
                        
                        'stripe_status' => $intent->status,
                        'stripe_response' => json_encode($intent)
                    ]);

                    $payment_method_label = 'ACH';

                    $paymentMethod = $stripe->paymentMethods->retrieve($transaction->payment_method_id);

                    if($paymentMethod){

                        if($transaction->payment_method == 'card'){

                            $payment_method_label = strtoupper($paymentMethod->card->display_brand) . "...-" . $paymentMethod->card->last4;
                        }else{

                            $payment_method_label = $paymentMethod->us_bank_account->bank_name . "...-" . $paymentMethod->us_bank_account->last4;
                        }
                    }
                    
                    /*
                    Generate logs
                    */
                    DtPayLogsModel::create([
                        'payment_id' => $transaction->uuid,
                        'transaction_label' => "ACH debit initiated",
                        'sub_label' => $payment_method_label . " · clears in 1-2 business days",
                        'added_by' => $user->uuid,
                        'added_by_type' => 'broker',
                        'transaction_date' => now(),
                        'sequence' => 2,
                        'stripe_transaction_id' => $intent->id,
                        'stripe_payment_status' => $intent->status,
                        'stripe_response' => json_encode($intent)
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

    public function initManualTransaction(Request $request, DtPayPaymentLoadsModel $dt_pay_payment_loads_model, DtPayModel $dt_pay_model){

        $transaction_id = $request->post('transaction_id');

        $transaction = null;

        if($transaction_id){

            $transaction = $dt_pay_model->with('payment_load')->find($transaction_id);
        }

        return response()->json(['status' => true, 'conditions' => $dt_pay_payment_loads_model->releas_conditions(), 'transaction' => $transaction], 200);
    }

    public function submitManualTransaction(Request $request, DtPayModel $dt_pay_model, DTPayBrokerStats $dt_pay_broker_stats){

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

            $payment_ref = $dt_pay_model->create_payment_number('MANUAL');

            $amount = $data['total_to_carrier'];

            $platform_fee = $dt_pay_model->calculatePercentage($amount, $dt_pay_model->platform_fees());
            
            DB::beginTransaction();

            try {

                $update = false;

                $transaction_id = $request->post('transaction_id');

                if($transaction_id){

                    $transaction = $dt_pay_model->find($transaction_id);

                    if($transaction){

                        $update = true;
                    }
                }

                if($update){

                    $transaction->update([
                        'broker_id' => $user->uuid,
                        'source' => DtPayModel::SOURCE_MANUAL,
                        'payment_ref' => $payment_ref,
                        'amount' => $amount,
                        'fee' => $platform_fee,
                        'payment_date' => date('Y-m-d H:i:s'),
                        'status' => DtPayModel::STATUS_INIT,
                        'notes' => $request->post('release_condition', null)
                    ]);

                    $row_id = $transaction->uuid;

                    $load = DtPayPaymentLoadsModel::where('transaction_id', $row_id)->update([
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
                        'added_by' => $user->uuid,
                        'added_by_type' => 'broker',
                    ]);

                }else{

                    $transaction = DtPayModel::create([
                        'broker_id' => $user->uuid,
                        'carrier_id' => '',
                        'source' => DtPayModel::SOURCE_MANUAL,
                        'payment_ref' => $payment_ref,
                        'amount' => $amount,
                        'fee' => $platform_fee,
                        'payment_date' => date('Y-m-d H:i:s'),
                        'stage' => DtPayModel::STAGE_IN_HOLD,
                        'status' => DtPayModel::STATUS_INIT,
                        'notes' => $request->post('release_condition', null)
                    ]);

                    $row_id = $transaction->uuid;

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
                        'added_by' => $user->uuid,
                        'added_by_type' => 'broker',
                    ]);
                }

                /*
                Add stats
                */
                $dt_pay_broker_stats->addStat($user->uuid, 'hold', $amount);

                /*
                Generate logs
                */
                DtPayLogsModel::create([
                    'payment_id' => $row_id,
                    'transaction_label' => "Payment created (manual)",
                    'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                    'added_by' => $user->uuid,
                    'added_by_type' => 'broker',
                    'load' => $data['load_ref'],
                    'sequence' => 1,
                    'transaction_date' => now(),
                ]);

                DB::commit();

                $dt_pay_model->update_payment_number();

                return response()->json(['status' => true, 'row_id' => $row_id], 200);

            }catch(\Exception $e){
	
                Log::error('DTPay Transaction Error: ' . $e->getMessage());
	            DB::rollback();

                return response()->json(['status' => false, 'message' => "There was an error while processing your request."], 200);
            }   
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 200);
    }

    public function manualCarrierSearch(Request $request, Carrier $carriers_model){

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

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 200);
    }

    public function manualCarrierUpdate(Request $request, Carrier $carriers_model){

        $transaction_id = $request->post('transaction_id');
        $carrier_id = $request->post('carrier');

        if($transaction_id && $carrier_id){

            /*
            Load carrier
            */
            $carrier = $carriers_model->where('dot_number', $carrier_id)->first();

            if($carrier){

                try{
                
                    DB::beginTransaction();
                    
                        DtPayModel::where('uuid', $transaction_id)->update(['carrier_id' => $carrier_id, 'carrier_name' => $carrier->legal_name]);

                        /*
                        Update logs
                        */
                        DtPayLogsModel::where('payment_id', $transaction_id)->where('sequence', 1)->update([
                            'carrier_dot_number' => $carrier_id,
                            'carrier_name' => $carrier->legal_name,
                        ]);
                    DB::commit();

                }catch(\Exception $e){
	
                    Log::error('DTPay Carrier Update Error - Manual payment: ' . $e->getMessage());
                    DB::rollback();

                    return response()->json(['status' => false, 'message' => "There was an error while processing your request."], 200);
                }   

                return response()->json(['status' => true, 'message' => 'Carrier updated successfully.'], 200);
            }else{

                return response()->json(['status' => false, 'message' => "Carrier not found for the DOT Number: " . $carrier_id], 200);
            }
        }
        
        return response()->json(['status' => false, 'message' => 'Invalid inputs.'], 200);
    }

    public function initManualFunding(Request $request, DtPayModel $dt_pay_model, DtPayPaymentLoadsModel $dt_pay_payment_loads_model){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $dt_pay_model::where('uuid', $transaction_id)->with(['carrier', 'payment_load'])->first(); 

                if($transaction){

                    $transaction->amount_formatted = Number::currency($transaction->payment_load->total_to_carrier);

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

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 200);
    }

    private function storeManualLoadDocument($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/manual-loads/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }

    public function brokerStats(Request $request, DTPayBrokerStats $dt_pay_broker_stats){

        $user = $request->user();

        if($user){
        
            $stats = $dt_pay_broker_stats->fetchBrokerStats($user->uuid);

            return response()->json(['status' => true, 'data' => $stats], 200);
        }

        $stats = [
            'hold_sum' => '$0',
            'hold_count' => 0,
            'ready_to_release_sum' => '$0',
            'ready_to_release_count' => 0,
            'released_sum' => '$0',
            'released_count' => 0,
            'disputes_count' => 0,
        ];

        return response()->json(['status' => true, 'data' => $stats], 200);
    }
}
