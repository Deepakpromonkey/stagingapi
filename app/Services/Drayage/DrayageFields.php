<?php

namespace App\Services\Drayage;

/**
 * The drayage field dictionary: one entry per column of the Drayage Carrier
 * Finder export, in export order.
 *
 * Everything else reads from here - the CSV header mapping, normalization,
 * the nested document sections, what the list endpoint can filter and sort
 * on, the export's column labels, and GET /drayage/fields, which hands the
 * same list to the frontend so its filters are built from data rather than
 * hardcoded. Adding a column is one line here.
 *
 * Types: text (free text), string (identifier - never cast to a number, so
 * leading zeros survive), enum, int, money, bool (tri-state: true / false /
 * null for "not stated"), date (YYYY-MM-DD), list (joined with "; " in CSV).
 */
class DrayageFields
{
    public const GROUPS = [
        'company' => 'Company',
        'location' => 'Location & coverage',
        'authority' => 'Authority & insurance',
        'drayage' => 'Drayage services',
        'special' => 'Special cargo & services',
        'fleet' => 'Drivers & power units',
        'chassis' => 'Chassis & trailers',
        'contact' => 'Contact & profile',
    ];

    /**
     * Nested sections of a carrier document, in the order they appear in it.
     * `meta` and `extra` are filled by the importer, not from a column.
     */
    public const SECTIONS = [
        'meta', 'identity', 'location', 'coverage', 'authority', 'insurance',
        'compliance', 'drayage', 'special_cargo', 'fleet', 'equipment',
        'contact', 'profile_dates', 'extra',
    ];

    /**
     * Source columns that are deliberately not kept: the LoadMatch profile and
     * directory page links. The parsers drop them rather than filing them
     * under `extra`, so they appear nowhere in the API or the export.
     */
    public const DROPPED = ['profile url', 'directory url', 'profile_url', 'directory_url'];

    public const RECORD_TYPES = [
        'full' => 'Full profile',
        'listing' => 'Directory listing',
    ];

