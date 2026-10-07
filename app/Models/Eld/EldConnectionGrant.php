<?php

namespace App\Models\Eld;

use App\Models\CarrierConnectRequest;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One broker company's permission to see a carrier's fleet data.
 *
 * Revoking is a timestamp rather than a delete: the carrier's consent, and its
 * withdrawal, are both things the broker may later have to evidence.
 */
class EldConnectionGrant extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'company_id',
        'carrier_connect_request_id',
        'granted_at',
        'revoked_at',
        'consent_template',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connectRequest(): BelongsTo
    {
        return $this->belongsTo(CarrierConnectRequest::class, 'carrier_connect_request_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
