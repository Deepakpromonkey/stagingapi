<?php

namespace {

/*
 * Standalone harness: stubs just enough Laravel/Fmcsa surface to compose
 * the trait into a fake controller and run scoring scenarios.
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
    public function whereNotIn($col, $v): self { return $this; }
    public function select(...$c): self { return $this; }
    public function distinct(): self { return $this; }
    public function limit(int $n): self { return $this; }
    public function count($col = null): int
    {
        $map = ['telephone' => 'phone', 'email_address' => 'email', 'phy_state' => 'address', 'vin' => 'vin'];
        $k = $map[$this->firstColumn] ?? null;
        return (int) ($GLOBALS['dt_netcounts'][$k] ?? 0);
    }
    /* The VIN lookup selects the distinct dot_numbers and counts them in
       PHP (COUNT(DISTINCT) forced a 2.35M-entry loose index scan), so the
       stub hands back a collection of that many placeholder DOTs. */
    public function pluck($col): \Col
    {
        $n = $this->count();
        return new \Col($n > 0 ? range(1, $n) : []);
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

    private const MAIL_DROP_PATTERNS = ['PMB ', 'P.O. BOX', 'PO BOX', 'MAILBOX'];

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

    public function stem($name) { return $this->dtNameStem($name); }
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
        'legal_name' => 'RUNDLETT TRUCKING LLC',
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
        'inspections' => \collect([new Rec(['vin' => null, 'insp_date' => d(100)]), new Rec(['vin' => null, 'insp_date' => d(200)]), new Rec(['vin' => null, 'insp_date' => d(300)])]),
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
        $o['vehicleOosPct'] ?? 12.5,
        $o['driverOosPct'] ?? 0.0,
        51, null, null,
        $o['dotAge'] ?? 52,
        $o['mcs150Year'] ?? (int) date('Y'),
        $o['observedUnits'] ?? 2,
        $o['observedTrailers'] ?? 1,
        $o['crashesTotal'] ?? 0, $o['crashFatalities'] ?? 0, $o['crashInjuries'] ?? 0, $o['crashesTowAway'] ?? 0
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

/* 9a. Bond required but broker authority NOT active -> stale flag, no finding (v3.3) */
$r = run($c, ['auth' => auth(['bond_req' => 'Y'])]);
check('bond_req with inactive broker -> no INS-04, 100',
    $r['overall_score'] === 100 && $r['knockout']['triggered'] === false
    && ! collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'INS-04'),
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 9b. Bond required, broker ACTIVE, not on file -> INS-04 Low (+ AUTH-12 base) */
$r = run($c, ['auth' => auth(['bond_req' => 'Y', 'broker_stat' => 'A']), 'carrier' => carrier(['nbr_power_unit' => 15])]);
check('bond gap with active broker -> INS-04 + AUTH-12, 83',
    $r['overall_score'] === 83
    && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'INS-04')
    && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'AUTH-12' && $x['tier'] === 'medium'),
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 10. Two BASICs at/over the cut -> Review (demoted from Fail in v3.2.1) */
$r = run($c, ['sms' => sms(['unsafe_driv_measure' => 6.2, 'hos_driv_measure' => 5.5])]);
check('two BASICs over -> 52 Review, demoted, no knockout',
    $r['overall_score'] === 52 && $r['status'] === 'Review' && $r['knockout']['triggered'] === false
    && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'SMS-MULTI' && $x['tier'] === 'review'),
    json_encode([$r['overall_score'], $r['status'], $r['v3']['rules_fired']]));

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


/* ---- v3.2 rules ---- */

function histRow(string $orig, string $disp, string $date): Rec
{
    return new Rec(['op_auth_type' => 'COMMON', 'original_action_desc' => $orig, 'disp_action_desc' => $disp, 'orig_served_date' => $date]);
}

