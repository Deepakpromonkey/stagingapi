<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrokerAgreementDocument extends Model
{
    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'title',
        'description',
        'disk',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'file_size' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Short-lived signed URL — the bucket is private, so the object is never
     * publicly readable.
     */
    public function downloadUrl(int $minutes = 5): ?string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl(
                $this->file_path,
                now()->addMinutes($minutes)
            );
        } catch (\Throwable) {
            // Disks without signed-URL support (e.g. local during testing).
            return Storage::disk($this->disk)->url($this->file_path);
        }
    }
}
