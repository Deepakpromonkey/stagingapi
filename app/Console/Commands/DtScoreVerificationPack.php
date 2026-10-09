<?php

namespace App\Console\Commands;

use App\Services\DtScore\DtScore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Score the reference carriers against the live warehouse and keep the full
 * result for each, so every documented score traces to current data.
 *
 * Reads the carrier warehouse only. Writes the pack to
 * storage/app/dtscore-packs/{date}/ (one JSON per DOT plus summary.json),
 * and, as any scoring does, the score cache and trust_score_evaluations.
 */
class DtScoreVerificationPack extends Command
{
    protected $signature = 'dtscore:verification-pack
        {dots?* : DOT numbers; defaults to the seven reference carriers}';

    protected $description = 'Re-score the DT score reference carriers and archive the full results.';

    /** The reference set from the October 2026 audit. */
    private const REFERENCE = [
        '3415372' => 'Warrior',
        '1007597' => 'Trout',
        '120670' => 'Kaplan',
        '1001665' => 'Miracle',
        '100139' => 'Kreilkamp',
        '1000000' => 'Killingsworth',
        '4361498' => 'M&M',
    ];

    public function handle(): int
    {
        $dots = $this->argument('dots') ?: array_keys(self::REFERENCE);

        $results = DtScore::fullResultsFor($dots);

        $dir = 'dtscore-packs/'.now()->format('Y-m-d_His');
        $summary = [];

        foreach ($dots as $dot) {
            $result = $results[$dot] ?? null;

            if ($result === null) {
                $summary[] = [$dot, self::REFERENCE[$dot] ?? '', 'not found', '', '', ''];

                continue;
            }

            Storage::disk('local')->put("{$dir}/{$dot}.json", json_encode($result, JSON_PRETTY_PRINT));

            $summary[] = [
                $dot,
                self::REFERENCE[$dot] ?? '',
                $result['overall_score'] ?? '',
                $result['status'] ?? '',
                ($result['needs_manual_review'] ?? false) ? 'yes' : 'no',
                implode(' ', array_column($result['v3']['rules_fired'] ?? [], 'id')),
            ];
        }

        $headers = ['DOT', 'name', 'score', 'status', 'review', 'rules fired'];

        Storage::disk('local')->put("{$dir}/summary.json", json_encode([
            'model_version' => config('dtscore.model_version'),
            'generated_at' => now()->toIso8601String(),
            'rows' => array_map(fn ($row) => array_combine($headers, $row), $summary),
        ], JSON_PRETTY_PRINT));

        $this->table($headers, $summary);
        $this->info("Pack written to storage/app/{$dir}");

        return self::SUCCESS;
    }
}
