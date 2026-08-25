<?php

namespace App\Services\Vin;

use App\Jobs\DecodeVinPatterns;
use App\Models\VinPattern;
use App\Support\Vin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read and fill the VIN pattern cache.
 *
 * Two halves, and the split matters:
 *
 *   lookup()  is the request path. One indexed query against a small local
 *             table. It never touches the network, and a cache miss is simply
 *             a missing entry — the caller renders a blank, it does not wait.
 *
 *   decode()  is the queue path. This is the only place that calls NHTSA.
 *
 * Nothing on the request path may call decode().
 */
class VinDecoderService
{
    /**
     * Decoded patterns for a set of raw VINs, keyed by the *VIN* the caller
     * passed in so it can be joined straight back onto its own rows.
     *
     * @param  iterable<string|null>  $vins
     * @return Collection<string, VinPattern>
     */
    public function lookup(iterable $vins): Collection
    {
        $byPattern = [];

        foreach ($vins as $vin) {
            if ($pattern = Vin::pattern($vin)) {
                $byPattern[$pattern][] = Vin::normalize($vin);
            }
        }

        if (! $byPattern) {
            return collect();
        }

        $decoded = VinPattern::query()
            ->decoded()
            ->whereIn('pattern', array_keys($byPattern))
            ->get()
            ->keyBy('pattern');

        $result = collect();

        foreach ($byPattern as $pattern => $vinList) {
            if (! $hit = $decoded->get($pattern)) {
                continue;
            }

            foreach ($vinList as $vin) {
                $result->put($vin, $hit);
            }
        }

        return $result;
    }

