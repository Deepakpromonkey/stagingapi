<?php

namespace App\Services\DtScore;

use App\Models\Carriers\Carrier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batched answers to the engine's four fraud-network questions, written
 * into the exact cache keys dtNetworkCounts() reads, so scoring a page of
 * carriers costs four queries instead of forty. Used by DtScore::many().
 *
 * Moved here from AdvancedCarrierSearchController, which kept it alongside
 * an identical hand-kept copy in CarrierShortlistController.
 */
class NetworkCountsPrewarmer
{
    private function conn()
    {
        return DB::connection((new Carrier)->getConnectionName());
    }

    /* ======================================================================
     | Fraud-network pre-warm
     |
     | dtNetworkCounts() in the engine asks, for one carrier: how many OTHER
     | carriers share this phone, this email, this street address, one of
     | these VINs. Four questions, each a COUNT(DISTINCT dot_number) over a
     | 4.48M-row view, and it wraps the answer in Cache::remember for six
     | hours - so a carrier profile pays it once and never again.
     |
     | A search page pays it ten times, on ten cold keys, and measured ~4s
     | each: 40 of the 68 seconds a first search took.
     |
     | Nothing here changes the engine or the score. It answers the same four
     | questions for the whole page in four queries instead of forty, then
     | writes the results into the very keys dtNetworkCounts() is about to
     | read. By the time the engine runs, every Cache::remember is a hit, so
     | the score is computed from identical inputs - the profile page and the
     | search still agree to the digit.
     |
     | v3.3 changed what those four questions actually ask: a shared
     | identifier no longer counts against a carrier when the OTHER party's
     | legal_name shares its name stem (KAPLAN TRUCKING / KAPLAN LOGISTICS at
     | one HQ read as family, not a chameleon reincarnation) - see
     | dtNameStem() on the engine. That changed the cache key too
     | (dt:trust:net: -> dt:trust:net:v2:), because the old key's values were
     | computed a different way and would otherwise be served back as if
     | they were the new answer. Every helper below applies the identical
     | exclusion, so a value this file writes and a value the engine would
     | have computed cold are the same number, not an approximation of it.
     |
     | Everything degrades: if any of this throws, the keys simply stay cold
     | and the engine computes them itself, exactly as before, only slower.
     ====================================================================== */

    /**
     * Fill dt:trust:net:v2:{dot} for a page of carriers before scoring them.
     *
     * @param  Collection<int, Carrier>  $carriers
     */
    public function prewarm($carriers): void
    {
        // The engine returns null immediately when this is off, and never
        // touches the cache - so pre-warming would be writing keys nothing
        // will ever read.
        if (! config('dtscore.rules.network.enabled', true)) {
            return;
        }

        $pending = [];

        foreach ($carriers as $carrier) {
            $dot = (string) $carrier->dot_number;

            if ($dot === '') {
                continue;
            }

            // Already warm - almost always the majority of a page, since the
            // keys live six hours and carriers recur across searches.
            if (Cache::get('dt:trust:net:v2:'.$dot) !== null) {
                continue;
            }

            $pending[$dot] = $carrier;
        }

        if (empty($pending)) {
            return;
        }

        try {
            $phones = $this->dtSharedFieldCounts($pending, 'telephone');
            $emails = $this->dtSharedFieldCounts($pending, 'email_address');
            $addresses = $this->dtSharedAddressCounts($pending);
            $vins = $this->dtSharedVinCounts($pending);

            foreach ($pending as $dot => $carrier) {
                /*
                | Key, TTL and array shape all copied from dtNetworkCounts()
                | deliberately. A missing entry stays null rather than
                | becoming 0, because the engine distinguishes them: null is
                | "no phone on file, cannot ask", 0 is "asked, shares it with
                | nobody", and the identity rules read those differently.
                */
                Cache::put('dt:trust:net:v2:'.$dot, [
                    'phone' => $phones[$dot] ?? null,
                    'email' => $emails[$dot] ?? null,
                    'address' => $addresses[$dot] ?? null,
                    'vin' => $vins[$dot] ?? null,
                ], (int) config('dtscore.rules.network.cache_seconds'));
            }
        } catch (\Throwable $e) {
            Log::warning('[DtScore] network prewarm failed, engine will fall back to per-carrier: '.$e->getMessage());
        }
    }

