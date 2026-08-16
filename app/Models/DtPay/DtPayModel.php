<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

use App\Models\DtPay\DtPayIncrementModel;

use App\Models\DtPay\DtPayDisputesModel;
use App\Models\DtPay\DtPayPaymentLoadsModel;
use App\Models\DtPay\DtPayLogsModel;

use App\Models\Carriers\Carrier;
use App\Models\Shipment;

use Illuminate\Support\Number;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

class DtPayModel extends Model
{
    
    use HasUuids;

    public $timestamps = true;
    
    protected $table = 'dt_payments';
    
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;

    const SOURCE_AUTO = 'AUTO';
    const SOURCE_MANUAL = 'MANUAL';

    const STAGE_IN_HOLD = 'in_hold';
    const STAGE_READY = 'ready';
    const STAGE_PAID_OUT = 'paid_out';
    const STAGE_DISPUTED = 'disputed';
    const STAGE_REFUNDED = 'refunded';

    const STATUS_INIT = 'init';
    const STATUS_REVIEW = 'review';
    const STATUS_PAID = 'paid';
    const STATUS_HOLD = 'hold';
    const STATUS_POD_VERIFIED = 'pod_verified';
    const STATUS_REFUND = 'refund';
    const STATUS_DISPUTED = 'disputed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'broker_id',
        'carrier_id',
        'payment_ref',
        'load_id',
        'source',
        'carrier_name',
        'amount',
        'tax',
        'fee',
        'card_fee',
        'payment_method',
        'payment_method_id',
        'payment_method_label',
        'payment_id',

        'transfer_id',
        'transferred_amount',
        'transfer_date',
        'transferred_by',

        'stage',
        'status',
        'payment_date',

        'payment_status',
        'stripe_payment_date',
        'stripe_transaction_id',
        'stripe_status',
        'stripe_response',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'fee' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function disputes(){

        return $this->hasMany(DtPayDisputesModel::class, 'transaction_id', 'uuid');
    }

    public function carrier(){

        return $this->hasOne(Carrier::class, 'dot_number', 'carrier_id');
    }

    public function payment_load(){

        return $this->hasOne(DtPayPaymentLoadsModel::class, 'transaction_id', 'uuid');
    }

    public function shipment(){

        return $this->hasOne(Shipment::class, 'uuid', 'load_id');
    }

    public function payment_logs(){

        return $this->hasMany(DtPayLogsModel::class, 'payment_id', 'uuid');
    }

    public function platform_fees(){

        return 1.2;
    }

    public function calculations($amount, $method_type = 'bank'){

        $amounts = [];
        $amounts['carrier_rate'] = $amount;
        $amounts['carrier_rate_formatted'] = Number::currency($amount);

        if($method_type == 'bank'){

            $ach_funding_fee = 0;
        }else{

            $ach_funding_fee = $this->calculatePercentage($amount, 2.9);
        }

        $amounts['ach_funding_fee'] = $ach_funding_fee;
        $amounts['ach_funding_fee_formatted'] = Number::currency($ach_funding_fee);

        $platform_fee = $this->calculatePercentage($amount, $this->platform_fees());

        $amounts['platform_fee'] = $platform_fee;
        $amounts['platform_fee_formatted'] = Number::currency($platform_fee);

        $total_chargeable = round(((float) $amount + (float) $ach_funding_fee + (float) $platform_fee), 2, PHP_ROUND_HALF_UP);

        $amounts['total_chargeable'] = $total_chargeable;
        $amounts['total_chargeable_formatted'] = Number::currency($total_chargeable);

        $carrier_receives = round(((float) $amount - (float) $platform_fee), 2, PHP_ROUND_HALF_UP);
        
        $amounts['carrier_receives'] = $carrier_receives;
        $amounts['carrier_receives_formatted'] = Number::currency($carrier_receives);;

        return $amounts;
    }

    public function calculatePercentage($amount, $percentage){
        
        $amountStr = (string) $amount;

        $divided = bcdiv($amountStr, '100', 4); 

        $multiplied = bcmul($divided, $percentage, 4);

        $finalResult = round((float) $multiplied, 2, PHP_ROUND_HALF_UP);

        return number_format($finalResult, 2, '.', '');
    }

