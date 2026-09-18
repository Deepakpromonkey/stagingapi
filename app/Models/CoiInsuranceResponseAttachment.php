<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One file that arrived on a reply. See the migration for why the bytes are on
 * the disk and only the index is here.
 */
class CoiInsuranceResponseAttachment extends Model
{
    protected $fillable = [
        'uuid',
        'coi_insurance_response_id',
        'filename',
        'content_type',
        'size_bytes',
        'disk',
        'path',
        'sha256',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attachment) {
            $attachment->uuid ??= (string) Str::uuid();
        });
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(CoiInsuranceResponse::class, 'coi_insurance_response_id');
    }

    /**
     * Whether the browser can show this in place rather than only download it.
     *
     * The card puts a PDF straight on screen, because the whole reason a broker
     * opens the thread is to read the certificate, and a download that lands in
     * a folder is one step further from that than it needs to be.
     */
    public function isPreviewable(): bool
    {
        $type = strtolower((string) $this->content_type);

        return $type === 'application/pdf' || str_starts_with($type, 'image/');
    }
}
