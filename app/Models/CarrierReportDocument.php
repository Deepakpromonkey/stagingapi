<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A file a broker attached to an incident report as evidence.
 */
class CarrierReportDocument extends Model
{
    protected $table = 'carrier_report_documents';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document) {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CarrierReport::class, 'carrier_report_id');
    }
}
