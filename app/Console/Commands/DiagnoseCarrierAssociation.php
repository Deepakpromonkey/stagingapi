<?php

namespace App\Console\Commands;

use App\Http\Controllers\Carrier\CarrierController;
use App\Services\Carrier\CarrierChangeLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Why do these two carriers not show up as associated?
 *
 * Every answer to that question lives in data this machine can reach and no
 * developer's machine can: the census rows, the change log slice, whether the
 * index behind the gated matches was ever built. Rather than guess at which
 * column diverges, run this on the server that has them.
 *
 * It reports each identifier the association endpoint matches on, both
 * carriers' values for it bracketed so padding is visible, and whether the
 * endpoint's own predicate holds. Then it calls the endpoint and says outright
 * whether the second carrier came back.
 *
 *   php artisan carriers:diagnose-association 3647460 3266696
 *
 * @see \App\Http\Controllers\Carrier\CarrierController::association()
 */
class DiagnoseCarrierAssociation extends Command
{
    protected $signature = 'carriers:diagnose-association
                            {dot : The carrier whose profile is being viewed}
                            {other : The carrier expected to appear on it}';

    protected $description = 'Explain why two carriers do or do not associate';

    public function handle(): int
    {
        $dot = (string) $this->argument('dot');
        $other = (string) $this->argument('other');

        $a = $this->census($dot);
        $b = $this->census($other);

        foreach ([$dot => $a, $other => $b] as $number => $row) {
            if (! $row) {
                $this->error("DOT {$number} is not in company_census_file at all.");

                return self::FAILURE;
            }
        }

        $this->line('');
        $this->components->info("Census rows — brackets show padding");

        $columns = [
            'legal_name', 'dba_name', 'email_address', 'telephone', 'fax',
            'phy_street', 'phy_city', 'phy_state', 'phy_zip',
            'mailing_street', 'mailing_city', 'mailing_state', 'mailing_zip',
        ];

        $rows = [];

        foreach ($columns as $column) {
            $rows[] = [
                $column,
                '['.($a->{$column} ?? '').']',
                '['.($b->{$column} ?? '').']',
                $this->same($a->{$column} ?? null, $b->{$column} ?? null) ? 'same' : 'DIFFERS',
            ];
        }

        $this->table(['column', "DOT {$dot}", "DOT {$other}", ''], $rows);

        $this->components->info('Would the endpoint match on it?');

        $this->verdicts($a, $b);

        $this->components->info('Change log');

        $this->changeLog($dot, $other, $a, $b);

        $this->components->info('Index and gate behind former physical street matching');

        $this->line('  idx_phy_street built: '.($this->hasIndex('idx_phy_street') ? 'yes' : 'NO'));
        $this->line('  CARRIER_FORMER_PHY_ADDRESS_MATCHING: '.(
            config('carriers.former_physical_address_matching') ? 'true' : 'FALSE (former physical streets are not matched)'
        ));

        $this->components->info('What the endpoint actually returns');

        $this->endpoint($dot, $other);

        return self::SUCCESS;
    }

    /**
     * The same predicates buildAssociations() uses, evaluated in PHP so the
     * report says which one failed rather than only that nothing matched.
     */
    private function verdicts($a, $b): void
    {
        $checks = [
            'EMAIL' => $this->same($a->email_address, $b->email_address) && ! empty($a->email_address),
            'PHONE' => $this->same($a->telephone, $b->telephone) && ! empty($a->telephone),
            'FAX' => $this->same($a->fax, $b->fax) && ! empty($a->fax),
            'LEGAL NAME' => $this->same(trim((string) $a->legal_name), $b->legal_name) && ! empty($a->legal_name),
            'DBA NAME' => $this->same(trim((string) $a->dba_name), $b->dba_name) && ! empty($a->dba_name),
            'PHYSICAL ADDRESS' => ! empty($a->phy_street) && ! empty($a->phy_city) && ! empty($a->phy_state)
                && $this->same($a->phy_street, $b->phy_street)
                && $this->same($a->phy_city, $b->phy_city)
                && $this->same($a->phy_state, $b->phy_state),
            'MAILING ADDRESS' => ! empty($a->mailing_street) && ! empty($a->mailing_city) && ! empty($a->mailing_state)
                && $this->same($a->mailing_street, $b->mailing_street)
                && $this->same($a->mailing_city, $b->mailing_city)
                && $this->same($a->mailing_state, $b->mailing_state),
        ];

        foreach ($checks as $label => $matched) {
            $this->line(sprintf('  %-18s %s', $label, $matched ? 'MATCH' : 'no'));
        }

        // A physical address that agrees on street and city but not state, or
        // on street alone, is the shape of a data entry difference rather than
        // two different places - worth naming, because it reads as "same
        // address" to anyone looking at the two profiles.
        if (
            $this->same($a->phy_street, $b->phy_street) &&
            ! ($this->same($a->phy_city, $b->phy_city) && $this->same($a->phy_state, $b->phy_state))
        ) {
            $this->warn('  Physical streets agree but city/state do not — the match needs all three.');
        }

        if (
            ! $this->same($a->phy_street, $b->phy_street) &&
            $this->same($this->loosen($a->phy_street), $this->loosen($b->phy_street)) &&
            $this->same($a->phy_city, $b->phy_city)
        ) {
            $this->warn('  Physical streets differ only in punctuation/spacing — the match is exact and misses this.');
        }
    }

