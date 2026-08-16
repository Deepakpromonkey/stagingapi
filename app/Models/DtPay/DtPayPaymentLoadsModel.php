<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Model;

class DtPayPaymentLoadsModel extends Model
{
    
    public $timestamps = true;
    
    protected $table = 'dt_payments_loads';

    const CONDITION_ON_POD_VERIFICATION = 'pod_verified';
    const CONDITION_ON_DELIVERY_DATE = 'delivery_date';
    const CONDITION_ON_FUND_CLEAR = 'fund_clear';

    protected $fillable = [
        'transaction_id',
        'load_ref',
        'carrier_invoice',
        'origin',
        'destination',
        'pickup_date',
        'delivery_date',
        'equipment',
        'commodity',
        'weight',
        'linehaul_rate',
        'accessorials',
        'total_to_carrier',
        'rate_confirmation',
        'pod',
        'added_by',
        'added_by_type'
    ];

    protected $casts = [
        'linehaul_rate' => 'decimal:2',
        'total_to_carrier' => 'decimal:2',
        'pickup_date' => 'datetime',
        'delivery_date' => 'datetime'
    ];

    public function releas_conditions(){

        return [
            ['key' => self::CONDITION_ON_POD_VERIFICATION, 'value' => 'On POD verification', 'label' => 'AI matches POD against this load; you confirm release.', 'icon' => 'done'],
            ['key' => self::CONDITION_ON_DELIVERY_DATE, 'value' => 'On delivery date', 'label' => 'Funds release automatically on the delivery date above unless you hold.', 'icon' => 'calendar_today'],
            ['key' => self::CONDITION_ON_FUND_CLEAR, 'value' => 'As soon as funds clear', 'label' => 'No POD gate. For trusted carriers only — releases when ACH settles.
', 'icon' => 'electric_bolt']
        ];
    }
}
