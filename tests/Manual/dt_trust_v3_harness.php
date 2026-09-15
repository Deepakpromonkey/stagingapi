<?php

namespace {

/*
 * Standalone harness for App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3.
 *
 * Stubs just enough Laravel/Fmcsa surface to compose the real trait into a
 * fake controller and run scoring scenarios with no database, no container
 * and no framework boot.
 *
 *   php tests/Manual/dt_trust_v3_harness.php
 *
 * Exits 0 when every scenario passes.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

/* ---------------------------------------------------------------- dates */

class DtDate
{
    public DateTimeImmutable $d;

    public function __construct(DateTimeImmutable $d) { $this->d = $d; }

    public static function make(string $s): ?self
    {
        $s = trim($s);
        if ($s === '') return null;
        foreach (['d-M-y', 'd-M-Y', 'Y-m-d'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat($fmt, ucwords(strtolower($s)));
            if ($d instanceof DateTimeImmutable) return new self($d->setTime(0, 0));
        }
        try { return new self(new DateTimeImmutable($s)); } catch (\Throwable) { return null; }
    }

    public function subMonths(int $n): self { return new self($this->d->modify("-{$n} months")); }
    public function gte(DtDate $o): bool { return $this->d >= $o->d; }
    public function isFuture(): bool { return $this->d > new DateTimeImmutable('now'); }
    public function diffInDays(DtDate $o): int { return (int) $this->d->diff($o->d)->days; }
    public function __get($k) { if ($k === 'year') return (int) $this->d->format('Y'); return null; }
}

function now(): DtDate { return new DtDate(new DateTimeImmutable('now')); }

function config(string $key, $default = null) { return $GLOBALS['dt_config'][$key] ?? $default; }

/* ------------------------------------------------------------ collection */

class Col implements Countable
{
    public array $items;
    public function __construct(array $items = []) { $this->items = array_values($items); }
    public function filter(?callable $cb = null): Col
    { return new Col(array_values(array_filter($this->items, $cb ?? fn ($x) => (bool) $x))); }
    public function count(): int { return count($this->items); }
    public function pluck(string $key): Col
    { return new Col(array_map(fn ($x) => is_array($x) ? ($x[$key] ?? null) : ($x->$key ?? null), $this->items)); }
    public function unique(): Col { return new Col(array_values(array_unique($this->items, SORT_REGULAR))); }
    public function contains($v): bool
    {
        if (is_callable($v)) { foreach ($this->items as $i) { if ($v($i)) return true; } return false; }
        return in_array($v, $this->items, true);
    }
    public function sum($key = null)
    {
        $t = 0;
        foreach ($this->items as $i) { $t += $key === null ? $i : (is_callable($key) ? $key($i) : ($i->$key ?? 0)); }
        return $t;
    }
    public function where(string $key, $value): Col
    { return new Col(array_values(array_filter($this->items, fn ($x) => (is_array($x) ? ($x[$key] ?? null) : ($x->$key ?? null)) == $value))); }
    public function sortByDesc(callable $cb): Col
    { $c = $this->items; usort($c, fn ($a, $b) => $cb($b) <=> $cb($a)); return new Col($c); }
    public function sortBy(callable $cb): Col
    { $c = $this->items; usort($c, fn ($a, $b) => $cb($a) <=> $cb($b)); return new Col($c); }
    public function first() { return $this->items[0] ?? null; }
    public function isNotEmpty(): bool { return count($this->items) > 0; }
    public function take(int $n): Col { return new Col(array_slice($this->items, 0, $n)); }
    public function all(): array { return $this->items; }
    public function map(callable $cb): Col { return new Col(array_map($cb, $this->items)); }
    public function values(): Col { return new Col(array_values($this->items)); }
    public function flatMap(callable $cb): Col
    { $o = []; foreach ($this->items as $i) { foreach ($cb($i) as $x) { $o[] = $x; } } return new Col($o); }
}

function collect($items = []): Col { return new Col(is_array($items) ? $items : iterator_to_array($items)); }

/* ---------------------------------------------------------------- stubs */

}

namespace App\Support {

class Fmcsa
{
    public static function date($s): ?\DtDate { return $s === null ? null : \DtDate::make((string) $s); }
    public static function dateKey($s): ?int
    { $d = self::date($s); return $d ? (int) $d->d->format('U') : null; }
    public static function authorityType(string $key): array
    {
        return match ($key) {
            'common' => ['COMMON', 'PROPERTY COMMON CARRIER', 'COMMON CARRIER'],
            'contract' => ['CONTRACT', 'PROPERTY CONTRACT CARRIER', 'CONTRACT CARRIER'],
            'broker' => ['BROKER', 'PROPERTY BROKER'],
        };
    }
}

}

namespace Illuminate\Support\Facades {

class Cache
{
    public static function remember($key, $ttl, $cb) { return $cb(); }
}

}

namespace App\Models\Carriers {

class StubQuery
{
    public ?string $firstColumn = null;
    public function where($col, $a = null, $b = null): self
    { if ($this->firstColumn === null && is_string($col)) $this->firstColumn = $col; return $this; }
    public function whereIn($col, $v): self
    { if ($this->firstColumn === null) $this->firstColumn = $col; return $this; }
    public function distinct(): self { return $this; }
    public function pluck($col): \Col { return new \Col([]); }
    public function count($col = null): int
    {
        $map = ['telephone' => 'phone', 'email_address' => 'email', 'phy_state' => 'address', 'vin' => 'vin'];
        $k = $map[$this->firstColumn] ?? null;
        return (int) ($GLOBALS['dt_netcounts'][$k] ?? 0);
    }
}

class Carrier { public static function query(): StubQuery { return new StubQuery; } }
class Inspection { public static function query(): StubQuery { return new StubQuery; } }

/* ------------------------------------------------------------- records */

}

namespace Harness {

class Rec
{
    public array $a;
    public function __construct(array $a = []) { $this->a = $a; }
    public function __get($k) { return $this->a[$k] ?? null; }
    public function __isset($k) { return isset($this->a[$k]); }
    public function getAttribute($k) { return $this->a[$k] ?? null; }
    public function relationLoaded($k): bool { return isset($this->a[$k]); }
}

/* ------------------------------------------------- the composed class */

}

namespace App\Http\Controllers\Carrier {

require dirname(__DIR__, 2).'/app/Http/Controllers/Carrier/Concerns/DtTrustScoreV3.php';

use App\Support\Fmcsa;

class FakeController
{
    use Concerns\DtTrustScoreV3;

