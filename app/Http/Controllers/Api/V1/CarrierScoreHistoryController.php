<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * The DT score's change history for one carrier, newest first, from the
 * receipts DtScore::recordEvaluation() keeps. Each row is a moment the score,
 * status, fired rules or config changed - not every time it was looked at.
 */
class CarrierScoreHistoryController extends Controller
{
    public function show(string $dot)
    {
        $rows = DB::table('trust_score_evaluations')
            ->where('dot_number', $dot)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'score', 'status', 'band', 'needs_manual_review', 'model_version', 'payload', 'created_at']);

        return response()->json([
            'dot_number' => $dot,
            'history' => $rows->map(function ($row) {
                $payload = json_decode($row->payload, true) ?: [];

                return [
                    'id' => (int) $row->id,
                    'score' => (int) $row->score,
                    'status' => $row->status,
                    'band' => $row->band ?: null,
                    'needs_manual_review' => (bool) $row->needs_manual_review,
                    'model_version' => $row->model_version,
                    'rules_fired' => array_values(array_column($payload['v3']['rules_fired'] ?? [], 'id')),
                    'flags' => array_values($payload['v3']['flags'] ?? []),
                    'created_at' => $row->created_at,
                ];
            })->values(),
        ]);
    }
}