    /**
     * Record any patterns we have never seen and hand them to the queue.
     * Returns how many were newly registered.
     *
     * Safe to call from a request: it writes locally and dispatches, it does
     * not decode. Existing rows — including failures — are left alone, which
     * is what stops a junk pattern being re-queued on every profile view.
     *
     * @param  iterable<string|null>  $vins
     */
    public function queueUnknown(iterable $vins): int
    {
        $patterns = Vin::patterns($vins);

        if (! $patterns) {
            return 0;
        }

        $known = VinPattern::query()
            ->whereIn('pattern', $patterns)
            ->pluck('pattern')
            ->all();

        $missing = array_values(array_diff($patterns, $known));

        if (! $missing) {
            return 0;
        }

        $now = now();

        VinPattern::query()->insertOrIgnore(array_map(fn ($pattern) => [
            'pattern' => $pattern,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing));

        foreach (array_chunk($missing, config('vin.batch_size', 50)) as $index => $chunk) {
            DecodeVinPatterns::dispatch($chunk)
                ->onQueue(config('vin.queue', 'vin'))
                // Spread the batches out rather than letting the worker fire
                // them back to back at NHTSA.
                ->delay(now()->addSeconds((int) ($index * (60 / max(1, config('vin.batches_per_minute', 6))))));
        }

        return count($missing);
    }

    /**
     * Decode one batch of patterns against vPIC and persist the results.
     * Queue path only.
     *
     * @param  array<int, string>  $patterns
     * @return int  patterns successfully decoded
     */
    public function decode(array $patterns): int
    {
        /*
         * strval() again, and not redundantly. Vin::patterns() casts at the
         * source, but a job queued before that fix still carries integers in
         * its serialised payload — and MySQL puts a CHAR(9) column into
         * numeric context the moment one is bound, which both defeats the
         * index and errors on the first non-numeric pattern. Defend here too,
         * where the payload actually arrives.
         */
        $patterns = array_values(array_unique(array_map('strval', array_filter($patterns))));

        if (! $patterns) {
            return 0;
        }

        /*
         * vPIC takes the batch as one semicolon-separated string. Each entry
         * is a partial VIN, so 1FUJGLDR*C stands in for every Cascadia built
         * to that spec in that model year.
         */
        $partials = [];

        foreach ($patterns as $pattern) {
            $partials[Vin::toPartialVin($pattern)] = $pattern;
        }

        $data = implode(';', array_keys($partials));

        try {
            $response = Http::asForm()
                ->timeout(config('vin.timeout', 30))
                ->retry(2, 2000, throw: false)
                ->post(config('vin.endpoint'), [
                    'format' => 'json',
                    'data' => $data,
                ]);
        } catch (\Throwable $e) {
            Log::warning('vPIC batch failed', ['error' => $e->getMessage(), 'count' => count($patterns)]);
            $this->recordAttempt($patterns);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('vPIC batch returned '.$response->status(), ['count' => count($patterns)]);
            $this->recordAttempt($patterns);

            return 0;
        }

        $results = $response->json('Results') ?? [];
        $decoded = 0;
        $seen = [];

        /*
         * Rows are accumulated and written once, not row by row.
         *
         * This loop used to call updateOrInsert() per pattern — a SELECT and
         * an UPDATE each, so about a hundred round trips for a batch of fifty,
         * plus another query per undecodable pattern. Measured on the live
         * backfill that made a batch take 4-5 seconds against the half second
         * the vPIC call itself costs: the decoder spent nine tenths of its
         * time talking to its own database.
         */
        $upserts = [];
        $undecodable = [];
        $exhausted = [];
        $now = now();

        foreach ($results as $index => $row) {
            /*
             * vPIC echoes back the partial VIN it was given, so results are
             * matched on that. Position is only a fallback, for the case where
             * it normalises the echoed value into something unrecognisable.
             */
            $echoed = strtoupper(trim((string) ($row['VIN'] ?? '')));
            $pattern = $partials[$echoed] ?? ($patterns[$index] ?? null);

            if (! $pattern || in_array($pattern, $seen, true)) {
                continue;
            }

            $seen[] = $pattern;

            $year = $this->cleanInt($row['ModelYear'] ?? null);
            $vehicleType = $this->clean($row['VehicleType'] ?? null);
            $bodyClass = $this->clean($row['BodyClass'] ?? null);
            $make = $this->clean($row['Make'] ?? null);
            $model = $this->clean($row['Model'] ?? null);

            /*
             * vPIC answered and had nothing at all. That is a definitive "no
             * data", not a transient failure, so retire it now rather than
             * spending two more full passes rediscovering the same silence.
             *
             * Only a request we could not complete deserves a retry — that is
             * handled separately, below, via $unanswered.
             */
            if (! $year && ! $make && ! $model && ! $vehicleType && ! $bodyClass) {
                $exhausted[] = $pattern;

                continue;
            }

            /*
             * A partial answer is still worth keeping. vPIC knows the make and
             * body class of many small trailers without knowing their model
             * year — 'LARK UNITED MANUFACTURING', VehicleType TRAILER, no year
             * — and that alone fills the Make and Model columns and classifies
             * the unit as towed equipment. model_year stays null, so the row
             * contributes nothing to the age averages, which is correct.
             */
            $upserts[] = [
                'pattern' => $pattern,
                'model_year' => $year,
                'make' => $make,
                'model' => $model,
                'vehicle_type' => $vehicleType,
                'body_class' => $bodyClass,
                'gvwr' => $this->clean($row['GVWR'] ?? null),
                'is_trailer' => $this->isTrailer($vehicleType, $bodyClass),
                'status' => 'ok',
                'decoded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $decoded++;
        }

        if ($exhausted) {
            VinPattern::query()
                ->whereIn('pattern', array_map('strval', $exhausted))
                ->update([
                    'status' => 'failed',
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => $now,
                ]);
        }

        if ($upserts) {
            // One INSERT ... ON DUPLICATE KEY UPDATE for the whole batch.
            // created_at is deliberately absent from the update list so it
            // survives on a row that already existed.
            VinPattern::query()->upsert($upserts, ['pattern'], [
                'model_year', 'make', 'model', 'vehicle_type', 'body_class',
                'gvwr', 'is_trailer', 'status', 'decoded_at', 'updated_at',
            ]);
        }

        if ($undecodable) {
            $this->recordAttempt($undecodable);
        }

        // Anything vPIC simply did not answer for.
        $unanswered = array_values(array_diff($patterns, $seen));

        if ($unanswered) {
            $this->recordAttempt($unanswered);
        }

        return $decoded;
    }

    /**
     * vPIC classifies towed equipment as VehicleType TRAILER, and gives the
     * BodyClass 'Trailer' as well. Either is enough.
     */
    protected function isTrailer(?string $vehicleType, ?string $bodyClass): bool
    {
        return str_contains(strtoupper($vehicleType.' '.$bodyClass), 'TRAILER');
    }

    /**
     * Bump the attempt counter and retire the pattern once it has had enough
     * chances. Keeps a permanently undecodable VIN out of the queue.
     *
     * @param  array<int, string>  $patterns
     */
    protected function recordAttempt(array $patterns): void
    {
        if (! $patterns) {
            return;
        }

        $patterns = array_map('strval', $patterns);

        VinPattern::query()
            ->whereIn('pattern', $patterns)
            ->increment('attempts', 1, ['updated_at' => now()]);

        VinPattern::query()
            ->whereIn('pattern', $patterns)
            ->where('status', 'pending')
            ->where('attempts', '>=', config('vin.max_attempts', 3))
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }

    /**
     * Trim, normalise vPIC's empty markers, and cap at the column width.
     *
     * The cap is not cosmetic. A batch is written as one upsert, so a single
     * over-long value would abort the statement and take all fifty rows with
     * it — losing forty-nine good decodes to one long body_class string. A
     * truncated label is a far better outcome than a lost batch.
     */
    protected function clean(?string $value, int $max = 255): ?string
    {
        $value = trim((string) $value);

        // vPIC writes 'Not Applicable' rather than leaving a field empty.
        if ($value === '' || strcasecmp($value, 'Not Applicable') === 0) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    protected function cleanInt($value): ?int
    {
        $value = $this->clean((string) $value);

        return ($value !== null && ctype_digit($value)) ? (int) $value : null;
    }
}