    private const SMS_BASICS = ['unsafe_driv', 'hos_driv', 'driv_fit', 'contr_subst', 'veh_maint'];

    private const MAIL_DROP_PATTERNS = [
        'UPS STORE', 'REGUS', 'WEWORK', 'PMB ', 'POSTAL ANNEX',
        'MAIL BOXES ETC', 'MAILBOX', 'REGISTERED AGENT', 'VIRTUAL OFFICE', 'SUITE #',
    ];

    private function insuranceFilingMatches($filing, string $kind): bool
    {
        $code = strtoupper(trim((string) ($filing->ins_form_code ?? '')));
        $desc = strtoupper((string) ($filing->ins_type_desc ?? ''));

        return match ($kind) {
            'bipd' => in_array($code, ['91', '91X'], true) || str_starts_with($desc, 'BIPD'),
            'cargo' => $code === '34' || str_contains($desc, 'CARGO'),
            'bond' => in_array($code, ['84', '85'], true)
                || str_contains($desc, 'SURETY')
                || str_contains($desc, 'BOND')
                || str_contains($desc, 'TRUST FUND'),
            default => false,
        };
    }

    private function hasInsuranceFiling($carrier, string $kind): bool
    {
        return $carrier->insuranceFilings->contains(function ($filing) use ($kind) {
            if (! $this->insuranceFilingMatches($filing, $kind)) return false;
            if (empty($filing->cancl_effective_date)) return true;
            return Fmcsa::date($filing->cancl_effective_date)?->isFuture() ?? false;
        });
    }

    private function smsPercentiles(): array
    {
        $cuts = [];
        foreach (self::SMS_BASICS as $b) { $cuts[$b] = [50 => 1.0, 75 => 5.0, 90 => 10.0]; }
        return $cuts;
    }

    private function benchmarks(): array
    {
        return ['natl_vehicle_oos' => 0.20, 'natl_driver_oos' => 0.05];
    }

    private function getGrade($score)
    {
        return match (true) {
            $score >= 90 => 'A', $score >= 80 => 'B', $score >= 70 => 'C', $score >= 60 => 'D', default => 'F',
        };
    }

    private function parseFmcsaDate(?string $value): ?\DtDate
    {
        return $value === null ? null : \DtDate::make($value);
    }

