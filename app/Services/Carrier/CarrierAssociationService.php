<?php

namespace App\Services\Carrier;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Company associations and equipment insights: other carriers that share an
 * identifier (phone, email, address, name) or a vehicle (VIN) with this one.
 *
 * These are the double-brokering / shell-company signals, so matches are
 * grouped by what was shared rather than returned as a flat list.
 */
class CarrierAssociationService
{
    /** Hard cap so a carrier on a shared mail-drop cannot return thousands of rows. */
    protected const MAX_MATCHES = 200;

    /**
     * Carriers sharing contact details, name or address with this one.
     */
    public function associations(string $dot): ?array
    {
        return Cache::remember(
            "carrier:associations:{$dot}",
            (int) config('carriers.profile_cache_ttl', 900),
            fn () => $this->toPlainArray($this->buildAssociations($dot))
        );
    }

    protected function buildAssociations(string $dot): ?array
    {
        $carrier = DB::connection('external_db')->selectOne('
            SELECT dot_number, legal_name, dba_name, telephone, fax, email_address,
                   phy_street, phy_city, phy_state, phy_zip,
                   mailing_street, mailing_city, mailing_state, mailing_zip
            FROM carriers WHERE dot_number = ? LIMIT 1
        ', [$dot]);

        if (! $carrier) {
            return null;
        }

        $blocks = [];
        $bindings = [];

        $add = function (string $label, string $where, array $values) use (&$blocks, &$bindings, $dot) {
            $blocks[] = $this->matchBlock($label, $where);

            array_push($bindings, ...$values);

            $bindings[] = $dot;
        };

        foreach ([
            'EMAIL' => ['email_address = ?', [$carrier->email_address]],
            'PHONE' => ['telephone = ?', [$carrier->telephone]],
            'FAX' => ['fax = ?', [$carrier->fax]],
            'LEGAL NAME' => ['legal_name = ?', [trim((string) $carrier->legal_name)]],
            'DBA NAME' => ['dba_name = ?', [trim((string) $carrier->dba_name)]],
        ] as $label => [$where, $values]) {
            if (! empty($values[0])) {
                $add($label, $where, $values);
            }
        }

        if ($carrier->phy_street && $carrier->phy_city && $carrier->phy_state && $carrier->phy_zip) {
            $add('PHYSICAL ADDRESS', 'phy_street = ? AND phy_city = ? AND phy_state = ? AND phy_zip = ?', [
                $carrier->phy_street, $carrier->phy_city, $carrier->phy_state, $carrier->phy_zip,
            ]);
        }

        if ($carrier->mailing_street && $carrier->mailing_city && $carrier->mailing_state && $carrier->mailing_zip) {
            $add('MAILING ADDRESS', 'mailing_street = ? AND mailing_city = ? AND mailing_state = ? AND mailing_zip = ?', [
                $carrier->mailing_street, $carrier->mailing_city, $carrier->mailing_state, $carrier->mailing_zip,
            ]);
        }

        if (empty($blocks)) {
            return $this->emptyResult($dot);
        }

        $sql = implode(' UNION ALL ', $blocks)
            .' ORDER BY legal_name, dot_number LIMIT '.self::MAX_MATCHES;

        $matches = DB::connection('external_db')->select($sql, $bindings);

        return $this->group($dot, $matches, 'match_type');
    }

    /**
     * Carriers inspected on the same vehicles — shared equipment.
     */
    public function equipment(string $dot): array
    {
        return Cache::remember(
            "carrier:equipment:{$dot}",
            (int) config('carriers.profile_cache_ttl', 900),
            function () use ($dot) {
                $matches = DB::connection('external_db')->select('
                    WITH target_vins AS (
                        SELECT DISTINCT vin FROM inspections
                        WHERE dot_number = ? AND vin IS NOT NULL AND vin <> ""
                    )
                    SELECT
                        "VIN" AS match_type,
                        tv.vin AS matched_vin,
                        i.dot_number,
                        c.legal_name,
                        c.dba_name,
                        c.telephone,
                        c.fax,
                        c.email_address,
                        COUNT(*) AS inspection_count,
                        MAX(i.insp_date) AS last_inspection_date
                    FROM target_vins tv
                    JOIN inspections i ON i.vin = tv.vin AND i.dot_number <> ?
                    JOIN carriers c ON c.dot_number = i.dot_number
                    GROUP BY tv.vin, i.dot_number, c.legal_name, c.dba_name, c.telephone, c.fax, c.email_address
                    ORDER BY inspection_count DESC, c.legal_name
                    LIMIT '.self::MAX_MATCHES.'
                ', [$dot, $dot]);

                $result = $this->group($dot, $matches, 'matched_vin');

                $result['shared_vin_count'] = count($result['groups']);

                return $this->toPlainArray($result);
            }
        );
    }

    /**
     * One SELECT per identifier type, UNION ALL'd into a single statement.
     */
    protected function matchBlock(string $label, string $where): string
    {
        return "
            SELECT
                '{$label}' AS match_type,
                dot_number,
                legal_name,
                dba_name,
                telephone,
                fax,
                email_address,
                phy_city,
                phy_state,
                (SELECT docket_number FROM carrier_authorities ca
                  WHERE ca.dot_number = carriers.dot_number LIMIT 1) AS mc_number,
                (SELECT dun_bradstreet_no FROM carrier_details cd
                  WHERE cd.dot_number = carriers.dot_number LIMIT 1) AS duns_number
            FROM carriers
            WHERE {$where} AND dot_number <> ?
        ";
    }

    protected function group(string $dot, array $matches, string $key): array
    {
        $groups = collect($matches)
            ->groupBy($key)
            ->map(fn ($rows, $name) => [
                'match_type' => $name,
                'count' => $rows->count(),
                'carriers' => $rows->values(),
            ])
            ->values();

        return [
            'dot_number' => $dot,
            'count' => count($matches),
            'truncated' => count($matches) >= self::MAX_MATCHES,
            'groups' => $groups,
            'data' => $matches,
        ];
    }

    protected function emptyResult(string $dot): array
    {
        return [
            'dot_number' => $dot,
            'count' => 0,
            'truncated' => false,
            'groups' => [],
            'data' => [],
        ];
    }

    /**
     * Query results are stdClass rows and Collections; the cache can only
     * return scalars and arrays intact.
     */
    protected function toPlainArray(?array $payload): ?array
    {
        return $payload === null ? null : json_decode(json_encode($payload), true);
    }
}
