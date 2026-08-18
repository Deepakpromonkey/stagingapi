<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

use App\Models\DtPay\DtPayGuestPayModel;
use App\Models\DtPay\DtPayLogsModel;

use App\Models\Carriers\CarriersModel;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

use Illuminate\Support\Number;
use Illuminate\Support\Str;

class GuestPayController extends Controller
{
    
    public function initGuestPay(Request $request, DtPayGuestPayModel $dt_pay_guest_pay_model){

        $carrier_id = $request->post('carrier_id');

        if($carrier_id){

            try {
                
                $payment_ref = $dt_pay_guest_pay_model->create_payment_number('AUTO');

                $entry = DtPayGuestPayModel::create([
                    'carrier_id' => $carrier_id,
                    'payment_ref' => $payment_ref,
                    'payment_date' => now(),
                    'status' => DtPayGuestPayModel::STATUS_INIT,
                ]);

                $row_id = $entry->uuid;

                /*
                Generate logs
                */
                DtPayLogsModel::create([
                    'guest_payment_id' => $row_id,
                    'transaction_label' => "Payment created (guest pay)",
                    'added_by_type' => 'guest',
                    'transaction_date' => now()
                ]);

                DB::commit();

                $dt_pay_guest_pay_model->update_payment_number();

                return response()->json(['status' => true, 'row_id' => $row_id], 200);

            }catch(\Exception $e){

                Log::error('DTPay Transaction Error: ' . $e->getMessage());
                DB::rollback();
            }
        }

        return response()->json(['status' => false, 'message' => "There was an error while processing your request."], 400);
    }

    public function loadTransaction(Request $request, DtPayGuestPayModel $dt_pay_guest_pay_model){

        $transaction_id = $request->post('transaction_id');

        if($transaction_id){

            try{
            
                $transaction = $dt_pay_guest_pay_model->with('carrier')->find($transaction_id);

                if($transaction){

                    return response()->json(['status' => true, 'transaction' => $transaction], 200);
                }

                return response()->json(['status' => false, 'message' => 'There was an error while processing your request.'], 200);
                
            }catch(\Exception $e){

                Log::error('DTPay Guest pay error: ' . $e->getMessage());

                return response()->json(['status' => false, 'message' => 'There was an error while processing your request.'], 200);
            }
        }

        return response()->json(['status' => false], 200);
    }