    public function run(...$args) { return $this->dtCalculateTrustScore(...$args); }
}

/* ------------------------------------------------------------ scenarios */

}

namespace Harness {

use App\Http\Controllers\Carrier\FakeController;

function d(int $daysAgo): string { return (new \DateTimeImmutable("-{$daysAgo} days"))->format('d-M-y'); }

function carrier(array $over = []): Rec
{
    return new Rec(array_merge([
        'dot_number' => '1749',
        'telephone' => '2075550100',
        'email_address' => 'dispatch@rundletttrucking.com',
        'phy_street' => '12 MILL RD', 'phy_city' => 'HOLLIS', 'phy_state' => 'ME',
        'mailing_street' => '12 MILL RD',
        'nbr_power_unit' => 2,
        'mcs150_mileage' => 90000,
        'driver_total' => 2,
        'insuranceFilings' => \collect([new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '01000', 'cancl_effective_date' => null])]),
        'insuranceFilingsPending' => \collect([]),
        'insuranceFilingsHistory' => \collect([new Rec(['name_company' => 'ACADIA']), new Rec(['name_company' => 'ACADIA'])]),
        'oosOrders' => \collect([]),
        'authorityHistory' => \collect([new Rec(['op_auth_type' => 'COMMON', 'original_action_desc' => 'GRANTED', 'disp_action_desc' => 'GRANTED', 'orig_served_date' => d(19000)])]),
        'authorityOrders' => \collect([]),
        'crashes' => \collect([]),
        'inspections' => \collect([new Rec(['vin' => null]), new Rec(['vin' => null]), new Rec(['vin' => null])]),
    ], $over));
}

function detail(array $over = []): Rec
{
    return new Rec(array_merge([
        'status_code' => 'A', 'safety_rating' => 'S', 'prior_revoke_flag' => 'N',
        'dun_bradstreet_no' => '123', 'total_drivers' => 2,
    ], $over));
}

function auth(array $over = []): Rec
{
    return new Rec(array_merge([
        'common_stat' => 'A', 'contract_stat' => 'N', 'broker_stat' => 'N',
        'common_app_pend' => 'N', 'contract_app_pend' => 'N', 'broker_app_pend' => 'N',
        'common_rev_pend' => 'N', 'contract_rev_pend' => 'N', 'broker_rev_pend' => 'N',
        'bipd_file' => '01000', 'min_cov_amount' => '00750',
        'cargo_req' => 'N', 'cargo_file' => '00000',
        'bond_req' => 'N', 'bond_file' => '00000',
    ], $over));
}

function sms(array $over = []): Rec
{
    return new Rec(array_merge([
        'insp_total' => 12, 'vehicle_insp_total' => 8, 'driver_insp_total' => 10,
        'unsafe_driv_measure' => 0.4, 'hos_driv_measure' => 0.2, 'driv_fit_measure' => 0,
        'contr_subst_measure' => 0, 'veh_maint_measure' => 0.8,
        'unsafe_driv_ac' => null, 'hos_driv_ac' => null, 'driv_fit_ac' => null,
        'contr_subst_ac' => null, 'veh_maint_ac' => null,
        'unsafe_driv_insp_w_viol' => 2, 'hos_driv_insp_w_viol' => 1, 'driv_fit_insp_w_viol' => 0,
        'contr_subst_insp_w_viol' => 0, 'veh_maint_insp_w_viol' => 2,
    ], $over));
}

function run(FakeController $c, array $o = []): array
{
    $GLOBALS['dt_config'] = $o['config'] ?? ['trustscore.network_checks' => false];
    $GLOBALS['dt_netcounts'] = $o['net'] ?? [];

    return $c->run(
        $o['carrier'] ?? carrier(),
        array_key_exists('detail', $o) ? $o['detail'] : detail(),
        array_key_exists('sms', $o) ? $o['sms'] : sms(),
        array_key_exists('auth', $o) ? $o['auth'] : auth(),
        array_key_exists('vehicleOosPct', $o) ? $o['vehicleOosPct'] : 12.5,
        array_key_exists('driverOosPct', $o) ? $o['driverOosPct'] : 0.0,
        51, null, null,
        // array_key_exists, not ??: these two are meaningful as an explicit
        // null (the engine treats an unknown DOT age / MCS-150 year very
        // differently from a known one), and ?? would quietly restore the
        // default and score a scenario the test never asked for.
        array_key_exists('dotAge', $o) ? $o['dotAge'] : 52,
        array_key_exists('mcs150Year', $o) ? $o['mcs150Year'] : (int) date('Y'),
        $o['observedUnits'] ?? 2,
        $o['observedTrailers'] ?? 1,
        $o['crashesTotal'] ?? 0, $o['crashFatalities'] ?? 0, $o['crashInjuries'] ?? 0, $o['crashesTowAway'] ?? 0,
        $o['inspectionCount'] ?? null
    );
}

