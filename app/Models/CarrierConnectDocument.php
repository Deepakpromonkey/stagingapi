<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A compliance document uploaded by the carrier during onboarding.
 */
class CarrierConnectDocument extends Model
{
    protected $table = 'carrier_connect_documents';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * The documents asked for, slug => label. Both are currently required, so
     * `documents_completed_at` is only set once one of each exists.
     */
    public const TYPES = [
        'w9' => 'W-9 Form',
        'coi' => 'Certificate of Insurance',
    ];

    public function label(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function connectRequest(): BelongsTo
    {
        return $this->belongsTo(CarrierConnectRequest::class, 'carrier_connect_request_id');
    }
}