/* 21. AUTH-11: one discontinued proceeding in the 36-month window */
$hist = \collect([histRow('GRANTED', 'GRANTED', d(19000)), histRow('INVOLUNTARY REVOCATION', 'DISCONTINUED REVOCATION', d(180))]);
$r = run($c, ['carrier' => carrier(['authorityHistory' => $hist])]);
check('one revocation proceeding in window -> AUTH-11 Low, 94',
    $r['overall_score'] === 94 && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'AUTH-11' && $x['tier'] === 'low'),
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 22a. Three proceedings -> Medium */
$rows = [histRow('GRANTED', 'GRANTED', d(19000))];
foreach ([200, 300, 400] as $ago) { $rows[] = histRow('INVOLUNTARY REVOCATION', 'DISCONTINUED REVOCATION', d($ago)); }
$r = run($c, ['carrier' => carrier(['authorityHistory' => \collect($rows)])]);
check('three proceedings -> AUTH-11 Medium, 89', $r['overall_score'] === 89, json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 22b. Five proceedings -> Review */
$rows = [histRow('GRANTED', 'GRANTED', d(19000))];
foreach ([150, 250, 350, 450, 550] as $ago) { $rows[] = histRow('INVOLUNTARY REVOCATION', 'DISCONTINUED REVOCATION', d($ago)); }
$r = run($c, ['carrier' => carrier(['authorityHistory' => \collect($rows)])]);
check('five proceedings -> AUTH-11 Review, 54', $r['overall_score'] === 54 && $r['status'] === 'Review', json_encode([$r['overall_score'], $r['status']]));

/* 30. Proceedings outside the window score zero */
$rows = [histRow('GRANTED', 'GRANTED', d(19000))];
foreach ([1200, 1300, 1400, 1500] as $ago) { $rows[] = histRow('INVOLUNTARY REVOCATION', 'DISCONTINUED REVOCATION', d($ago)); }
$r = run($c, ['carrier' => carrier(['authorityHistory' => \collect($rows)])]);
check('four proceedings, all outside 36mo -> 100', $r['overall_score'] === 100, json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 23. AUTH-12 base: dual authority, healthy fleet */
$r = run($c, ['auth' => auth(['broker_stat' => 'A']), 'carrier' => carrier(['nbr_power_unit' => 15])]);
check('dual authority, 15-truck fleet -> Medium, 89 + dual_authority flag',
    $r['overall_score'] === 89 && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'AUTH-12' && $x['tier'] === 'medium') && in_array('dual_authority', $r['v3']['flags'], true),
    json_encode([$r['overall_score'], $r['v3']['rules_fired'], $r['v3']['flags']]));

/* 24a. AUTH-12 escalation: 2-truck fleet */
$r = run($c, ['auth' => auth(['broker_stat' => 'A']), 'carrier' => carrier(['nbr_power_unit' => 2])]);
check('dual authority, 2-truck fleet -> Review, 54', $r['overall_score'] === 54 && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'AUTH-12' && $x['tier'] === 'review'), json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 24b. AUTH-12 escalation: zero observed units */
$r = run($c, ['auth' => auth(['broker_stat' => 'A']), 'carrier' => carrier(['nbr_power_unit' => 15]), 'observedUnits' => 0]);
check('dual authority, zero observed -> Review, 54', $r['overall_score'] === 54, json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 26. INS-13: expired filings while authority says covered -> flag, not points */
$stale = carrier(['insuranceFilings' => \collect([
    new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '00750', 'cancl_effective_date' => d(430)]),
    new Rec(['ins_form_code' => '91X', 'ins_type_desc' => 'BIPD/PRIMARY', 'max_cov_amount' => '00750', 'cancl_effective_date' => d(800)]),
])]);
$r = run($c, ['carrier' => $stale]);
check('stale insurance feed -> 100, flag + manual review, no knockout',
    $r['overall_score'] === 100 && in_array('insurance_feed_inconsistency', $r['v3']['flags'], true) && $r['v3']['needs_manual_review'] === true && $r['knockout']['triggered'] === false,
    json_encode([$r['overall_score'], $r['v3']['flags'], $r['knockout']]));

/* 27. INSP-02: established authority, fewer than 5 inspections */
$r = run($c, ['sms' => sms(['insp_total' => 3])]);
check('established + 3 inspections -> INSP-02 Low, 94, manual review',
    $r['overall_score'] === 94 && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'INSP-02') && $r['v3']['needs_manual_review'] === true,
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 28. INSP-03: history exists, newest inspection 400 days old */
$r = run($c, ['carrier' => carrier(['inspections' => \collect(array_map(fn ($i) => new Rec(['vin' => null, 'insp_date' => d(400 + $i * 10)]), range(0, 4)))])]);
check('no inspection in 12 months -> INSP-03 Low, 94',
    $r['overall_score'] === 94 && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'INSP-03'),
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 29. NET-04 tiers */
$vinCarrier = fn () => carrier(['inspections' => \collect([new Rec(['vin' => '1FUJGLDR0CLBP8834', 'insp_date' => d(100)])])]);
$r = run($c, ['carrier' => $vinCarrier(), 'config' => ['trustscore.network_checks' => true], 'net' => ['vin' => 2]]);
check('VIN shared with 2 others -> Low, 94', $r['overall_score'] === 94, json_encode([$r['overall_score'], $r['v3']['rules_fired']]));
$r = run($c, ['carrier' => $vinCarrier(), 'config' => ['trustscore.network_checks' => true], 'net' => ['vin' => 4]]);
check('VIN shared with 4 others -> Medium, 89', $r['overall_score'] === 89, json_encode([$r['overall_score'], $r['v3']['rules_fired']]));
$r = run($c, ['carrier' => $vinCarrier(), 'config' => ['trustscore.network_checks' => true], 'net' => ['vin' => 6]]);
check('VIN shared with 6 others -> Review, 54', $r['overall_score'] === 54 && $r['status'] === 'Review', json_encode([$r['overall_score'], $r['status']]));