$c = new FakeController;
$pass = 0; $fail = 0;

function check(string $name, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}   -> {$got}\n"; }
}

/* 1. Clean established carrier (Rundlett) */
$r = run($c);
check('clean carrier -> 100 / Approved / A / Preferred',
    $r['overall_score'] === 100 && $r['status'] === 'Approved' && $r['grade'] === 'A'
    && $r['v3']['band']['key'] === 'preferred' && $r['knockout']['triggered'] === false,
    json_encode([$r['overall_score'], $r['status'], $r['grade'], $r['v3']['band']['key'], $r['v3']['rules_fired']]));

/* 2. Authority stored as the SAFER phrase */
$r = run($c, ['auth' => auth(['common_stat' => 'AUTHORIZED FOR Property'])]);
check('SAFER phrase authority -> still 100', $r['overall_score'] === 100, (string) $r['overall_score']);

/* 3. Authority genuinely inactive on both */
$r = run($c, ['auth' => auth(['common_stat' => 'I', 'contract_stat' => 'I'])]);
check('inactive authority -> 18 Rejected AUTHORITY_INACTIVE',
    $r['overall_score'] === 18 && $r['status'] === 'Rejected'
    && $r['knockout']['reasons'][0]['code'] === 'AUTHORITY_INACTIVE' && $r['pillars'] === [],
    json_encode([$r['overall_score'], $r['status'], $r['knockout']]));

/* 4. Unsatisfactory rating */
$r = run($c, ['detail' => detail(['safety_rating' => 'U'])]);
check('Unsatisfactory -> 18 Rejected', $r['overall_score'] === 18 && $r['status'] === 'Rejected', (string) $r['overall_score']);

/* 5. Conditional rating — NEW hard stop */
$r = run($c, ['detail' => detail(['safety_rating' => 'CONDITIONAL'])]);
check('Conditional -> 18 Rejected CONDITIONAL_RATING',
    $r['overall_score'] === 18 && collect($r['knockout']['reasons'])->contains(fn ($x) => $x['code'] === 'CONDITIONAL_RATING'),
    json_encode($r['knockout']));

/* 6. Unrated carrier — no penalty, no knockout */
$r = run($c, ['detail' => detail(['safety_rating' => ''])]);
check('unrated -> no finding, 100', $r['overall_score'] === 100, json_encode($r['v3']['rules_fired']));

/* 7. BIPD '00000' and no filing */
$r = run($c, ['auth' => auth(['bipd_file' => '00000']), 'carrier' => carrier(['insuranceFilings' => \collect([])])]);
check('no BIPD anywhere -> 18 NO_BIPD',
    $r['overall_score'] === 18 && $r['knockout']['reasons'][0]['code'] === 'NO_BIPD',
    json_encode($r['knockout']));

/* 8. BIPD on file but short of the required minimum */
$r = run($c, ['carrier' => carrier(['insuranceFilings' => \collect([new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '00300', 'cancl_effective_date' => null])])]), 'auth' => auth(['bipd_file' => '00300'])]);
check('BIPD 300k vs required 750k -> 18 BIPD_INSUFFICIENT',
    $r['overall_score'] === 18 && collect($r['knockout']['reasons'])->contains(fn ($x) => $x['code'] === 'BIPD_INSUFFICIENT'),
    json_encode($r['knockout']));

