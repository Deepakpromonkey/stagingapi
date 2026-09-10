<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A carrier's answer to one of the broker company's onboarding questions.
 *
 * The question text and answer type are snapshotted at answer time — the broker
 * can edit or delete the question afterwards, and the answer still has to be
 * readable on its own.
 */
class CarrierConnectAnswer extends Model
{
    protected $fillable = [
        'carrier_connect_request_id',
        'carrier_question_id',
        'question_text',
        'answer_type',
        'answer',
        'answer_document_disk',
        'answer_document_path',
        'answer_document_name',
    ];

    public function connectRequest()
    {
        return $this->belongsTo(CarrierConnectRequest::class, 'carrier_connect_request_id');
    }

    public function question()
    {
        return $this->belongsTo(CarrierQuestion::class, 'carrier_question_id');
    }

    /**
     * Short-lived signed URL for an uploaded answer, or null when the answer is
     * plain text.
     */
    public function documentUrl(int $minutes = 30): ?string
    {
        if (! $this->answer_document_path) {
            return null;
        }

        try {
            return Storage::disk($this->answer_document_disk)->temporaryUrl(
                $this->answer_document_path,
                now()->addMinutes($minutes)
            );
        } catch (\Throwable) {
            // Disks without signed-URL support (local during development).
            return Storage::disk($this->answer_document_disk)->url($this->answer_document_path);
        }
    }
}