    /**
     * How many other carriers share each page carrier's phone / email.
     *
     * The engine asks with `dot_number <> self AND legal_name NOT LIKE
     * {self's name stem}%` - self excluded explicitly, an affiliate (same
     * stem) excluded because it isn't the chameleon pattern the rule exists
     * to catch. Every carrier on the page can have a different stem even
     * when two of them share the same phone, so the bucket a value falls
     * into is (value, stem) together, not the value alone - two carriers
     * only ever share a bucket when both the value AND the stem match,
     * which is exactly when they'd get the same answer from the engine too.
     *
     * Self-subtraction only happens when no stem filter ran: when it did,
     * self is already excluded by name (a carrier's own legal_name always
     * matches its own stem), and subtracting again would undercount by one.
     *
     * UNION ALL of one indexed `=` lookup per bucket, NOT `IN (...) GROUP
     * BY`. That distinction is worth 26 seconds: `carriers` is a view, and
     * grouping on a view column makes MySQL materialise and scan all 4.48M
     * rows, while plain equality merges into the view and rides idx_phone /
     * idx_email. Measured on one page of ten: 26.2s grouped, 0.56s this way,
     * identical numbers out. One round trip either way.
     *
     * @param  array<string, Carrier>  $pending
     * @return array<string, int>
     */
    private function dtSharedFieldCounts(array $pending, string $column): array
    {
        // Whitelisted because it is interpolated into SQL below. Both are
        // this file's own literals today; this keeps it that way.
        if (! in_array($column, ['telephone', 'email_address'], true)) {
            return [];
        }

        $buckets = [];      // "value\0stem" => bucket index
        $bucketMeta = [];   // bucket index => ['value' => ..., 'stem_like' => ...|null]

        foreach ($pending as $carrier) {
            // empty(), not === '', to match the engine: it skips '0' too.
            if (empty($carrier->{$column})) {
                continue;
            }

            // A string value, not an array key: PHP turns a numeric-string
            // key like a phone number into an int, and binding an int
            // against a varchar column is how you quietly lose the index.
            $value = (string) $carrier->{$column};

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $stemLike = $stem !== null ? addcslashes($stem, '\\%_').'%' : null;

            $bucketKey = $value."\0".($stem ?? '');

            if (isset($buckets[$bucketKey])) {
                continue;
            }

            $buckets[$bucketKey] = count($bucketMeta);

            $bucketMeta[] = ['value' => $value, 'stem_like' => $stemLike];
        }

        if (empty($bucketMeta)) {
            return [];
        }

        $parts = [];
        $bindings = [];

        foreach ($bucketMeta as $i => $meta) {

            if ($meta['stem_like'] !== null) {
                $parts[] = "SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers WHERE {$column} = ? AND legal_name NOT LIKE ?";
                $bindings[] = $i;
                $bindings[] = $meta['value'];
                $bindings[] = $meta['stem_like'];
            } else {
                $parts[] = "SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers WHERE {$column} = ?";
                $bindings[] = $i;
                $bindings[] = $meta['value'];
            }
        }

        $shared = [];

        foreach ($this->conn()->select(implode(' UNION ALL ', $parts), $bindings) as $row) {
            $shared[(int) $row->bucket] = (int) $row->shared;
        }

        $out = [];

        foreach ($pending as $dot => $carrier) {
            if (empty($carrier->{$column})) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $bucketKey = ((string) $carrier->{$column})."\0".($stem ?? '');

            $bucket = $buckets[$bucketKey];

            $count = $shared[$bucket] ?? 0;

            if ($bucketMeta[$bucket]['stem_like'] === null) {
                $count = max(0, $count - 1);
            }

            $out[(string) $dot] = $count;
        }

        return $out;
    }

