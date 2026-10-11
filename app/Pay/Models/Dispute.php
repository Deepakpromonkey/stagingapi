<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    protected $table = 'dtpay_disputes';

    public const OPEN = 'open';
    public const RESOLVED = 'resolved';

    public const REASONS = [
        'not_delivered' => 'Load not delivered',
        'damaged' => 'Freight damaged',
        'short' => 'Shortage / missing items',
        'late' => 'Late delivery',
        'wrong_amount' => 'Wrong amount',
        'duplicate' => 'Duplicate payment',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected $casts = ['evidence_paths' => 'array', 'resolved_at' => 'datetime'];
}