    /**
     * label => [key, type, group, section]
     */
    private const DICTIONARY = [
        // Company
        'Company' => ['company_name', 'text', 'company', 'identity'],
        'Record type' => ['record_type', 'enum', 'company', 'meta'],
        'Legal name' => ['legal_name', 'text', 'company', 'identity'],
        'Also known as' => ['alt_name', 'text', 'company', 'identity'],
        'DBA of' => ['dba_of', 'text', 'company', 'identity'],
        'Division of' => ['division_of', 'text', 'company', 'identity'],
        'Description' => ['description', 'text', 'company', 'identity'],
        'Profile completeness %' => ['completeness', 'int', 'company', 'meta'],

        // Location & coverage
        'Directory metros' => ['metros', 'list', 'location', 'location'],
        'Metros listed in' => ['listed_metros', 'int', 'location', 'location'],
        'Metro codes' => ['city_codes', 'list', 'location', 'location'],
        'Street' => ['street', 'text', 'location', 'location'],
        'HQ city' => ['hq_city', 'text', 'location', 'location'],
        'HQ state/prov.' => ['hq_state', 'text', 'location', 'location'],
        'HQ ZIP/postal' => ['hq_zip', 'string', 'location', 'location'],
        'HQ country' => ['hq_country', 'text', 'location', 'location'],
        'States served' => ['states_served', 'list', 'location', 'coverage'],
        'Provinces served' => ['provinces_served', 'list', 'location', 'coverage'],
        'Nationwide (48 states)' => ['nationwide', 'bool', 'location', 'coverage'],
        'U.S.–Canada cross-border' => ['cross_border_canada', 'bool', 'location', 'coverage'],
        'U.S.–Mexico cross-border' => ['cross_border_mexico', 'bool', 'location', 'coverage'],
        'Ingate / outgate' => ['ingate', 'list', 'location', 'coverage'],
        'Terminals in' => ['terminals', 'list', 'location', 'coverage'],

        // Authority & insurance
        'SCAC' => ['scac', 'string', 'authority', 'authority'],
        'MC #' => ['mc', 'string', 'authority', 'authority'],
        'USDOT #' => ['usdot', 'string', 'authority', 'authority'],
        'USDOT authority date' => ['authority_date', 'date', 'authority', 'authority'],
        'Established' => ['established', 'int', 'authority', 'authority'],
        'Years in business' => ['years_in_business', 'int', 'authority', 'authority'],
        'Cargo insurance' => ['cargo_insurance', 'money', 'authority', 'insurance'],
        'Trailer interchange' => ['trailer_interchange', 'money', 'authority', 'insurance'],
        'Warehouse insurance' => ['warehouse_insurance', 'bool', 'authority', 'insurance'],
        'Customs bonded' => ['customs_bonded', 'bool', 'authority', 'compliance'],
        'Customs bond #' => ['bond_number', 'string', 'authority', 'compliance'],
        'C-TPAT' => ['ctpat', 'bool', 'authority', 'compliance'],
        'TSA / airport approved' => ['tsa_approved', 'bool', 'authority', 'compliance'],
        'IANA member' => ['iana', 'bool', 'authority', 'compliance'],
        'SmartWay' => ['smartway', 'bool', 'authority', 'compliance'],
        'Memberships (text)' => ['member_of', 'text', 'authority', 'compliance'],
        'FIRMS code' => ['firms_code', 'string', 'authority', 'compliance'],
        'Federal ID' => ['fed_id', 'string', 'authority', 'authority'],
        'NSC # (Canada)' => ['nsc', 'string', 'authority', 'authority'],
        'CVOR # (Ontario)' => ['cvor', 'string', 'authority', 'authority'],
        'NIR # (Québec)' => ['nir', 'string', 'authority', 'authority'],
        'Freight forwarder #' => ['freight_forwarder', 'string', 'authority', 'authority'],
        'NVOCC #' => ['nvocc', 'string', 'authority', 'authority'],
        'Accepts credit cards' => ['accept_credit_cards', 'bool', 'authority', 'compliance'],

        // Drayage services
        '20′ containers' => ['size_20', 'bool', 'drayage', 'drayage'],
        '40′ containers' => ['size_40', 'bool', 'drayage', 'drayage'],
        '45′ containers' => ['size_45', 'bool', 'drayage', 'drayage'],
        '53′ containers' => ['size_53', 'bool', 'drayage', 'drayage'],
        'Ocean port drayage' => ['ocean_port', 'bool', 'drayage', 'drayage'],
        'Rail ramp drayage' => ['rail_ramp', 'bool', 'drayage', 'drayage'],
        'Dry container' => ['dry_container', 'bool', 'drayage', 'drayage'],
        'Reefer drayage' => ['reefer_drayage', 'bool', 'drayage', 'drayage'],
        'Reefer details' => ['reefer_detail', 'text', 'drayage', 'drayage'],
        'Reefer breakdown' => ['reefer_breakdown', 'text', 'drayage', 'drayage'],
        'Open top' => ['open_top', 'bool', 'drayage', 'drayage'],
        'Flat rack' => ['flat_rack', 'bool', 'drayage', 'drayage'],
        'Out-of-gauge (OOG)' => ['oog', 'bool', 'drayage', 'drayage'],
        'Tank-endorsed drivers' => ['tank_endorsed', 'bool', 'drayage', 'drayage'],
        'ISO tank (loaded)' => ['iso_tank', 'bool', 'drayage', 'drayage'],

        // Special cargo & services
        'Hazmat' => ['hazmat', 'bool', 'special', 'special_cargo'],
        'Hazmat classes / notes' => ['hazmat_detail', 'text', 'special', 'special_cargo'],
        'Overweight permit' => ['overweight', 'bool', 'special', 'special_cargo'],
        'Overweight max (lbs)' => ['overweight_max_lbs', 'int', 'special', 'special_cargo'],
        'Overweight notes' => ['overweight_detail', 'text', 'special', 'special_cargo'],
        'Liquor / alcohol' => ['liquor', 'bool', 'special', 'special_cargo'],
        'Household goods' => ['household_goods', 'bool', 'special', 'special_cargo'],
        'Residential delivery' => ['residential', 'bool', 'special', 'special_cargo'],
        'Amazon' => ['amazon', 'bool', 'special', 'special_cargo'],
        'Menards approved' => ['menards', 'bool', 'special', 'special_cargo'],
        'Transload service' => ['transload', 'bool', 'special', 'special_cargo'],
        'Chains / binders' => ['chains_binders', 'bool', 'special', 'special_cargo'],
        'CY depot' => ['cy_depot', 'bool', 'special', 'special_cargo'],
        'Languages' => ['languages', 'list', 'special', 'special_cargo'],

        // Drivers & power units
        'Drivers (approx.)' => ['drivers_approx', 'int', 'fleet', 'fleet'],
        'Company drivers' => ['company_drivers', 'int', 'fleet', 'fleet'],
        'Owner-operators' => ['owner_operators', 'int', 'fleet', 'fleet'],
        'Owner-operator %' => ['owner_op_pct', 'int', 'fleet', 'fleet'],
        'FMCSA SAFER driver count' => ['fmcsa_drivers', 'int', 'fleet', 'fleet'],
        'MCS-150 mileage' => ['mcs150_miles', 'int', 'fleet', 'fleet'],
        'MCS-150 year' => ['mcs150_year', 'int', 'fleet', 'fleet'],
        'Inspections (2 yrs)' => ['inspections_2yr', 'int', 'fleet', 'fleet'],
        'TWIC' => ['twic', 'bool', 'fleet', 'fleet'],
        'ELD' => ['eld', 'bool', 'fleet', 'fleet'],
        'Day cabs' => ['day_cabs', 'bool', 'fleet', 'fleet'],
        'Natural gas trucks' => ['natural_gas', 'bool', 'fleet', 'fleet'],
        'Battery-electric trucks' => ['battery_electric', 'bool', 'fleet', 'fleet'],
        'Hydrogen / fuel-cell trucks' => ['hydrogen', 'bool', 'fleet', 'fleet'],
        'Straight box trucks' => ['straight_box', 'bool', 'fleet', 'fleet'],
        'Sprinter cargo vans' => ['sprinter_vans', 'bool', 'fleet', 'fleet'],
        'Parking space' => ['parking', 'bool', 'fleet', 'fleet'],
        'Parking spaces (count)' => ['parking_spaces', 'int', 'fleet', 'fleet'],
        'Parking details' => ['parking_detail', 'text', 'fleet', 'fleet'],
        'Top-pick container lift' => ['top_pick', 'bool', 'fleet', 'fleet'],

        // Chassis & trailers
        'Private chassis' => ['private_chassis', 'bool', 'chassis', 'equipment'],
        'Chassis details' => ['private_chassis_detail', 'text', 'chassis', 'equipment'],
        'Dry vans' => ['dry_vans', 'bool', 'chassis', 'equipment'],
        'Reefer trailers' => ['reefer_trailers', 'bool', 'chassis', 'equipment'],
        'Flatbed / flatdeck' => ['flatbed', 'bool', 'chassis', 'equipment'],
        'Stepdeck / dropdeck' => ['stepdeck', 'bool', 'chassis', 'equipment'],
        'Double drop' => ['double_drop', 'bool', 'chassis', 'equipment'],
        'Lowboy' => ['lowboy', 'bool', 'chassis', 'equipment'],
        'RGN (removable gooseneck)' => ['rgn', 'bool', 'chassis', 'equipment'],
        'Tilt-down' => ['tilt_down', 'bool', 'chassis', 'equipment'],
        'Swing / side lift' => ['swing_lift', 'bool', 'chassis', 'equipment'],
        'Equipment notes' => ['equipment_notes', 'text', 'chassis', 'equipment'],

        // Contact & profile
        'Phone' => ['phone', 'string', 'contact', 'contact'],
        'All phones' => ['phones', 'list', 'contact', 'contact'],
        'Fax' => ['fax', 'string', 'contact', 'contact'],
        'Emails' => ['emails', 'list', 'contact', 'contact'],
        'Pricing email' => ['pricing_email', 'string', 'contact', 'contact'],
        'Dispatch email' => ['dispatch_email', 'string', 'contact', 'contact'],
        'Website' => ['website', 'string', 'contact', 'contact'],
        'Named contacts' => ['contact_people', 'list', 'contact', 'contact'],
        'Profile last updated' => ['last_updated', 'date', 'contact', 'profile_dates'],
        'Profile first added' => ['first_added', 'date', 'contact', 'profile_dates'],
        'LoadMatch ID' => ['loadmatch_id', 'string', 'contact', 'meta'],
    ];