/* 9. Bond required, not on file — demoted from knockout to Low */
$r = run($c, ['auth' => auth(['bond_req' => 'Y'])]);
check('bond-only gap -> Low finding, ~94, Approved',
    $r['overall_score'] === 94 && $r['status'] === 'Approved' && $r['knockout']['triggered'] === false,
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 10. Two BASICs at/over the threshold cut -> categorical Fail */
$r = run($c, ['sms' => sms(['unsafe_driv_measure' => 6.2, 'hos_driv_measure' => 5.5])]);
check('two BASICs over -> 18 MULTIPLE_BASIC_THRESHOLDS',
    $r['overall_score'] === 18 && collect($r['knockout']['reasons'])->contains(fn ($x) => $x['code'] === 'MULTIPLE_BASIC_THRESHOLDS'),
    json_encode($r['knockout']));

/* 11. One BASIC over -> Medium, still Acceptable */
$r = run($c, ['sms' => sms(['unsafe_driv_measure' => 6.2])]);
check('one BASIC over -> Medium 250, ~89, Approved',
    $r['overall_score'] === 89 && $r['status'] === 'Approved' && $r['v3']['status'] === 'Acceptable',
    json_encode([$r['overall_score'], $r['v3']['risk_points'], $r['v3']['rules_fired']]));

/* 12. Authority granted 10 days ago -> Review + cap 45 + senior flag */
$r = run($c, ['carrier' => carrier(['authorityHistory' => \collect([new Rec(['op_auth_type' => 'COMMON', 'original_action_desc' => 'GRANTED', 'disp_action_desc' => 'GRANTED', 'orig_served_date' => d(10)])])])]);
check('10-day authority -> 45 / Review / senior_approval_required',
    $r['overall_score'] === 45 && $r['status'] === 'Review'
    && in_array('senior_approval_required', $r['v3']['flags'], true)
    && $r['v3']['band']['key'] === 'review_required',
    json_encode([$r['overall_score'], $r['status'], $r['v3']['flags'], $r['v3']['score_caps']]));

/* 13. Authority granted 60 days ago -> Medium + cap 65 */
$r = run($c, ['carrier' => carrier(['authorityHistory' => \collect([new Rec(['op_auth_type' => 'PROPERTY COMMON CARRIER', 'original_action_desc' => 'GRANTED', 'disp_action_desc' => 'GRANTED', 'orig_served_date' => d(60)])])])]);
check('60-day authority -> 65 / Review band Conditional? (capped, Acceptable status)',
    $r['overall_score'] === 65 && $r['v3']['status'] === 'Acceptable' && $r['status'] === 'Review'
    && in_array('documented_review_required', $r['v3']['flags'], true),
    json_encode([$r['overall_score'], $r['v3']['status'], $r['v3']['flags']]));

/* 14. No authority row at all -> capped 84, review flag, no knockout */
$r = run($c, ['auth' => null]);
check('no authority row -> 84 cap + manual review, not Rejected',
    $r['overall_score'] === 84 && $r['knockout']['triggered'] === false && $r['v3']['needs_manual_review'] === true,
    json_encode([$r['overall_score'], $r['v3']['score_cap_applied'], $r['v3']['needs_manual_review']]));

/* 15. Pending insurance cancellation -> Review status */
$r = run($c, ['carrier' => carrier(['insuranceFilings' => \collect([
    new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '01000', 'cancl_effective_date' => (new \DateTimeImmutable('+20 days'))->format('d-M-y')]),
])])]);
check('pending cancellation -> 54 Review review_required',
    $r['overall_score'] === 54 && $r['status'] === 'Review' && $r['v3']['band']['key'] === 'review_required',
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 16. Fatal crash in window vs old fatal crash */
$cr = carrier(['crashes' => \collect([new Rec(['report_date' => d(200), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false])])]);
$r = run($c, ['carrier' => $cr, 'crashesTotal' => 1, 'crashFatalities' => 1]);
$old = carrier(['crashes' => \collect([new Rec(['report_date' => d(2000), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false])])]);
$r2 = run($c, ['carrier' => $old, 'crashesTotal' => 1, 'crashFatalities' => 1]);
check('fatal crash: 54 inside 24mo, 100 outside window',
    $r['overall_score'] === 54 && $r2['overall_score'] === 100,
    json_encode([$r['overall_score'], $r2['overall_score']]));

/* 17. Network graph: phone+email each shared with 4 DOTs -> two Review rules */
$r = run($c, [
    'config' => ['trustscore.network_checks' => true],
    'net' => ['phone' => 4, 'email' => 4, 'address' => 0, 'vin' => 0],
]);
check('shared phone+email x4 -> 2000 pts, Review',
    $r['v3']['risk_points'] === 2000 && $r['status'] === 'Review',
    json_encode([$r['v3']['risk_points'], $r['v3']['rules_fired']]));

/* 18. Thin shell: new DOT, no history, no SMS, no inspections */
$shell = carrier([
    'authorityHistory' => \collect([]), 'inspections' => \collect([]),
    'insuranceFilingsHistory' => \collect([]),
    'email_address' => 'fastfreight99@gmail.com',
]);
$r = run($c, ['carrier' => $shell, 'sms' => null, 'dotAge' => 0, 'mcs150Year' => (int) date('Y'), 'observedUnits' => 0, 'vehicleOosPct' => null, 'driverOosPct' => null]);
check('thin shell -> capped 65, Review, manual review',
    $r['overall_score'] === 65 && $r['status'] === 'Review' && $r['v3']['needs_manual_review'] === true,
    json_encode([$r['overall_score'], $r['v3']['score_caps'], $r['v3']['data_confidence']]));

/* 19. Violation-rate cap: per-BASIC sums can no longer exceed 100% */
$r = run($c, ['sms' => sms(['unsafe_driv_insp_w_viol' => 9, 'hos_driv_insp_w_viol' => 9, 'veh_maint_insp_w_viol' => 9])]);
check('violation rate capped at 1.0',
    $r['v3']['rules_fired'] !== [] && ($r['pillars']['inspection_quality']['parameters']['violation_rate'] ?? 99) <= 1.0,
    json_encode($r['pillars']['inspection_quality']['parameters'] ?? []));

/* 20. Legacy pillar shape intact */
$r = run($c);
$p = $r['pillars'];
check('pillar payload keeps the seven keys + shape',
    array_keys($p) === ['safety_roadside', 'identity_fraud', 'insurance_financial', 'authority_compliance', 'crash_history', 'inspection_quality', 'operations_experience']
    && isset($p['safety_roadside']['weight'], $p['safety_roadside']['score'], $p['safety_roadside']['status'], $p['safety_roadside']['deductions'], $p['safety_roadside']['parameters']),
    json_encode(array_keys($p)));

/* 21. INS-02 reads the filing in force, not the biggest one on record.
   A stale-but-uncancelled $1M filing sits alongside the live $300k one;
   ranking by amount would score the $1M and clear a carrier who is short. */
$r = run($c, [
    'carrier' => carrier(['insuranceFilings' => \collect([
        new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '01000', 'effective_date' => d(900), 'cancl_effective_date' => null]),
        new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '00300', 'effective_date' => d(30), 'cancl_effective_date' => null]),
    ])]),
    'auth' => auth(['bipd_file' => '00300']),
]);
check('latest BIPD filing wins over the largest -> 18 BIPD_INSUFFICIENT',
    $r['overall_score'] === 18 && collect($r['knockout']['reasons'])->contains(fn ($x) => $x['code'] === 'BIPD_INSUFFICIENT'),
    json_encode([$r['overall_score'], $r['knockout']]));