    /**
     * How many other carriers sit at each page carrier's physical address.
     *
     * Matched on the same three columns the engine matches on, plus the same
     * name-stem exclusion and (value, stem) bucketing as
     * dtSharedFieldCounts() above - see that method's docblock for why both
     * exist. UNION ALL of plain equality so each branch still rides idx_phy
     * (phy_state, phy_city, phy_street), rather than a row-constructor IN
     * with a GROUP BY, which scans the view.
     *
     * @param  array<string, Carrier>  $pending
     * @return array<string, int>
     */
    private function dtSharedAddressCounts(array $pending): array
    {
        // Same guard as the engine: a street too short to be a real address
        // is not worth asking about, and would group half the feed together.
        $usable = fn ($c) => ! empty($c->phy_street) && strlen(trim((string) $c->phy_street)) > 5;

        $addressKey = fn ($c) => implode("\0", [(string) $c->phy_state, (string) $c->phy_city, (string) $c->phy_street]);

        $buckets = [];      // "state\0city\0street\0stem" => bucket index
        $bucketMeta = [];   // bucket index => ['tuple' => [state, city, street], 'stem_like' => ...|null]

        foreach ($pending as $carrier) {
            if (! $usable($carrier)) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $stemLike = $stem !== null ? addcslashes($stem, '\\%_').'%' : null;

            $bucketKey = $addressKey($carrier)."\0".($stem ?? '');

            if (isset($buckets[$bucketKey])) {
                continue;
            }

            $buckets[$bucketKey] = count($bucketMeta);

            $bucketMeta[] = [
                'tuple' => [(string) $carrier->phy_state, (string) $carrier->phy_city, (string) $carrier->phy_street],
                'stem_like' => $stemLike,
            ];
        }

        if (empty($bucketMeta)) {
            return [];
        }

        $parts = [];
        $bindings = [];

        foreach ($bucketMeta as $i => $meta) {

            $bindings[] = $i;

            array_push($bindings, ...$meta['tuple']);

            if ($meta['stem_like'] !== null) {
                $parts[] = 'SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers
                             WHERE phy_state = ? AND phy_city = ? AND phy_street = ? AND legal_name NOT LIKE ?';

                $bindings[] = $meta['stem_like'];
            } else {
                $parts[] = 'SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers
                             WHERE phy_state = ? AND phy_city = ? AND phy_street = ?';
            }
        }

        $shared = [];

        foreach ($this->conn()->select(implode(' UNION ALL ', $parts), $bindings) as $row) {
            $shared[(int) $row->bucket] = (int) $row->shared;
        }

        $out = [];

        foreach ($pending as $dot => $carrier) {
            if (! $usable($carrier)) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $bucketKey = $addressKey($carrier)."\0".($stem ?? '');

            $bucket = $buckets[$bucketKey];

            $count = $shared[$bucket] ?? 0;

            if ($bucketMeta[$bucket]['stem_like'] === null) {
                $count = max(0, $count - 1);
            }

            $out[(string) $dot] = $count;
        }

        return $out;
    }