    /** List filters: `{key}[]=value`, with `{key}_mode=any|all`. */
    public const MULTI_VALUE = [
        'metros', 'city_codes', 'hq_state', 'hq_country', 'states_served',
        'provinces_served', 'terminals', 'languages', 'record_type',
    ];

    /** `has[]=` keys. canadian_authority is any of the three Canadian numbers. */
    public const PRESENCE = [
        'scac', 'mc', 'usdot', 'firms_code', 'canadian_authority', 'phone',
        'emails', 'website', 'pricing_email', 'dispatch_email', 'contact_people',
    ];

    public const CANADIAN_AUTHORITY = ['nsc', 'cvor', 'nir'];

    /** Derived for range filters; live in the index only. */
    public const DERIVED_RANGES = [
        'authority_year' => 'authority_date',
        'first_added_year' => 'first_added',
    ];

    /** Sortable beyond the numbers and dates. */
    private const SORTABLE_TEXT = ['company_name', 'hq_city', 'hq_state', 'record_type', 'scac'];

    /**
     * The list endpoint's default `fields`, and the columns GET /drayage/fields
     * marks default_visible (checked) - carrier_key aside, which every row
     * carries so the frontend can open the carrier.
     */
    public const CARD_FIELDS = [
        'carrier_key', 'company_name', 'metros', 'hq_city', 'hq_state',
        'scac', 'mc', 'usdot', 'cargo_insurance', 'hazmat', 'drivers_approx',
        'twic', 'private_chassis', 'phone', 'pricing_email', 'dispatch_email',
        'last_updated',
    ];