    public function create_payment_number($payment_type){

        $shipments_increment_model = new DtPayIncrementModel;

		$row = $shipments_increment_model->last_increment('dtpay');

		if($row !== false){

			$next_increment = $row->increment + 1;

			$payment_number = str_pad($next_increment, "6", "0", STR_PAD_LEFT);

			return $row->prefix . "-" . $payment_type . "-" . $payment_number;
		}

		return false;
	}

    public function transactionTotal($transaction){

        return round(((float) $transaction->amount + (float) $transaction->card_fee + (float) $transaction->fee), 2, PHP_ROUND_HALF_UP);
    }

    public function update_payment_number(){

        $shipments_increment_model = new DtPayIncrementModel;
        
        $shipments_increment_model->update_increment('dtpay');
    }

    public function stripePaymentSources($user){

        $stripe_model = new StripeModel;

        list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

        $stripe = new StripeClient($api_secret_key);

        $stripe_customer_id = $user->stripe_customer_id;

        $sources = [];

        if($stripe_customer_id != ''){

            $cards = $stripe->paymentMethods->all([
                'customer' => $stripe_customer_id,
                'type' => 'card',
            ]);

            foreach($cards->data as $method){

                $sources[] = ['type' => 'card', 'key' => $method->id, 'label' => strtoupper($method->card->display_brand) . "...-" . $method->card->last4, 'sub_label' => '2.9% funding fee · instant clearing'];
            }

            $paymentMethods = $stripe->paymentMethods->all([
                'customer' => $stripe_customer_id,
                'type' => 'us_bank_account', 
            ]);

            foreach($paymentMethods->data as $method){

                $sources[] = ['type' => 'bank', 'key' => $method->id, 'label' => $method->us_bank_account->bank_name . "...-" . $method->us_bank_account->last4, 'sub_label' => 'Free · ACH debit enabled'];
            }
        }

        return $sources;
    }

    public function stage_options(){

        return [
            ['key' => self::STAGE_IN_HOLD, 'value' => 'In Hold'],
            ['key' => self::STAGE_READY, 'value' => 'Ready'],
            ['key' => self::STAGE_PAID_OUT, 'value' => 'Paid Out'],
            ['key' => self::STAGE_DISPUTED, 'value' => 'Disputed'],
            ['key' => self::STAGE_REFUNDED, 'value' => 'Refunded'],
        ];
    }

    public function status_options(){

        return [
            ['key' => self::STATUS_INIT, 'value' => 'Initiated', 'color' => '#4d88ff', 'bg' => '#e1ebff'],
            ['key' => self::STATUS_REVIEW, 'value' => 'Under review', 'color' => '#9133d0', 'bg' => '#f3e0ff'],
            ['key' => self::STATUS_PAID, 'value' => 'Paid', 'color' => '#14988b', 'bg' => '#e0fff8'],
            ['key' => self::STATUS_HOLD, 'value' => 'On hold', 'color' => '#987514', 'bg' => '#f5ffe3'],
            ['key' => self::STATUS_POD_VERIFIED, 'value' => 'POD Verified', 'color' => '#589814', 'bg' => '#ecffe3'],
            ['key' => self::STATUS_REFUND, 'value' => 'Refunded', 'color' => '#981461', 'bg' => '#ffe7f3'],
            ['key' => self::STATUS_DISPUTED, 'value' => 'Disputed', 'color' => '#981414', 'bg' => '#ffe7f5'],
            ['key' => self::STATUS_CANCELLED, 'value' => 'Cancelled', 'color' => '#981414', 'bg' => '#ffe9e9'],
        ];
    }

    function text_truncate_center($string, $limit = 20, $end = '...'){

		if(strlen($string) <= $limit){

        	return $string;
    	}

		$separatorLen = strlen($end);
		$targetLen = $limit - $separatorLen;
		$startLen = ceil($targetLen / 2);
		$endLen = floor($targetLen / 2);

		return substr($string, 0, $startLen) . $end . substr($string, -$endLen);
	}

    public function format($row){

        foreach($this->status_options() as $status_option){

            if($status_option['key'] == $row->status){

                $row->status_color = $status_option['color'];
                $row->status_bg = $status_option['bg'];
                $row->status_label = $status_option['value'];

                break;
            }
        }

        return $row;
    }
}
