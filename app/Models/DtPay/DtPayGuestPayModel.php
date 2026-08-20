<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

use App\Models\DtPay\DtPayIncrementModel;
use App\Models\DtPay\DtPayLogsModel;

class DtPayGuestPayModel extends Model
{
    use HasUuids;
    
    public $timestamps = true;
    
    protected $table = 'dt_pay_guest';
    
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;

    // const SOURCE_AUTO = 'AUTO';
    // const SOURCE_MANUAL = 'MANUAL';

    // const STAGE_POD_VERIFIED = 'pod_verified';
    // const STAGE_REFUNDED = 'refunded';
    // const STAGE_RELEASED = 'released';
    // const STAGE_PAID = 'paid';

    const STATUS_INIT = 'init';
    const STATUS_REVIEW = 'review';
    const STATUS_PAID = 'paid';
    const STATUS_HOLD = 'hold';
    const STATUS_REFUND = 'refund';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'broker_id',
        'carrier_id',
        'payment_ref',
        'load_id',
        'source',
        'amount',
        'tax',
        'fee',
        'card_fee',
        'payment_method',
        'payment_method_id',
        'payment_method_label',
        'payment_id',

        'carrier_invoice',
        'origin',
        'destination',
        'delivery_date',
        'equipment',
        'rate_confirmation',

        'customer_legal_name',
        'role',
        'broker_mc',
        'ein',
        'contact_name',
        'phone',
        'email',
        'business_address',

        'transfer_id',
        'transferred_amount',
        'transfer_date',
        'transferred_by',

        'stage',
        'status',
        'payment_date',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function payment_logs(){

        return $this->hasMany(DtPayLogsModel::class, 'guest_payment_id', 'uuid');
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'fee' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function create_payment_number(){

        $shipments_increment_model = new DtPayIncrementModel;

		$row = $shipments_increment_model->last_increment('dtpay');

		if($row !== false){

			$next_increment = $row->increment + 1;

			$payment_number = str_pad($next_increment, "6", "0", STR_PAD_LEFT);

			return $row->prefix . "-" . "GUEST" . "-" . $payment_number;
		}

		return false;
	}

    public function update_payment_number(){

        $shipments_increment_model = new DtPayIncrementModel;
        
        $shipments_increment_model->update_increment('dtpay');
    }
}