/* 22. SMS-MULTI replaces the per-BASIC Mediums rather than stacking on them. */
$r = run($c, ['sms' => sms(['unsafe_driv_measure' => 6.2, 'hos_driv_measure' => 5.5])]);
$ids = array_column($r['v3']['rules_fired'], 'id');
check('SMS-MULTI fires instead of the per-BASIC Mediums',
    in_array('SMS-MULTI', $ids, true)
    && ! collect($ids)->contains(fn ($id) => str_starts_with($id, 'SMS-') && $id !== 'SMS-MULTI')
    && $r['v3']['risk_points'] === 10000,
    json_encode([$ids, $r['v3']['risk_points']]));

/* 23. One BASIC over still reports that BASIC by name. */
$r = run($c, ['sms' => sms(['unsafe_driv_measure' => 6.2])]);
check('one BASIC over still names the BASIC',
    array_column($r['v3']['rules_fired'], 'id') === ['SMS-UNSAFE_DRIV'] && $r['v3']['risk_points'] === 250,
    json_encode(array_column($r['v3']['rules_fired'], 'id')));

/* 24. The shortlist's SQL inspection count is honoured in place of the
   relation, which that path deliberately leaves unloaded. */
$thin = carrier(['nbr_power_unit' => 3, 'inspections' => \collect([])]);
$r = run($c, ['carrier' => $thin, 'sms' => null, 'observedUnits' => 0, 'inspectionCount' => 9]);
check('passed inspection count drives OPS-11 with no inspections loaded',
    collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'OPS-11'),
    json_encode(array_column($r['v3']['rules_fired'], 'id')));

/* ---------------------------------------------------------------------
 | The four worked examples from the founder breakdown, §10. These pin the
 | end-to-end number, not just one rule, so a change anywhere in the chain
 | (points -> curve -> caps -> legacy shape) shows up here.
 * ------------------------------------------------------------------- */

/* 25. §10 A — the Rundlett test: established, clean, thin inspection file.
   Zero rules fire; 7 of 8 confidence inputs present (only '>=5 inspections'
   is false) => 0.88, which is above the 0.85 cap line, so nothing clips. */
$thinFile = ['insp_total' => 3, 'vehicle_insp_total' => 3, 'driver_insp_total' => 3,
    'unsafe_driv_insp_w_viol' => 0, 'hos_driv_insp_w_viol' => 0, 'driv_fit_insp_w_viol' => 0,
    'contr_subst_insp_w_viol' => 0, 'veh_maint_insp_w_viol' => 0];