    public function manualCarrierSearch(Request $request, CarriersModel $carriers_model){

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

    public function loadSubmission(Request $request, DtPayGuestPayModel $dt_pay_guest_pay_model){

        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'load_ref' => 'required|string|max:100',
            'carrier_invoice' => 'nullable|string|max:255',
            'origin' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'delivery_date' => 'required|date',
            'equipment' => 'required|string|max:255',
            'amount_to_carrier' => 'required|numeric|min:0.01',
            'rate_confirmation' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if($validator->fails()){

            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        $rate_confirmation_path = $request->hasFile('rate_confirmation') ? $this->storeGuestPayDocument($request->file('rate_confirmation')) : '';

        $transaction = $dt_pay_guest_pay_model->find($data['transaction_id']);

        if($transaction){

            try{
            
                /*
                Update transaction
                */
                $transaction->update([
                    'amount' => $data['amount_to_carrier'],
                    'load_id' => $data['load_ref'],
                    'carrier_invoice' => $data['carrier_invoice'],
                    'origin' => $data['origin'],
                    'destination' => $data['destination'],
                    'delivery_date' => $data['delivery_date'],
                    'rate_confirmation' => $rate_confirmation_path
                ]);

                return response()->json(['status' => true, 'message' => "Transaction updated successfully."], 200);

            }catch(\Exception $e){

                return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
            }
        }

        return response()->json(['status' => false, 'message' => 'There was an error while processing your request.'], 400);
    }

    public function handlePersonalSubmit(Request $request, DtPayGuestPayModel $dt_pay_guest_pay_model){

        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'legal_name' => 'required|string|max:255',
            'role' => 'required|string|max:100',
            'broker_mc' => 'nullable|string|max:100',
            'ein' => 'nullable|string|max:50',
            'contact_name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'business_address' => 'required|string',
        ]);

        if($validator->fails()){

            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        $transaction = $dt_pay_guest_pay_model->find($data['transaction_id']);

        if($transaction){

            try{
            
                /*
                Update transaction
                */
                $transaction->update([
                    'customer_legal_name' => $data['legal_name'],
                    'role' => $data['role'],
                    'broker_mc' => $data['broker_mc'],
                    'ein' => $data['ein'],
                    'contact_name' => $data['contact_name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'business_address' => $data['business_address']
                ]);

                return response()->json(['status' => true, 'message' => "Transaction updated successfully."], 200);

            }catch(\Exception $e){

                return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
            }
        }

        return response()->json(['status' => false, 'message' => 'There was an error while processing your request.'], 400);
    }

    public function paymentIntent(Request $request, StripeModel $stripe_model, DtPayGuestPayModel $dt_pay_guest_pay_model){

        $transaction_id = $request->post('transaction_id');

        $transaction = $dt_pay_guest_pay_model->find($transaction_id);

        if($transaction && $transaction->amount > 0){

            try{

                list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                $stripe = new StripeClient($api_secret_key);

                $intent = null;

                /*
                Reuse the existing PaymentIntent if one is already open for this
                transaction, so a re-fired request (retry, StrictMode double-effect,
                page refresh) can't orphan the intent the card form is bound to.
                */
                if($transaction->payment_id){

                    try{

                        $existing = $stripe->paymentIntents->retrieve($transaction->payment_id);

                        if(in_array($existing->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)){

                            $intent = $existing;
                        }
                    }catch(\Exception $e){

                        $intent = null;
                    }
                }

                if(!$intent){

                    $intent = $stripe->paymentIntents->create([
                        'amount' => (int) round($transaction->amount * 100),
                        'currency' => 'usd',

                        'payment_method_types' => ['card'],

                        'metadata' => [
                            'guest_transaction_id' => $transaction->uuid,
                        ],
                    ]);

                    $transaction->update(['payment_id' => $intent->id]);
                }

                return response()->json(['status' => true, 'client_secret' => $intent->client_secret], 200);

            }catch(\Exception $e){

                Log::error('DTPay Guest pay payment intent error: ' . $e->getMessage());

                return response()->json(['status' => false, 'message' => 'There was an error while setting up payment.'], 400);
            }
        }

        return response()->json(['status' => false, 'message' => 'There was an error while processing your request.'], 400);
    }

    public function confirmPayment(Request $request, StripeModel $stripe_model, DtPayGuestPayModel $dt_pay_guest_pay_model){

        $transaction_id = $request->post('transaction_id');
        $payment_intent_id = $request->post('payment_intent_id');

        $transaction = $dt_pay_guest_pay_model->find($transaction_id);

        if($transaction && $payment_intent_id && $transaction->payment_id === $payment_intent_id){

            try{

                list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

                $stripe = new StripeClient($api_secret_key);

                $intent = $stripe->paymentIntents->retrieve($payment_intent_id);

                if($intent->status === 'succeeded'){

                    $payment_method_label = 'Card';

                    if($intent->latest_charge){

                        $charge = $stripe->charges->retrieve($intent->latest_charge);

                        if($charge && $charge->payment_method_details && $charge->payment_method_details->card){

                            $card = $charge->payment_method_details->card;

                            $payment_method_label = strtoupper($card->brand) . ' ····' . $card->last4;
                        }
                    }

                    $transaction->update([
                        'payment_method' => 'card',
                        'payment_method_label' => $payment_method_label,
                        'payment_id' => $intent->id,
                        'payment_date' => now(),
                        'status' => DtPayGuestPayModel::STATUS_HOLD,
                    ]);

                    DtPayLogsModel::create([
                        'guest_payment_id' => $transaction->uuid,
                        'transaction_label' => "Card payment captured (guest pay)",
                        'added_by_type' => 'guest',
                        'transaction_date' => now(),
                        'stripe_transaction_id' => $intent->id,
                        'stripe_payment_status' => $intent->status,
                    ]);

                    return response()->json(['status' => true, 'message' => 'Payment successful. Funds are held until delivery is confirmed.'], 200);
                }

                return response()->json(['status' => false, 'message' => 'Payment was not completed.'], 200);

            }catch(\Exception $e){

                Log::error('DTPay Guest pay confirm payment error: ' . $e->getMessage());

                return response()->json(['status' => false, 'message' => 'There was an error while confirming your payment.'], 400);
            }
        }

        return response()->json(['status' => false, 'message' => 'There was an error while processing your request.'], 400);
    }

    private function storeGuestPayDocument($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/guest-pay/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }
}