    /**
     * The census is a snapshot; the change log is not. An address the census
     * has not caught up with is the one case where two carriers plainly share
     * an address and no current-value query can see it.
     */
    private function changeLog(string $dot, string $other, $a, $b): void
    {
        $log = app(CarrierChangeLogService::class);

        if (! $log->isIndexed()) {
            $this->warn('  Not indexed on this machine — no former-value matching happens at all.');
            $this->line('  Run: php artisan carriers:index-change-log');

            return;
        }

        $pairs = [
            [$dot, $a, $other, $b],
            [$other, $b, $dot, $a],
        ];

        foreach ($pairs as [$subject, $subjectRow, $against, $againstRow]) {
            $former = $log->formerValues($subject, [
                'email_address' => $subjectRow->email_address,
                'telephone' => $subjectRow->telephone,
                'fax' => $subjectRow->fax,
                'legal_name' => $subjectRow->legal_name,
                'dba_name' => $subjectRow->dba_name,
                'phy_street' => $subjectRow->phy_street,
                'mailing_street' => $subjectRow->mailing_street,
            ]);

            if (empty($former)) {
                $this->line("  DOT {$subject} has no former values on record.");

                continue;
            }

            foreach ($former as $column => $values) {
                $current = $againstRow->{$column} ?? null;

                foreach ($values as $value) {
                    $hit = $this->same($value, $current);

                    $this->line(sprintf(
                        '  DOT %s former %-14s [%s]%s',
                        $subject,
                        $column,
                        $value,
                        $hit ? "  <== equals DOT {$against}'s current value" : ''
                    ));
                }
            }
        }
    }

    private function endpoint(string $dot, string $other): void
    {
        // The response is cached for 15 minutes and the deploy does not clear
        // it, so a diagnosis run straight after a deploy would otherwise read
        // the behaviour of the code that was just replaced.
        Cache::forget('carrier:associations:'.$dot);

        $payload = json_decode(
            app(CarrierController::class)->association($dot)->getContent(),
            true
        );

        $data = $payload['data'] ?? [];

        $mine = array_values(array_filter(
            $data,
            fn ($row) => (string) ($row['dot_number'] ?? '') === $other
        ));

        $this->line('  rows returned: '.count($data));
        $this->line('  truncated: '.(($payload['truncated'] ?? false) ? 'yes — some matches were dropped to fit the cap' : 'no'));

        if (empty($mine)) {
            $this->error("  DOT {$other} is NOT in the response.");

            return;
        }

        $this->info("  DOT {$other} IS in the response, on:");

        foreach ($mine as $row) {
            $this->line(sprintf('    %-24s [%s]', $row['match_type'] ?? '?', $row['matched_value'] ?? ''));
        }
    }

    private function census(string $dot)
    {
        return DB::connection('external_db')->selectOne('
            SELECT dot_number, legal_name, dba_name, telephone, fax, email_address,
                   phy_street, phy_city, phy_state, phy_zip,
                   mailing_street, mailing_city, mailing_state, mailing_zip
            FROM carriers
            WHERE dot_number = ?
            LIMIT 1
        ', [$dot]);
    }

    private function hasIndex(string $name): bool
    {
        $row = DB::connection('external_db')->selectOne('
            SELECT COUNT(*) AS n
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
        ', ['company_census_file', $name]);

        return (int) ($row->n ?? 0) > 0;
    }

    /** MySQL compares these case-insensitively and ignores trailing spaces. */
    private function same($left, $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return strcasecmp(rtrim((string) $left), rtrim((string) $right)) === 0;
    }

    /** Strip everything two data entry clerks could disagree about. */
    private function loosen($value): string
    {
        return preg_replace('/\s+/', ' ', trim(preg_replace('/[^A-Za-z0-9 ]/', '', (string) $value)));
    }
}