$r = run($c, ['sms' => sms($thinFile), 'vehicleOosPct' => 0.0, 'driverOosPct' => 0.0]);
check('§10 A: clean carrier -> 100 / Preferred / Approved / A, conf 0.88 uncapped',
    $r['overall_score'] === 100 && $r['grade'] === 'A' && $r['status'] === 'Approved'
    && $r['v3']['band']['key'] === 'preferred' && $r['v3']['rules_fired'] === []
    && $r['v3']['data_confidence'] === 0.88 && $r['v3']['score_cap_applied'] === null,
    json_encode([$r['overall_score'], $r['grade'], $r['status'], $r['v3']['data_confidence'], $r['v3']['score_cap_applied']]));

/* 26. §10 B — 60-day authority caps at 65; the same carrier at day 10 caps
   at 45 with senior approval. One raw score, two different clips. */
$sixty = run($c, ['carrier' => carrier(['authorityHistory' => \collect([
    new Rec(['op_auth_type' => 'COMMON', 'original_action_desc' => 'GRANTED', 'disp_action_desc' => 'GRANTED', 'orig_served_date' => d(60)])])])]);
$ten = run($c, ['carrier' => carrier(['authorityHistory' => \collect([
    new Rec(['op_auth_type' => 'COMMON', 'original_action_desc' => 'GRANTED', 'disp_action_desc' => 'GRANTED', 'orig_served_date' => d(10)])])])]);
check('§10 B: day 60 -> 65 + documented_review, day 10 -> 45 + senior_approval',
    $sixty['overall_score'] === 65 && in_array('documented_review_required', $sixty['v3']['flags'], true)
    && $ten['overall_score'] === 45 && in_array('senior_approval_required', $ten['v3']['flags'], true),
    json_encode([$sixty['overall_score'], $sixty['v3']['flags'], $ten['overall_score'], $ten['v3']['flags']]));

/* 27. §10 C — pending cancellation (Review 1,000) plus one BASIC over
   (Medium 250) = 1,250 points => 53, and the broker sees exactly two lines. */
$r = run($c, [
    'carrier' => carrier(['insuranceFilings' => \collect([
        new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '01000', 'effective_date' => d(200), 'cancl_effective_date' => (new \DateTimeImmutable('+20 days'))->format('d-M-y')]),
    ])]),
    'sms' => sms(['unsafe_driv_measure' => 6.2]),
]);
$ids = array_column($r['v3']['rules_fired'], 'id');
sort($ids);
check('§10 C: INS-10 + SMS-UNSAFE_DRIV = 1250 pts -> 53, two rules listed',
    $r['v3']['risk_points'] === 1250 && $r['overall_score'] === 53
    && $r['v3']['status'] === 'Unacceptable-Review' && $r['v3']['band']['key'] === 'review_required'
    && $ids === ['INS-10', 'SMS-UNSAFE_DRIV'],
    json_encode([$r['v3']['risk_points'], $r['overall_score'], $ids]));

/* 28. §10 D — Conditional alone disqualifies: pillars empty, no override. */
$r = run($c, ['detail' => detail(['safety_rating' => 'C'])]);
check('§10 D: Conditional -> 18 / Rejected / F / disqualified, pillars empty',
    $r['overall_score'] === 18 && $r['status'] === 'Rejected' && $r['grade'] === 'F'
    && $r['v3']['band']['key'] === 'disqualified' && $r['pillars'] === []
    && $r['knockout']['triggered'] === true && $r['knockout']['cap_score'] === 18,
    json_encode([$r['overall_score'], $r['status'], $r['grade'], $r['pillars']]));

/* 29. §9 score fingerprints — the number alone should identify the cause. */
$fingerprints = [
    100 => ['nothing fired, full data', []],
    94  => ['exactly one Low', ['auth' => auth(['bond_req' => 'Y'])]],
    89  => ['exactly one Medium', ['sms' => sms(['unsafe_driv_measure' => 6.2])]],
    84  => ['authority row missing/unreadable', ['auth' => null]],
    54  => ['exactly one Review', ['carrier' => carrier(['insuranceFilings' => \collect([
                new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '01000', 'cancl_effective_date' => (new \DateTimeImmutable('+20 days'))->format('d-M-y')]),
            ])])]],
    18  => ['hard stop', ['detail' => detail(['safety_rating' => 'U'])]],
];
$bad = [];
foreach ($fingerprints as $expected => [$why, $opts]) {
    $got = run($c, $opts)['overall_score'];
    if ($got !== $expected) $bad[] = "{$why}: expected {$expected}, got {$got}";
}
check('§9 fingerprints: 100 / 94 / 89 / 84 / 54 / 18 land on their causes',
    $bad === [], json_encode($bad));

