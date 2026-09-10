<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayLogsModel;

use App\Models\CarrierConnectRequest;

use Illuminate\Support\Facades\Log;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

class DtPayTransactionsController extends Controller
{

    public function listTransactions(Request $request, DtPayModel $dt_pay_model){

        $user = $request->user();

        if($user){

            $status_labels = array_column($dt_pay_model->status_options(), 'value', 'key');

            $status = $request->post('status', 'all');

            $transactions = $dt_pay_model
                                ->where('broker_id', $user->uuid)
                                ->when(!empty($status) && $status !== 'all', function ($query) use ($status) {
                                    $query->where('stage', $status);
                                })
                                ->with(['carrier:id,row_id,legal_name,dot_number', 'payment_load', 'shipment:id,uuid,shipment_no'])
                                ->orderBy('payment_date', 'desc')
                                ->get();

            $results = [];

            foreach($transactions as $transaction){

                $transaction = $dt_pay_model->format($transaction);

                $transaction->amount_formatted = Number::currency($transaction->amount);
                $transaction->fee_formatted = Number::currency($transaction->fee);

                $transaction->status_label = $status_labels[$transaction->status] ?? 'NA';

                $transaction->payment_date_formatted = date("M d", strtotime($transaction->payment_date));

                if($transaction->source == DtPayModel::SOURCE_AUTO){

                    $transaction->load_ref = $transaction->shipment_number;
                }

                $results[] = $transaction;
            }

            return response()->json(['status' => true, 'transactions' => $results, 'statuses' => $dt_pay_model->stage_options()], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function loadTransaction(Request $request, DtPayModel $dt_pay_model, DtPayLogsModel $dt_pay_logs_model){

        $user = $request->user();

        if($user){

            $transaction_id = $request->post('transaction_id');

            if($transaction_id){

                $transaction = $this->fetchTransaction($transaction_id);

                if($transaction){

                    $amounts = $dt_pay_model->calculations($transaction->amount, $transaction->payment_method);

                    $progress = $dt_pay_logs_model->transactionProgress($transaction, 'broker');

                    return response()->json(['status' => true, 'transaction' => $transaction, 'amounts' => $amounts, 'progress' => $progress], 200);
                }
            }
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function refundPayment(Request $request, DtPayModel $dt_pay_model, StripeModel $stripe_model){

        $user = $request->user();

        if($user){
        
            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){

                if($transaction->payment_id != ''){

                    try{
                    
                        list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                        $stripe = new StripeClient($api_secret_key);

                        $refund = $stripe->refunds->create([
                            'payment_intent' => $transaction->payment_id,
                        ]);

                        DtPayLogsModel::create([
                            'payment_id' => $transaction->uuid,
                            'transaction_label' => "Payment refunded (" . $transaction->payment_ref . ")",
                            'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                            'added_by' => $user->uuid,
                            'added_by_type' => 'broker',
                            'transaction_date' => now(),
                            'sequence' => 8,
                            'stripe_transaction_id' => $refund->id,
                            'stripe_payment_status' => $refund->status,
                            'stripe_response' => json_encode($refund)
                        ]);

                        $transaction->update([
                            'status' => DtPayModel::STATUS_REFUND,
                            'stage' => DtPayModel::STAGE_REFUNDED
                        ]);

                        $_transaction = $this->fetchTransaction($transaction_id);

                        return response()->json(['status' => true, 'transaction' => $_transaction, 'message' => 'Funds refunded successfully.'], 200);
                    
                    }catch(\Exception $e){

                        Log::error('DTPay Transaction Refund Error: ' . $e->getMessage());

                        return response()->json(['success' => false, 'message' => "There was an error while processing your request."], 400);
                    }
                }
            }
        }

        return response()->json(['success' => false, 'message' => "There was an error while processing your request.."], 400);
    }

    public function holdPayment(Request $request, DtPayModel $dt_pay_model, StripeModel $stripe_model){

        $user = $request->user();

        if($user){
        
            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){

                DtPayLogsModel::create([
                    'payment_id' => $transaction->uuid,
                    'transaction_label' => "Payment hold (manual hold)",
                    'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                    'added_by' => $user->uuid,
                    'added_by_type' => 'broker',
                    'transaction_date' => now(),
                    'sequence' => 7
                ]);

                $transaction->update([
                    'status' => DtPayModel::STATUS_HOLD,
                    'stage' => DtPayModel::STAGE_IN_HOLD
                ]);

                $_transaction = $this->fetchTransaction($transaction_id);

                return response()->json(['status' => true, 'transaction' => $_transaction], 200);
            }
        }

        return response()->json(['success' => false, 'message' => "There was an error while processing your request.."], 400);
    }

    public function releasePayment(Request $request, DtPayModel $dt_pay_model, StripeModel $stripe_model, CarrierConnectRequest $carrier_connect_requests_model){
        
        $user = $request->user();

        if($user){
        
            $transaction_id = $request->post('transaction_id');

            $transaction = $dt_pay_model->find($transaction_id);

            if($transaction){

                $connect_request = $carrier_connect_requests_model->where('carrier_dot_number', $transaction->carrier_id)->first();

                if($connect_request){

                    if($connect_request->stripe_express_account != ''){

                        try {
                        
                            list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                            $stripe = new StripeClient($api_secret_key);

                            $total = $dt_pay_model->transactionTotal($transaction);

                            $transfer = $stripe->transfers->create([
                                'amount' => (int) round($total * 100),
                                'currency' => 'usd',
                                'destination' => $connect_request->stripe_express_account,
                            ]);

                            $transaction->update([
                                'transfer_id' => $transfer->id,
                                'transferred_amount' => $total,
                                'transfer_date' => now(),
                                'transferred_by' => $user->uuid,
                                'stage' => DtPayModel::STAGE_RELEASED,
                                'status' => DtPayModel::STATUS_PAID
                            ]);

                            DtPayLogsModel::create([
                                'payment_id' => $transaction->uuid,
                                'transaction_label' => "Payment released (broker)",
                                'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                                'added_by' => $user->uuid,
                                'added_by_type' => 'broker',
                                'transaction_date' => now()
                            ]);

                            $_transaction = $this->fetchTransaction($transaction_id);

                            return response()->json(['status' => true, 'transaction' => $_transaction, 'message' => 'Funds transferred successfully.'], 200);

                        }catch(\Exception $e){

                            Log::error('DTPay payemnt releaase error: ' . $e->getMessage());

                            return response()->json(['status' => false, 'message' => "There was an error while processing your request."], 200);
                        }
                    }else{

                        return response()->json(['status' => false, 'message' => "Stripe express account not connected."], 200);
                    }
                }else{

                    return response()->json(['status' => false, 'message' => "This carrier is not connected with you."], 200);
                }
            }
        }

        return response()->json(['status' => false, 'message' => "There was an error while processing your request.."], 200);
    }

    public function fetchTransaction($transaction_id){

        $dt_pay_model = new DtPayModel;
        
        $status_labels = array_column($dt_pay_model->status_options(), 'value', 'key');

        $transaction = $dt_pay_model
                                ->where('uuid', $transaction_id)
                                ->with(['carrier:id,row_id,legal_name,dot_number', 'payment_load', 'shipment:id,uuid,shipment_no', 'payment_logs' => function ($query) {
                                    $query->orderBy('sequence', 'asc');
                                }])
                                ->orderBy('payment_date', 'desc')
                                ->first();

        if($transaction){

            $transaction->amount_formatted = Number::currency($transaction->amount);
            $transaction->fee_formatted = Number::currency($transaction->fee);

            $transaction->status_label = $status_labels[$transaction->status] ?? 'NA';

            $transaction->payment_date_formatted = date("M d", strtotime($transaction->payment_date));

            if($transaction->source == DtPayModel::SOURCE_AUTO){

                $transaction->load_ref = $transaction->shipment_number;
            }

            $state_options = ['init' => 'FUNDED_HELD', 'review' => 'FUNDED_HELD', 'paid' => 'RELEASED', 'hold' => 'MANUAL_HOLD', 'refund' => 'REFUNDED', 'cancelled' => 'CANCELLED'];

            $transaction->state = $state_options[$transaction->status] ?? 'FUNDED_HELD';

            $transaction->has_files = false;

            if($transaction->rate_confirmation || $transaction->pod){
            
                $transaction->has_files = true;

                $transaction->rate_confirmation_url = $transaction->rate_confirmation ? URL::to(Storage::url($transaction->rate_confirmation)) : null;
                $transaction->pod_url = $transaction->pod ? URL::to(Storage::url($transaction->pod)) : null;
            }

            return $transaction;
        }

        return false;
    }
}
