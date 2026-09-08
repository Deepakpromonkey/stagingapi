<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayIncrementModel;

class DtPayDisputesModel extends Model
{    
    use HasUuids;

    public $timestamps = true;
    
    protected $table = 'dt_pay_disputes';

    const STATUS_OPEN = 'open';
    const STATUS_INVESTIVATING = 'investigating';
    const STATUS_REVIEW = 'review';
    const STATUS_RESOLVED = 'resolved';

    const TYPE_DISPUTE = 'dispute';
    const TYPE_APPEAL = 'appeal';

    protected $fillable = [
        'uuid',
        'dispute_ref',
        'dispute_type',
        'transaction_id',
        'dispute_code',
        'dispute_details',
        'evidence',
        'added_by',
        'added_by_type',
        'status'
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function transaction(){

        return $this->belongsTo(DtPayModel::class, 'transaction_id', 'uuid');
    }

    public function create_ref_number($code){

        $shipments_increment_model = new DtPayIncrementModel;

		$row = $shipments_increment_model->last_increment($code);

		if($row !== false){

			$next_increment = $row->increment + 1;

			$payment_number = str_pad($next_increment, "4", "0", STR_PAD_LEFT);

			return $row->prefix . "-" . $payment_number;
		}

		return false;
	}

    public function update_ref_number($code){

        $shipments_increment_model = new DtPayIncrementModel;
        
        $shipments_increment_model->update_increment($code);
    }

    public function dispute_reasons_broker(){

        return [
            ['key' => 'non_delivery', 'value' => 'Non-delivery — freight never arrived', 'summary' => 'Non-delivery claim'],
            ['key' => 'short_damaged_delivery', 'value' => 'Short / damaged delivery', 'summary' => 'Short-damaged delivery'],
            ['key' => 'pod_mismatch', 'value' => 'POD mismatch — wrong document', 'summary' => 'POD mismatch'],
            ['key' => 'wrong_amount', 'value' => 'Wrong amount charged', 'summary' => 'Wrong amount'],
            ['key' => 'service_failure', 'value' => 'Service failure (late, no-show)', 'summary' => 'Service failure'],
            ['key' => 'fraud', 'value' => 'Suspected fraud', 'summary' => 'Suspected fraud']
        ];
    }

    public function appeal_reasons_carrier(){

        return [
            ['key' => 'release_overdue', 'value' => 'Release overdue — POD verified > 48h ago', 'summary' => 'Release overdue'],
            ['key' => 'counter_dispute', 'value' => 'Counter a broker dispute', 'summary' => 'Counter dispute'],
            ['key' => 'pod_issue', 'value' => 'POD wrongly failed verification', 'summary' => 'POD issue'],
            ['key' => 'wrong_acount', 'value' => 'Payout went to wrong account / factor issue', 'summary' => 'Wrong account']
        ];
    }

    public function status_options(){

        return [
            ['key' => self::STATUS_OPEN, 'value' => 'Open'],
            ['key' => self::STATUS_INVESTIVATING, 'value' => 'Investigating'],
            ['key' => self::STATUS_REVIEW, 'value' => 'Under Review'],
            ['key' => self::STATUS_RESOLVED, 'value' => 'Resolved'],
        ];
    }
}