/* ---------------------------------------------------------------------
 | Boundary cases. The scenarios above prove a rule fires; these prove it
 | stops firing on the far side of the line, which is what actually pins a
 | threshold down. Each one was written against a surviving mutant.
 * ------------------------------------------------------------------- */

/* 30. The 30/90-day authority lines, from both sides. */
$ageCase = fn (int $days) => run($c, ['carrier' => carrier(['authorityHistory' => \collect([
    new Rec(['op_auth_type' => 'COMMON', 'original_action_desc' => 'GRANTED', 'disp_action_desc' => 'GRANTED', 'orig_served_date' => d($days)])])])]);
check('authority age: 29d -> 45, 30d -> 65, 89d -> 65, 95d -> uncapped 100',
    $ageCase(29)['overall_score'] === 45
    && $ageCase(30)['overall_score'] === 65
    && $ageCase(89)['overall_score'] === 65
    && $ageCase(95)['overall_score'] === 100,
    json_encode([$ageCase(29)['overall_score'], $ageCase(30)['overall_score'], $ageCase(89)['overall_score'], $ageCase(95)['overall_score']]));

/* 31. The 24-month crash window, just outside it. A fatal at ~30 months is
   old news: CR-01 must not fire, and the carrier scores clean. */
$justOut = carrier(['crashes' => \collect([new Rec(['report_date' => d(910), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false])])]);
$justIn = carrier(['crashes' => \collect([new Rec(['report_date' => d(700), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false])])]);
check('fatal crash: 700d (in window) -> 54, 910d (~30mo, out) -> 100',
    run($c, ['carrier' => $justIn, 'crashesTotal' => 1, 'crashFatalities' => 1])['overall_score'] === 54
    && run($c, ['carrier' => $justOut, 'crashesTotal' => 1, 'crashFatalities' => 1])['overall_score'] === 100,
    json_encode([run($c, ['carrier' => $justIn])['overall_score'], run($c, ['carrier' => $justOut])['overall_score']]));

/* 32. The 65% data-confidence line -> cap 70 (sheet §9: '~5 of 8 inputs').
   Five inputs present, three missing, and no rule fires: the 70 is purely
   the confidence clip, which is the fingerprint brokers are meant to read. */
$fiveOfEight = carrier(['authorityHistory' => \collect([])]);
$r = run($c, ['carrier' => $fiveOfEight, 'dotAge' => null, 'mcs150Year' => null]);
check('confidence 5/8 = 0.63 -> cap 70, no rules fired',
    $r['v3']['data_confidence'] === 0.63
    && $r['overall_score'] === 70
    && $r['v3']['rules_fired'] === []
    && ($r['v3']['score_cap_applied']['cap'] ?? null) === 70.0,
    json_encode([$r['v3']['data_confidence'], $r['overall_score'], $r['v3']['score_cap_applied'], array_column($r['v3']['rules_fired'], 'id')]));

/* 33. §3 / §6 — a half-read authority record caps at 84 even when the half
   that IS readable says active. Unknown is not a clearance, so an active
   common authority beside a blank contract status still lands in manual
   review rather than scoring clean. Both-readable is untouched. */
$authCase = fn (array $over) => run($c, ['auth' => auth($over)]);
$partial = $authCase(['contract_stat' => '']);
check('§3/§6: active common + blank contract -> 84 + authority_record_missing',
    $partial['overall_score'] === 84
    && in_array('authority_record_missing', $partial['v3']['flags'], true)
    && $partial['v3']['needs_manual_review'] === true
    && $partial['knockout']['triggered'] === false
    && $authCase(['common_stat' => 'A', 'contract_stat' => 'N'])['overall_score'] === 100
    && $authCase(['common_stat' => 'I', 'contract_stat' => ''])['overall_score'] === 84
    && $authCase(['common_stat' => 'I', 'contract_stat' => 'N'])['overall_score'] === 18,
    json_encode([$partial['overall_score'], $partial['v3']['flags'],
        $authCase(['common_stat' => 'I', 'contract_stat' => ''])['overall_score'],
        $authCase(['common_stat' => 'I', 'contract_stat' => 'N'])['overall_score']]));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