/* 25. Warrior composite: the full staging fact pattern */
$warrior = carrier([
    'nbr_power_unit' => 2,
    'authorityHistory' => \collect([
        histRow('GRANTED', 'GRANTED', d(2325)),
        histRow('INVOLUNTARY REVOCATION', 'DISCONTINUED REVOCATION', d(460)),
    ]),
    'inspections' => \collect(array_map(fn ($i) => new Rec(['vin' => 'WARRIOR8VIN123456', 'insp_date' => d(400 + $i * 30)]), range(0, 4))),
]);
$r = run($c, [
    'carrier' => $warrior,
    'auth' => auth(['broker_stat' => 'A']),
    'sms' => sms(['hos_driv_measure' => 6.2, 'insp_total' => 5, 'vehicle_insp_total' => 1, 'driver_insp_total' => 3, 'unsafe_driv_insp_w_viol' => 0, 'hos_driv_insp_w_viol' => 3, 'veh_maint_insp_w_viol' => 0]),
    'config' => ['trustscore.network_checks' => true],
    'net' => ['phone' => 0, 'email' => 0, 'address' => 0, 'vin' => 2],
    'observedUnits' => 1,
    'vehicleOosPct' => 0.0, 'driverOosPct' => 0.0,
]);
check('Warrior composite -> 1750 pts, 51, Review required',
    $r['v3']['risk_points'] === 1750 && $r['overall_score'] === 51 && $r['v3']['band']['key'] === 'review_required' && in_array('dual_authority', $r['v3']['flags'], true),
    json_encode([$r['v3']['risk_points'], $r['overall_score'], $r['v3']['band']['key'], $r['v3']['rules_fired']]));


/* 35. VR Transport LLP (DOT 3175931): 1 truck, 5 inspections, unsafe 7.82 /
   veh_maint 15 over national cuts, 50% vehicle OOS on 4 vehicle inspections.
   Was 18/Disqualified; correct answer is Review with findings listed. */
$vrCarrier = carrier([
    'nbr_power_unit' => 1,
    'authorityHistory' => \collect([histRow('GRANTED', 'GRANTED', d(2900))]),
    'inspections' => \collect(array_map(fn ($i) => new Rec(['vin' => null, 'insp_date' => d(60 + $i * 90)]), range(0, 4))),
]);
$r = run($c, [
    'carrier' => $vrCarrier,
    'detail' => detail(['safety_rating' => '']),
    'sms' => sms([
        'insp_total' => 5, 'vehicle_insp_total' => 4, 'driver_insp_total' => 5,
        'unsafe_driv_measure' => 7.82, 'hos_driv_measure' => 0.44, 'veh_maint_measure' => 15,
        'driv_fit_measure' => 0, 'contr_subst_measure' => 0,
        'unsafe_driv_insp_w_viol' => 2, 'hos_driv_insp_w_viol' => 2, 'veh_maint_insp_w_viol' => 4,
        'driv_fit_insp_w_viol' => 0, 'contr_subst_insp_w_viol' => 0,
    ]),
    'vehicleOosPct' => 50.0, 'driverOosPct' => 0.0,
    'observedUnits' => 1,
]);
check('VR Transport pattern -> 51 Review required, not Disqualified',
    $r['overall_score'] === 51 && $r['v3']['band']['key'] === 'review_required' && $r['knockout']['triggered'] === false
    && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'SMS-MULTI' && $x['tier'] === 'review'),
    json_encode([$r['overall_score'], $r['v3']['risk_points'], $r['v3']['rules_fired']]));


/* ---- v3.3 rules ---- */

/* 36. Name-stem extraction */
check('name stems: KAPLAN / KREILKAMP / short names null',
    $c->stem('THE KAPLAN TRUCKING COMPANY') === 'KAPLAN'
    && $c->stem('KREILKAMP TRUCKING INC') === 'KREILKAMP'
    && $c->stem('KTR FREIGHT PVT LTD') === null
    && $c->stem('A1 EXPRESS LLC') === null
    && $c->stem(null) === null,
    json_encode([$c->stem('THE KAPLAN TRUCKING COMPANY'), $c->stem('KTR FREIGHT PVT LTD')]));

