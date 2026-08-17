<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Model;

use App\Models\DtPay\DtPayModel;
use App\Models\DtPay\DtPayGuestPayModel;

class DtPayLogsModel extends Model
{
    
    public $timestamps = true;
    
    protected $table = 'dt_payments_trasactions';

    protected $fillable = [
        'payment_id',
        'guest_payment_id',
        'transaction_label',
        'sub_label',
        'added_by',
        'added_by_type',
        'transaction_date',
        'load_ref',
        'load',
        'carrier_dot_number',
        'carrier_name',
        'sequence',
        'stripe_transaction_id',
        'stripe_payment_status',
        'stripe_response'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    public function transaction(){

        return $this->belongsTo(DtPayModel::class, 'payment_id', 'uuid');
    }

    public function guest_transaction(){

        return $this->belongsTo(DtPayGuestPayModel::class, 'guest_payment_id', 'uuid');
    }

    public function transactionProgress($transaction, $side = 'broker'){

        $progress = [];

        $steps = [1 => 'First up', 2 => 'Next' , 3 => 'Moving on to', 4 => 'Right after', 5 => 'Last of all', 7 => 'Manual hold', 8 => 'Disputed', 9 => 'Disputed'];

        if($transaction->payment_logs){

            $payment_logs = $transaction->payment_logs;

            $sequence = 1;

            foreach($payment_logs as $payment_log){

                $sequence = $payment_log->sequence;

                $step = array_key_exists($payment_log->sequence, $steps) ? $steps[$payment_log->sequence] : 'Stage';

                $progress[] = ['step' => $step, 'label' => $payment_log->transaction_label, 'text' => $payment_log->sub_label, 'state' => 'active'];
            }
        }

        if($side == 'broker'){

            if($transaction->stage == DtPayModel::STAGE_IN_HOLD){

                if($sequence == 2){

                    $progress[] = ['step' => 'Moving on to', 'label' => 'Funds clear → hold active', 'text' => 'Release unlocks once settled'];
                    $progress[] = ['step' => 'Right after', 'label' => 'POD verified by AI', 'text' => 'Carrier uploads · Vault agent matches against the load'];
                    $progress[] = ['step' => 'Last of all', 'label' => 'You release → carrier paid', 'text' => 'On next available ACH batch, or instant for 1%'];
                }

                if($sequence == 3){

                    $progress[] = ['step' => 'Right after', 'label' => 'POD verified by AI', 'text' => 'Carrier uploads · Vault agent matches against the load'];
                    $progress[] = ['step' => 'Last of all', 'label' => 'You release → carrier paid', 'text' => 'On next available ACH batch, or instant for 1%'];
                }

                if($sequence == 4){

                    $progress[] = ['step' => 'Last of all', 'label' => 'You release → carrier paid', 'text' => 'On next available ACH batch, or instant for 1%'];
                }

            }elseif($transaction->stage == DtPayModel::STAGE_DISPUTED){

                $progress[] = ['step' => 'Moving on to', 'label' => 'Dispute will be reviewed', 'text' => 'Settled by the DT Pay legal team.'];
            }else{
        
                if($sequence == 2){

                    $progress[] = ['step' => 'Moving on to', 'label' => 'Funds clear → hold active', 'text' => 'Release unlocks once settled'];
                    $progress[] = ['step' => 'Right after', 'label' => 'POD verified by AI', 'text' => 'Carrier uploads · Vault agent matches against the load'];
                    $progress[] = ['step' => 'Last of all', 'label' => 'You release → carrier paid', 'text' => 'On next available ACH batch, or instant for 1%'];
                }

                if($sequence == 3){

                    $progress[] = ['step' => 'Right after', 'label' => 'POD verified by AI', 'text' => 'Carrier uploads · Vault agent matches against the load'];
                    $progress[] = ['step' => 'Last of all', 'label' => 'You release → carrier paid', 'text' => 'On next available ACH batch, or instant for 1%'];
                }

                if($sequence == 4){

                    $progress[] = ['step' => 'Last of all', 'label' => 'You release → carrier paid', 'text' => 'On next available ACH batch, or instant for 1%'];
                }
            }
        }

        return $progress;
    }
}
