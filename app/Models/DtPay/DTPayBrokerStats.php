<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Support\Number;

class DTPayBrokerStats extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dt_pay_broker_stats';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'broker_id',
        'hold_sum',
        'hold_count',
        'ready_to_release_sum',
        'ready_to_release_count',
        'released_sum',
        'released_count',
        'disputes_count',
        'last_updated',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'hold_sum' => 'decimal:2',
        'hold_count' => 'integer',
        'ready_to_release_sum' => 'decimal:2',
        'ready_to_release_count' => 'integer',
        'released_sum' => 'decimal:2',
        'released_count' => 'integer',
        'disputes_count' => 'integer',
        'last_updated' => 'datetime',
    ];

    /**
     * Get the broker (User) that owns this payment summary.
     */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id', 'uuid');
    }

    public function addStat($brokerId, $type, $amount = null){

        $entry = self::findByBrokerId($brokerId);

        if($entry){

            if($type == 'hold'){

                $data['hold_sum'] = $amount + $entry->hold_sum;
                $data['hold_count'] = $entry->hold_count + 1;
            }

            if($type == 'ready_to_release'){

                $data['ready_to_release_sum'] = $amount + $entry->ready_to_release_sum;
                $data['ready_to_release_count'] = 1 + $entry->ready_to_release_count;
            }

            if($type == 'released'){

                $data['released_sum'] = $amount + $entry->released_sum;
                $data['released_count'] = 1 + $entry->released_count;
            }

            if($type == 'dispute'){

                $data['disputes_count'] = 1 + $entry->disputes_count;
            }

            $data['last_updated'] = now();

            $entry->update($data);
        }else{

            $data = ['broker_id' => $brokerId];

            if($type == 'hold'){

                $data['hold_sum'] = $amount;
                $data['hold_count'] = 1;
            }

            if($type == 'ready_to_release'){

                $data['ready_to_release_sum'] = $amount;
                $data['ready_to_release_count'] = 1;
            }

            if($type == 'released'){

                $data['released_sum'] = $amount;
                $data['released_count'] = 1;
            }

            $data['last_updated'] = now();

            self::create($data);
        }
    }

    public static function findByBrokerId($brokerId){

        return self::where('broker_id', $brokerId)->first();
    }

    public function fetchBrokerStats($brokerId){

        $entry = self::findByBrokerId($brokerId);

        if($entry){

            return [
                'hold_sum' => Number::currency($entry->hold_sum),
                'hold_count' => $entry->hold_count,
                'ready_to_release_sum' => Number::currency($entry->ready_to_release_sum),
                'ready_to_release_count' => $entry->ready_to_release_count,
                'released_sum' => Number::currency($entry->released_sum),
                'released_count' => $entry->released_count,
                'disputes_count' => $entry->disputes_count,
            ];
        }

        return [
            'hold_sum' => '$0',
            'hold_count' => 0,
            'ready_to_release_sum' => '$0',
            'ready_to_release_count' => 0,
            'released_sum' => '$0',
            'released_count' => 0,
            'disputes_count' => 0,
        ];
    }
}