    /** Counted by the completeness score, in the brief's order. */
    public const COMPLETENESS_FIELDS = [
        'scac', 'mc', 'usdot', 'phone', 'website', 'cargo_insurance',
        'drivers_approx', 'states_served', 'hazmat', 'reefer_drayage',
        'private_chassis', 'last_updated',
    ];

    /** Concatenated, lowercased, into search_text. */
    public const SEARCH_FIELDS = [
        'company_name', 'legal_name', 'alt_name', 'scac', 'mc', 'usdot',
        'hq_city', 'hq_state', 'description', 'emails', 'contact_people',
        'metros', 'hazmat_detail', 'equipment_notes',
    ];

    /** Lists that are unioned when duplicate rows merge into one carrier. */
    public const MERGE_UNION = ['metros', 'city_codes', 'emails', 'phones', 'terminals'];

    /** Values never written to logs or audit entries in full. */
    public const CONTACT_FIELDS = ['phone', 'phones', 'fax', 'emails', 'pricing_email', 'dispatch_email', 'contact_people'];

    /** @var array<string, array>|null */
    private static ?array $byKey = null;

    /**
     * Every field, keyed by its canonical key.
     *
     * @return array<string, array{key: string, label: string, type: string, group: string, section: string}>
     */
    public static function all(): array
    {
        if (self::$byKey !== null) {
            return self::$byKey;
        }

        $fields = [];

        foreach (self::DICTIONARY as $label => [$key, $type, $group, $section]) {
            $fields[$key] = compact('key', 'label', 'type', 'group', 'section');
        }

        return self::$byKey = $fields;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function keysOfType(string ...$types): array
    {
        return array_keys(array_filter(self::all(), fn (array $f) => in_array($f['type'], $types, true)));
    }

    public static function booleans(): array
    {
        return self::keysOfType('bool');
    }

    public static function lists(): array
    {
        return self::keysOfType('list');
    }

    /** Numeric fields, plus the derived years, each filterable by `_min` / `_max`. */
    public static function ranges(): array
    {
        return array_merge(self::keysOfType('int', 'money'), array_keys(self::DERIVED_RANGES));
    }

    public static function sortable(): array
    {
        return array_merge(self::SORTABLE_TEXT, self::keysOfType('int', 'money', 'date'));
    }

    /**
     * Fields copied into index/records.json: everything a list request can
     * filter, sort or show on a card without opening the carrier's own file.
     */
    public static function indexed(): array
    {
        return array_values(array_unique(array_merge(
            self::booleans(),
            self::ranges(),
            self::sortable(),
            self::MULTI_VALUE,
            array_diff(self::PRESENCE, ['canadian_authority']),
            self::CANADIAN_AUTHORITY,
            self::CARD_FIELDS,
            ['loadmatch_id', 'last_updated', 'first_added', 'authority_date'],
        )));
    }

    /**
     * Header label => key, keyed by the normalized label so matching ignores
     * case, spacing and the typographic primes/dashes in the export's labels.
     */
    public static function headerMap(): array
    {
        $map = [];

        foreach (self::all() as $key => $field) {
            $map[self::normalizeHeader($field['label'])] = $key;
            $map[self::normalizeHeader($key)] = $key;
        }

        return $map;
    }

    public static function normalizeHeader(string $header): string
    {
        $header = str_replace(["\u{FEFF}", '′', '’', '‘', '–', '—'], ['', "'", "'", "'", '-', '-'], $header);

        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($header)));
    }

    /**
     * The dictionary as GET /drayage/fields returns it.
     */
    public static function describe(): array
    {
        $booleans = self::booleans();
        $ranges = self::ranges();
        $sortable = self::sortable();

        $fields = [];

        foreach (self::all() as $key => $field) {
            $filters = [];

            if (in_array($key, $booleans, true)) {
                $filters[] = 'tri_state';
            }
            if (in_array($key, $ranges, true)) {
                $filters[] = 'range';
            }
            if (in_array($key, self::MULTI_VALUE, true)) {
                $filters[] = 'multi';
            }
            if (in_array($key, self::PRESENCE, true)) {
                $filters[] = 'presence';
            }
            if ($key === 'last_updated') {
                $filters[] = 'recency';
            }

            $entry = [
                'key' => $key,
                'label' => $field['label'],
                'group' => $field['group'],
                'group_label' => self::GROUPS[$field['group']],
                'section' => $field['section'],
                'type' => $field['type'],
                'filterable' => $filters !== [],
                'filters' => $filters,
                'sortable' => in_array($key, $sortable, true),
                'default_visible' => in_array($key, self::CARD_FIELDS, true),
            ];

            if ($key === 'record_type') {
                $entry['options'] = array_map(
                    fn ($value, $label) => ['value' => $value, 'label' => $label],
                    array_keys(self::RECORD_TYPES),
                    self::RECORD_TYPES
                );
            }

            $fields[] = $entry;
        }

        // Filters with no column of their own.
        $virtual = [
            ['key' => 'authority_year', 'label' => 'USDOT authority year', 'type' => 'int', 'derived_from' => 'authority_date', 'filters' => ['range']],
            ['key' => 'first_added_year', 'label' => 'Profile first added (year)', 'type' => 'int', 'derived_from' => 'first_added', 'filters' => ['range']],
            ['key' => 'canadian_authority', 'label' => 'Canadian authority (NSC / CVOR / NIR)', 'type' => 'string', 'derived_from' => 'nsc,cvor,nir', 'filters' => ['presence']],
        ];

        return [
            'fields' => $fields,
            'virtual_filters' => $virtual,
            'groups' => self::GROUPS,
            'card_fields' => self::CARD_FIELDS,
            'filter_syntax' => [
                'tri_state' => '{key}=yes|no|unknown',
                'presence' => 'has[]={key}',
                'range' => '{key}_min=N, {key}_max=N (records with no value are excluded)',
                'multi' => '{key}[]=value, {key}_mode=any|all (all: list fields only)',
                'recency' => 'updated_within_days=N',
                'text' => 'q=free text',
                'sort' => 'sort=key,-key (nulls last)',
            ],
        ];
    }
}
