<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One inbound mail matched to a request. See the migration for why the whole
 * body and the provider's raw payload are kept.
 */
class CoiInsuranceResponse extends Model
{
    protected $fillable = [
        'extracted',
        'uuid',
        'coi_insurance_request_id',
        'from_email',
        'from_name',
        'subject',
        'body_text',
        'body_html',
        'raw_payload',
        'llm_response',
        'extracted_expiry_date',
        'received_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'extracted' => 'array',
        'extracted_expiry_date' => 'date',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $response) {
            $response->uuid ??= (string) Str::uuid();
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CoiInsuranceRequest::class, 'coi_insurance_request_id');
    }
}