    /**
     * How many other carriers have been inspected in the same trucks.
     *
     * Two queries for the page's own VINs and who else has them, plus a
     * third for the affiliate exclusion - see dtSharedFieldCounts() for why
     * that exists. It has to be a separate lookup here: an owner's stem
     * membership depends on the TARGET carrier's name, not the owner's, so
     * unlike phone/email/address there is no single WHERE clause that can
     * apply it while the counting is still happening in SQL. Instead every
     * distinct owner DOT across the whole page is named-looked-up once, and
     * the exclusion is applied in PHP while tallying each target's count.
     *
     * The engine caps each carrier at 200 VINs and so does this - for a
     * carrier with more than 200 the two can pick a different 200, since
     * neither orders the rows, but the signal being scored is "is this truck
     * shared at all", which does not turn on which 200 were sampled.
     *
     * @param  array<string, Carrier>  $pending
     * @return array<string, int>
     */
    private function dtSharedVinCounts(array $pending): array
    {
        $dots = array_map('strval', array_keys($pending));

        // DISTINCT because a truck stopped fifty times is one VIN, and the
        // busiest carriers in the feed have over 22,000 inspection rows.
        $vinRows = $this->conn()
            ->table('inspections')
            ->select('dot_number', 'vin')
            ->distinct()
            ->whereIn('dot_number', $dots)
            ->whereRaw('CHAR_LENGTH(vin) = 17')
            ->get();

        $perCarrier = [];

        foreach ($vinRows as $row) {
            $dot = (string) $row->dot_number;

            if (count($perCarrier[$dot] ?? []) >= 200) {
                continue;
            }

            $perCarrier[$dot][] = (string) $row->vin;
        }

        if (empty($perCarrier)) {
            return [];
        }

        $allVins = array_values(array_unique(array_merge(...array_values($perCarrier))));

        $owners = [];

        // Chunked so the IN() list stays inside what the server will plan
        // for, even on a full page of heavily-inspected carriers.
        foreach (array_chunk($allVins, 1000) as $chunk) {
            $rows = $this->conn()
                ->table('inspections')
                ->select('vin', 'dot_number')
                ->distinct()
                ->whereIn('vin', $chunk)
                ->get();

            foreach ($rows as $row) {
                $owners[(string) $row->vin][(string) $row->dot_number] = true;
            }
        }

        /*
        | Every distinct "other" owner across the whole page, named in one
        | batch - not per target carrier, since the same owner DOT can turn
        | up against several targets on a busy page and its name never
        | changes between them.
        */
        $ownerDots = [];

        foreach ($pending as $dot => $carrier) {
            $dot = (string) $dot;

            foreach ($perCarrier[$dot] ?? [] as $vin) {
                foreach (array_keys($owners[$vin] ?? []) as $owner) {
                    if ((string) $owner !== $dot) {
                        $ownerDots[(string) $owner] = true;
                    }
                }
            }
        }

        $ownerNames = [];

        if (! empty($ownerDots)) {
            $ownerNames = $this->conn()
                ->table('carriers')
                ->select('dot_number', 'legal_name')
                ->whereIn('dot_number', array_keys($ownerDots))
                ->pluck('legal_name', 'dot_number')
                ->all();
        }

        $out = [];

        foreach ($pending as $dot => $carrier) {
            $dot = (string) $dot;

            // No usable VINs means the engine leaves this null, not zero.
            if (empty($perCarrier[$dot])) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $others = [];

            foreach ($perCarrier[$dot] as $vin) {
                foreach (array_keys($owners[$vin] ?? []) as $owner) {
                    // Both sides cast to string on purpose: PHP turns numeric
                    // array keys into ints, so a bare !== would compare "123"
                    // against 123 and call every owner a different carrier.
                    $owner = (string) $owner;

                    if ($owner === $dot) {
                        continue;
                    }

                    if ($stem !== null) {
                        $ownerName = strtoupper((string) ($ownerNames[$owner] ?? ''));

                        // Case-insensitive prefix match in PHP, not a SQL
                        // LIKE - this never touches the database, so it
                        // cannot silently depend on the column's collation
                        // being case-insensitive the way the engine's own
                        // `LIKE` implicitly does.
                        if (str_starts_with($ownerName, $stem)) {
                            continue;
                        }
                    }

                    $others[$owner] = true;
                }
            }

            $out[$dot] = count($others);
        }

        return $out;
    }

    /**
     * Affiliate name stem, identical to the engine's dtNameStem(): uppercase,
     * punctuation stripped, leading article (THE/A/AN) dropped, first token
     * kept when it is 4+ characters. Named differently from the trait's own
     * private method of the same job so composing DtTrustScoreV3 can never
     * silently pick this one up instead of its own - a plain class method
     * always wins over an identically-named trait method, so a genuine
     * name clash here would mean the engine's internal exclusion quietly
     * started running search-page logic instead of its own.
     */
    private function dtSearchNameStem($name): ?string
    {
        $name = strtoupper(trim((string) ($name ?? '')));

        $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name);

        $tokens = array_values(array_filter(explode(' ', (string) $name)));

        if (isset($tokens[0]) && in_array($tokens[0], ['THE', 'A', 'AN'], true)) {
            array_shift($tokens);
        }

        $stem = $tokens[0] ?? '';

        return strlen($stem) >= 4 ? $stem : null;
    }
}
