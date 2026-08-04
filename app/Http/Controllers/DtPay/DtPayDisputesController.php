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

use App\Models\Shipments\ShipmentsModel;

class DtPayDisputesController extends Controller
{

    public function init(Request $request, DtPayModel $dt_pay_model, CarriersModel $carriers_model, DtPayPaymentLoadsModel $dt_pay_payment_loads_model, ShipmentsModel $shipments_model, DtPayDisputesModel $dt_pay_disputes_model){

        $user = $request->user();

        if($user){

            $payments = [];

            $records = $dt_pay_model
                            ->select(
                                "dt_payments.row_id",
                                "dt_payments.payment_ref",
                                "dt_payments.source",
                                "dt_payments.amount",
                                "carriers.legal_name"
                            )
                            ->leftJoin($carriers_model->getTable(), "dt_payments.carrier_id", "=", "carriers.row_id")
                            ->whereIn('dt_payments.status', [DtPayModel::STATUS_INIT, DtPayModel::STATUS_REVIEW, DtPayModel::STATUS_HOLD])
                            ->get();

            if($records){

                foreach($records as $record){

                    $label = $record->payment_ref . "·" . $dt_pay_model->text_truncate_center($record->legal_name) . "·" . Number::currency($record->amount);

                    $payments[] = ['key' => $record->row_id, 'value' => $label];
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

            return response()->json(['status' => true, 'payments' => $payments, 'dispute_reasons' => $dt_pay_disputes_model->dispute_reasons_broker(), 'cases' => $cases], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 404);
    }

    public function submitDispute(Request $request, DtPayDisputesModel $dt_pay_disputes_model){

        $user = $request->user();

        if($user){

            $validator = Validator::make($request->all(), [
                'payment' => 'required|string|exists:dt_payments,row_id',
                'dispute_type' => 'required|string|max:100',
                'reason' => 'required|string',
                'evidence' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            ]);

            if($validator->fails()){

                return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
            }

            $data = $validator->validated();

            $evidence_path = $request->hasFile('evidence') ? $this->storeDisputeEvidence($request->file('evidence')) : null;

            $row_id = (string) Str::ulid();

            $dispute_ref = $dt_pay_disputes_model->create_ref_number('dtpay_dispute');

            DtPayDisputesModel::create([
                'row_id' => $row_id,
                'dispute_ref' => $dispute_ref,
                'dispute_type' => DtPayDisputesModel::TYPE_DISPUTE,
                'transaction_id' => $data['payment'],
                'dispute_code' => $data['dispute_type'],
                'dispute_details' => $data['reason'],
                'evidence' => $evidence_path,
                'added_by' => $user->row_id,
                'added_by_type' => 'broker',
                'status' => DtPayDisputesModel::STATUS_OPEN
            ]);

            $dt_pay_disputes_model->update_ref_number('dtpay_dispute');

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
