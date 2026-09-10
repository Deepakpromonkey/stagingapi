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

    public function loadTransaction(Request $request, DtPayGuestPayModel $dt_pay_guest_pay_model, CarriersModel $carriers_model){

        $transaction_id = $request->post('transaction_id');

        if($transaction_id){

            $transaction = $dt_pay_guest_pay_model->find($transaction_id);

            $transaction = $dt_pay_guest_pay_model
                            ->select("dt_guest_pay.*", "carriers.legal_name")
                            ->where('dt_guest_pay.row_id', $transaction_id)
                            ->join($carriers_model->getTable(), "dt_guest_pay.carrier_id", "=", "carriers.row_id")
                            ->first();

            if($transaction){

                return response()->json(['status' => true, 'transaction' => $transaction], 200);
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

    private function storeGuestPayDocument($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/guest-pay/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }
}