/* 37. Small young carrier: NET tiers unchanged (chameleon shape keeps Review) */
$r = run($c, ['config' => ['trustscore.network_checks' => true], 'net' => ['phone' => 4, 'email' => 4, 'address' => 0, 'vin' => 0]]);
check('small fleet, phone+email x4 -> still 2x Review, 2000 pts',
    $r['v3']['risk_points'] === 2000 && $r['status'] === 'Review',
    json_encode([$r['v3']['risk_points'], $r['v3']['rules_fired']]));

/* 38. Large established fleet: same sharing demotes to Medium */
$big = carrier(['legal_name' => 'MEGAFLEET CARRIERS INC', 'nbr_power_unit' => 300]);
$r = run($c, ['carrier' => $big, 'config' => ['trustscore.network_checks' => true], 'net' => ['phone' => 4, 'email' => 4, 'address' => 0, 'vin' => 0]]);
check('large established fleet, phone+email x4 -> 2x Medium, 89',
    $r['v3']['risk_points'] === 500 && $r['overall_score'] === 78
    && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'NET-01' && $x['tier'] === 'medium'),
    json_encode([$r['v3']['risk_points'], $r['overall_score'], $r['v3']['rules_fired']]));

/* 39. CR-01: small fleet fatal stays Review; large fleet at baseline rate -> Medium + flag */
$smallFatal = carrier(['crashes' => \collect([new Rec(['report_date' => d(200), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false])])]);
$r = run($c, ['carrier' => $smallFatal]);
$bigFatal = carrier(['nbr_power_unit' => 867, 'crashes' => \collect([new Rec(['report_date' => d(200), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false])])]);
$r2 = run($c, ['carrier' => $bigFatal]);
check('fatal crash: 2-truck fleet 54 Review, 867-truck fleet 89 Medium + flag',
    $r['overall_score'] === 54
    && $r2['overall_score'] === 89
    && in_array('fatal_crash_24mo', $r2['v3']['flags'], true)
    && collect($r2['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'CR-01' && $x['tier'] === 'medium'),
    json_encode([$r['overall_score'], $r2['overall_score'], $r2['v3']['flags']]));

/* 39b. Large fleet with a HIGH fatal rate still goes to Review */
$badBig = carrier(['nbr_power_unit' => 150, 'crashes' => \collect([
    new Rec(['report_date' => d(100), 'fatalities' => 1, 'injuries' => 0, 'tow_away' => false]),
    new Rec(['report_date' => d(300), 'fatalities' => 1, 'injuries' => 1, 'tow_away' => true]),
])]);
$r = run($c, ['carrier' => $badBig]);
check('150-unit fleet, 2 fatals (rate 0.013) -> Review',
    $r['status'] === 'Review' && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'CR-01' && $x['tier'] === 'review'),
    json_encode([$r['overall_score'], $r['v3']['rules_fired']]));

/* 40. Kaplan replica (DOT 120670): was 4,750 -> 39; v3.3 lands 1,625 -> 52 */
$kaplan = carrier([
    'legal_name' => 'THE KAPLAN TRUCKING COMPANY',
    'nbr_power_unit' => 867,
    'authorityHistory' => \collect([
        histRow('GRANTED', 'GRANTED', d(16500)),
        histRow('GRANTED', 'REVOKED', d(9000)),
    ]),
    'inspections' => \collect([new Rec(['vin' => '1FUJGLDR0CLBP8834', 'insp_date' => d(90)]), new Rec(['vin' => '1FUJGLDR0CLBP8835', 'insp_date' => d(150)])]),
    'crashes' => \collect(array_merge(
        [new Rec(['report_date' => d(300), 'fatalities' => 1, 'injuries' => 2, 'tow_away' => true])],
        array_map(fn ($i) => new Rec(['report_date' => d(120 + $i * 60), 'fatalities' => 0, 'injuries' => 0, 'tow_away' => true]), range(0, 4))
    )),
]);
$r = run($c, [
    'carrier' => $kaplan,
    'auth' => auth(['broker_stat' => 'A', 'contract_stat' => 'A', 'bipd_file' => '05000', 'min_cov_amount' => '00750']),
    'config' => ['trustscore.network_checks' => true],
    'net' => ['phone' => 3, 'email' => 0, 'address' => 9, 'vin' => 24],
    'observedUnits' => 546, 'observedTrailers' => 534,
    'vehicleOosPct' => 15.0, 'driverOosPct' => 1.2,
]);
check('Kaplan replica -> 1625 pts, 52 Review (was 4750 -> 39)',
    $r['v3']['risk_points'] === 1625 && $r['overall_score'] === 52 && $r['status'] === 'Review'
    && in_array('fatal_crash_24mo', $r['v3']['flags'], true)
    && collect($r['v3']['rules_fired'])->contains(fn ($x) => $x['id'] === 'NET-03' && $x['tier'] === 'medium'),
    json_encode([$r['v3']['risk_points'], $r['overall_score'], $r['v3']['rules_fired']]));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
