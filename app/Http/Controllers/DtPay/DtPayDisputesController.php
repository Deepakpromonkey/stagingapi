<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayDisputesModel;
use App\Models\DtPay\DTPayBrokerStats;
use App\Models\DtPay\DtPayLogsModel;

class DtPayDisputesController extends Controller
{

    public function init(Request $request, DtPayModel $dt_pay_model, DtPayDisputesModel $dt_pay_disputes_model){

        $user = $request->user();

        if($user){

            $payments = [];

            $status = [DtPayModel::STATUS_INIT, DtPayModel::STATUS_REVIEW, DtPayModel::STATUS_HOLD];

            $records = $dt_pay_model
                                ->where('broker_id', $user->uuid)
                                ->when(count($status), function ($query) use ($status) {

                                    $query->whereIn('status', $status);
                                })
                                ->with(['carrier:id,row_id,legal_name,dot_number'])
                                ->orderBy('payment_date', 'desc')
                                ->get();

            if($records){

                foreach($records as $record){

                    $label = $record->payment_ref . "·" . $dt_pay_model->text_truncate_center($record->legal_name) . "·" . Number::currency($record->amount);

                    $payments[] = ['key' => $record->uuid, 'value' => $label];
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
                            ->where('added_by', $user->uuid)
                            ->with('transaction:uuid,payment_ref,amount')
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

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 200);
    }

    public function submitDispute(Request $request, DtPayDisputesModel $dt_pay_disputes_model, DTPayBrokerStats $dt_pay_broker_stats, DtPayModel $dt_pay_model){

        $user = $request->user();

        if($user){

            $validator = Validator::make($request->all(), [
                'payment' => 'required|string|exists:dt_payments,uuid',
                'dispute_type' => 'required|string|max:100',
                'reason' => 'required|string',
                'evidence' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            ]);

            if($validator->fails()){

                return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
            }

            $data = $validator->validated();

            $evidence_path = $request->hasFile('evidence') ? $this->storeDisputeEvidence($request->file('evidence')) : null;

            $dispute_ref = $dt_pay_disputes_model->create_ref_number('dtpay_dispute');

            $transaction = $dt_pay_model->find($data['payment']);

            if($transaction){

                $dispute = DtPayDisputesModel::create([
                    'dispute_ref' => $dispute_ref,
                    'dispute_type' => DtPayDisputesModel::TYPE_DISPUTE,
                    'transaction_id' => $data['payment'],
                    'dispute_code' => $data['dispute_type'],
                    'dispute_details' => $data['reason'],
                    'evidence' => $evidence_path,
                    'added_by' => $user->uuid,
                    'added_by_type' => 'broker',
                    'status' => DtPayDisputesModel::STATUS_OPEN
                ]);

                /*
                Add stats
                */
                $dt_pay_broker_stats->addStat($user->uuid, 'dispute');

                $dt_pay_disputes_model->update_ref_number('dtpay_dispute');

                /*
                Update transaction stage / status
                */
                $dt_pay_model->where('uuid', $data['payment'])->update(['stage' => DtPayModel::STAGE_DISPUTED, 'status' => DtPayModel::STATUS_DISPUTED]);

                /*
                Generate logs
                */
                DtPayLogsModel::create([
                    'payment_id' => $data['payment'],
                    'transaction_label' => "Dispute raised (" . $transaction->payment_ref . ")",
                    'sub_label' => "by " . $user->first_name . " " . $user->last_name,
                    'added_by' => $user->uuid,
                    'added_by_type' => 'broker',
                    'transaction_date' => now(),
                    'sequence' => 9
                ]);

                return response()->json(['status' => true, 'row_id' => $dispute->uuid, 'message' => 'Dispute request has been submitted successfully.'], 200);
            }
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 200);
    }

    private function storeDisputeEvidence($file){

        $extension = strtolower($file->getClientOriginalExtension());

        $storage_path = 'uploads/dt-pay/disputes/' . (string) Str::ulid() . '.' . $extension;

        Storage::put($storage_path, file_get_contents($file->getRealPath()));

        return $storage_path;
    }
}
