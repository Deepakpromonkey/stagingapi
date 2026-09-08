<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayPaymentLoadsModel;
use App\Models\DtPay\DtPayDisputesModel;

use App\Models\Carriers\CarriersModel;

use App\Models\Customers\CustomersModel;

use App\Models\Shipments\ShipmentsModel;

class DtPayAppealController extends Controller
{

    public function init(Request $request, DtPayModel $dt_pay_model, CarriersModel $carriers_model, CustomersModel $customers_model, ShipmentsModel $shipments_model, DtPayDisputesModel $dt_pay_disputes_model){

        $user = $request->user();

        if($user){

            $shipments = [];

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

            /*
            Open cases
            */
            $cases = DtPayDisputesModel::query()
                            ->whereIn(
                                'status',
                                    [
                                        DtPayDisputesModel::STATUS_OPEN,
                                        DtPayDisputesModel::STATUS_INVESTIVATING,
                                        DtPayDisputesModel::STATUS_REVIEW,
                                    ]
                            )
                            ->where('added_by', $user->row_id)
                            ->with('transaction:row_id,payment_ref,amount')
                            ->get();

            $dispute_lables = array_column($dt_pay_disputes_model->dispute_reasons_broker(), 'summary', 'key');
            $status_labels = array_column($dt_pay_disputes_model->status_options(), 'value', 'key');

            if($cases->count()){

                foreach($cases as $case){

                    $case->dispute_label = $dispute_lables[$case->dispute_code] ?? 'NA';
                    $case->status_label = $status_labels[$case->status] ?? 'NA';
                }
            }

            return response()->json(['status' => true, 'loads' => $shipments, 'appeal_reasons' => $dt_pay_disputes_model->appeal_reasons_carrier(), 'cases' => $cases, 'message' => 'Appeal has been submitted successfully.'], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function submitAppeal(Request $request, DtPayDisputesModel $dt_pay_disputes_model){

        $user = $request->user();

        if($user){

            $validator = Validator::make($request->all(), [
                'payment' => 'required|string|exists:dt_payments,row_id',
                'appeal_type' => 'required|string|max:100',
                'statement' => 'required|string',
                'evidence' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            ]);

            if($validator->fails()){

                return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
            }

            $data = $validator->validated();

            $evidence_path = $request->hasFile('evidence') ? $this->storeDisputeEvidence($request->file('evidence')) : null;

            $row_id = (string) Str::ulid();

            $dispute_ref = $dt_pay_disputes_model->create_ref_number('dtpay_appeal');

            DtPayDisputesModel::create([
                'row_id' => $row_id,
                'dispute_ref' => $dispute_ref,
                'dispute_type' => DtPayDisputesModel::TYPE_APPEAL,
                'transaction_id' => $data['payment'],
                'dispute_code' => $data['appeal_type'],
                'dispute_details' => $data['statement'],
                'evidence' => $evidence_path,
                'added_by' => $user->row_id,
                'added_by_type' => 'carrier',
                'status' => DtPayDisputesModel::STATUS_OPEN
            ]);

            $dt_pay_disputes_model->update_ref_number('dtpay_appeal');

            return response()->json(['status' => true, 'row_id' => $row_id], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    private function storeDisputeEvidence($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/disputes/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }
}
