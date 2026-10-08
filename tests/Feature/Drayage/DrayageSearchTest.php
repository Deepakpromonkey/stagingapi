<?php

namespace Tests\Feature\Drayage;

use App\Services\Drayage\DrayageFields;
use Laravel\Sanctum\Sanctum;

/**
 * GET /drayage/carriers and the read endpoints around it: every filter
 * kind, facets, ranking, sorting, pagination and the 422s.
 *
 * Runs against a hand-built dataset where every expected count is obvious
 * from the rows below.
 */
class DrayageSearchTest extends DrayageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsv($this->csv([
            [
                'Company' => 'Alpha Dray', 'Record type' => 'Full profile', 'LoadMatch ID' => '1',
                'Directory metros' => 'GA - Atlanta; TN - Memphis', 'HQ city' => 'Atlanta', 'HQ state/prov.' => 'GA', 'HQ country' => 'USA',
                'States served' => 'GA; TN; AL', 'Hazmat' => 'Yes', 'TWIC' => 'Yes', 'Reefer drayage' => 'No',
                'SCAC' => 'ALPH', 'MC #' => '111111', 'USDOT #' => '1000001', 'Phone' => '404-555-0101',
                'Cargo insurance' => '250000', 'Drivers (approx.)' => '40', 'Profile last updated' => now()->subDays(10)->format('Y-m-d'),
                'USDOT authority date' => '2010-05-01', 'Languages' => 'English; Spanish',
            ],
            [
                'Company' => 'Bravo Intermodal', 'Record type' => 'Full profile', 'LoadMatch ID' => '2',
                'Directory metros' => 'GA - Atlanta', 'HQ city' => 'Savannah', 'HQ state/prov.' => 'GA', 'HQ country' => 'USA',
                'States served' => 'GA', 'Hazmat' => 'No', 'TWIC' => 'Yes',
                'SCAC' => 'BRAV', 'MC #' => '222222', 'USDOT #' => '1000002',
                'Cargo insurance' => '100000', 'Drivers (approx.)' => '5', 'Profile last updated' => now()->subDays(400)->format('Y-m-d'),
                'USDOT authority date' => '2021-01-15', 'Languages' => 'English',
            ],
            [
                'Company' => 'Charlie Alpha Freight', 'Record type' => 'Full profile', 'LoadMatch ID' => '3',
                'Directory metros' => 'TX - Houston', 'HQ city' => 'Houston', 'HQ state/prov.' => 'TX', 'HQ country' => 'USA',
                'States served' => 'TX', 'TWIC' => 'No', 'Description' => 'serves 1000001 lanes',
                'MC #' => '333333', 'Drivers (approx.)' => '12', 'CVOR # (Ontario)' => 'C-77',
            ],
            [
                'Company' => 'Delta Listing', 'Record type' => 'Directory listing',
                'Directory metros' => 'ON - Toronto', 'HQ city' => 'Toronto', 'HQ state/prov.' => 'ON', 'HQ country' => 'Canada',
            ],
        ]));

        Sanctum::actingAs($this->brokerUser('agent'));
    }

    private function search(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/drayage/carriers?'.http_build_query($params));
    }

    private function names(array $params = []): array
    {
        return array_column($this->search($params + ['fields' => 'company_name'])->assertOk()->json('data.carriers'), 'company_name');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filters
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_default_list_has_the_card_fields_and_envelope(): void
    {
        $response = $this->search()->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonPath('data.source', 'LoadMatch / Drayage.com directory (imported)');

        $this->assertSame(DrayageFields::CARD_FIELDS, array_keys($response->json('data.carriers.0')));
    }

    public function test_tri_state_boolean_filters(): void
    {
        $this->assertSame(['Alpha Dray'], $this->names(['hazmat' => 'yes']));
        $this->assertSame(['Bravo Intermodal'], $this->names(['hazmat' => 'no']));
        $this->assertEqualsCanonicalizing(['Charlie Alpha Freight', 'Delta Listing'], $this->names(['hazmat' => 'unknown']));
    }

    public function test_presence_filters_and_together(): void
    {
        $this->assertEqualsCanonicalizing(['Alpha Dray', 'Bravo Intermodal'], $this->names(['has' => ['scac', 'mc']]));
        $this->assertSame(['Alpha Dray'], $this->names(['has' => ['phone']]));
        $this->assertSame(['Charlie Alpha Freight'], $this->names(['has' => ['canadian_authority']]));
    }

    public function test_ranges_are_inclusive_and_exclude_records_with_no_value(): void
    {
        $this->assertEqualsCanonicalizing(['Alpha Dray', 'Bravo Intermodal'], $this->names(['cargo_insurance_min' => 100000]));
        $this->assertSame(['Bravo Intermodal'], $this->names(['cargo_insurance_max' => 100000]));
        $this->assertEqualsCanonicalizing(['Bravo Intermodal', 'Charlie Alpha Freight'], $this->names(['drivers_approx_min' => 5, 'drivers_approx_max' => 12]));
        $this->assertSame(['Bravo Intermodal'], $this->names(['authority_year_min' => 2020]));
    }

    public function test_multi_value_filters_with_any_and_all(): void
    {
        $this->assertEqualsCanonicalizing(['Alpha Dray', 'Charlie Alpha Freight'], $this->names(['states_served' => ['TN', 'TX']]));
        $this->assertSame(['Alpha Dray'], $this->names(['states_served' => ['GA', 'TN'], 'states_served_mode' => 'all']));
        $this->assertEqualsCanonicalizing(['Alpha Dray', 'Bravo Intermodal'], $this->names(['metros' => 'GA - Atlanta']));
        $this->assertSame(['Delta Listing'], $this->names(['hq_country' => ['canada']]), 'case-insensitive');
        $this->assertSame(['Alpha Dray'], $this->names(['languages' => ['spanish']]));
    }

    public function test_record_type_filter(): void
    {
        $this->assertSame(['Delta Listing'], $this->names(['record_type' => 'listing']));
        $this->assertCount(3, $this->names(['record_type' => 'full']));
        $this->assertCount(4, $this->names(['record_type' => 'all']));
        $this->assertCount(4, $this->names(['record_type' => ['full', 'listing']]));
    }

    public function test_updated_within_days_excludes_stale_and_undated_profiles(): void
    {
        $this->assertSame(['Alpha Dray'], $this->names(['updated_within_days' => 30]));
        $this->assertEqualsCanonicalizing(['Alpha Dray', 'Bravo Intermodal'], $this->names(['updated_within_days' => 500]));
    }

    public function test_free_text_tokens_are_anded(): void
    {
        $this->assertEqualsCanonicalizing(['Alpha Dray', 'Charlie Alpha Freight'], $this->names(['q' => 'alpha']));
        $this->assertSame(['Charlie Alpha Freight'], $this->names(['q' => 'alpha houston']));
        $this->assertSame([], $this->names(['q' => 'alpha toronto']));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ranking and sorting
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_exact_usdot_ranks_first(): void
    {
        // Charlie's description mentions the number too; the carrier that
        // actually holds the USDOT comes first.
        $this->assertSame(['Alpha Dray', 'Charlie Alpha Freight'], $this->names(['q' => '1000001']));
        $this->assertSame(['Bravo Intermodal'], $this->names(['q' => 'MC-222222']));
        $this->assertSame(['Bravo Intermodal'], $this->names(['q' => 'brav']));
    }

    public function test_a_name_prefix_outranks_a_match_elsewhere(): void
    {
        $this->assertSame(['Alpha Dray', 'Charlie Alpha Freight'], $this->names(['q' => 'alpha']));
    }

    public function test_sorting_puts_nulls_last_in_both_directions(): void
    {
        $this->assertSame(['Bravo Intermodal', 'Alpha Dray', 'Charlie Alpha Freight', 'Delta Listing'], $this->names(['sort' => 'cargo_insurance']));
        $this->assertSame(['Alpha Dray', 'Bravo Intermodal', 'Charlie Alpha Freight', 'Delta Listing'], $this->names(['sort' => '-cargo_insurance']));
        $this->assertSame(['Alpha Dray', 'Charlie Alpha Freight', 'Bravo Intermodal', 'Delta Listing'], $this->names(['sort' => '-drivers_approx']));
        $this->assertSame(['Delta Listing', 'Charlie Alpha Freight', 'Bravo Intermodal', 'Alpha Dray'], $this->names(['sort' => '-company_name']));
    }

    public function test_pagination_bounds(): void
    {
        $this->search(['per_page' => 3, 'page' => 2])->assertOk()
            ->assertJsonCount(1, 'data.carriers')
            ->assertJsonPath('data.pagination', ['current_page' => 2, 'last_page' => 2, 'per_page' => 3, 'total' => 4]);

        $this->search(['per_page' => 3, 'page' => 9])->assertOk()->assertJsonCount(0, 'data.carriers');
        $this->search(['per_page' => 501])->assertStatus(422);
        $this->search(['page' => 0])->assertStatus(422);
    }

    public function test_fields_selects_columns_including_ones_only_in_the_carrier_file(): void
    {
        $item = $this->search(['fields' => 'company_name,description,summary', 'q' => 'charlie'])->assertOk()->json('data.carriers.0');

        $this->assertSame(['carrier_key', 'company_name', 'description', 'summary'], array_keys($item));
        $this->assertSame('serves 1000001 lanes', $item['description']);
        $this->assertStringContainsString('Charlie Alpha Freight', $item['summary']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Facets
    // ─────────────────────────────────────────────────────────────────────

    public function test_boolean_facets_reconcile_with_the_total(): void
    {
        $params = ['twic' => 'yes', 'has' => ['mc']];
        $data = $this->search($params)->assertOk()->json('data');

        foreach (DrayageFields::booleans() as $key) {
            // Exclude-self: each facet counts over every filter but its own,
            // so it sums to what the other filters alone would return.
            $others = $params;
            unset($others[$key]);
            $expected = $this->search($others + ['per_page' => 1, 'facets' => 'false'])->json('data.pagination.total');

            $counts = $data['facets']['booleans'][$key];
            $this->assertSame($expected, $counts['yes'] + $counts['no'] + $counts['unknown'], $key);
        }
    }

    public function test_facets_use_exclude_self_semantics(): void
    {
        $facets = $this->search(['hazmat' => 'yes'])->assertOk()->json('data.facets');

        // The hazmat facet ignores hazmat=yes: it still shows all four.
        $this->assertSame(['yes' => 1, 'no' => 1, 'unknown' => 2], $facets['booleans']['hazmat']);

        // Every other facet is narrowed to the one hazmat carrier.
        $this->assertSame(['yes' => 1, 'no' => 0, 'unknown' => 0], $facets['booleans']['twic']);
        $this->assertSame([['value' => 'GA - Atlanta', 'count' => 1], ['value' => 'TN - Memphis', 'count' => 1]], $facets['values']['metros']);
        $this->assertSame(['present' => 1, 'absent' => 0], $facets['presence']['scac']);
    }

    public function test_multi_value_facets_ignore_their_own_filter(): void
    {
        $facets = $this->search(['metros' => ['TX - Houston']])->assertOk()->json('data.facets');
        $metros = array_column($facets['values']['metros'], 'count', 'value');

        $this->assertSame(2, $metros['GA - Atlanta']);
        $this->assertSame(1, $metros['TX - Houston']);
        $this->assertSame([['value' => 'TX', 'count' => 1]], $facets['values']['hq_state']);
    }

    public function test_the_summary_stats(): void
    {
        $summary = $this->search(['record_type' => 'full'])->assertOk()->json('data.summary');

        $this->assertSame(3, $summary['total']);
        $this->assertSame(12, $summary['median_drivers_approx']);
        $this->assertEquals(175000, $summary['median_cargo_insurance']);
        $this->assertEquals(50.0, $summary['pct_yes_of_stated']['hazmat']);
        $this->assertEquals(66.7, $summary['pct_yes_of_stated']['twic']);
        $this->assertNull($summary['pct_yes_of_stated']['private_chassis']);
    }

    public function test_facets_can_be_turned_off(): void
    {
        $this->search(['facets' => 'false'])->assertOk()->assertJsonPath('data.facets', null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invalid input
    // ─────────────────────────────────────────────────────────────────────

    public function test_invalid_parameters_are_422_not_ignored(): void
    {
        $this->search(['hazmatt' => 'yes'])->assertStatus(422)->assertJsonValidationErrors('hazmatt');
        $this->search(['hazmat' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('hazmat');
        $this->search(['record_type' => 'premium'])->assertStatus(422);
        $this->search(['cargo_insurance_min' => 'lots'])->assertStatus(422)->assertJsonValidationErrors('cargo_insurance_min');
        $this->search(['cargo_insurance_min' => 5, 'cargo_insurance_max' => 1])->assertStatus(422);
        $this->search(['sort' => 'description'])->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->search(['fields' => 'company_name,password'])->assertStatus(422)->assertJsonValidationErrors('fields');
        $this->search(['has' => ['nothing']])->assertStatus(422);
        $this->search(['hq_state' => 'GA', 'hq_state_mode' => 'all'])->assertStatus(422)->assertJsonValidationErrors('hq_state_mode');
        $this->search(['states_served' => 'GA', 'states_served_mode' => 'every'])->assertStatus(422);
        $this->search(['include' => 'bank_details'])->assertStatus(422);
        $this->search(['facets' => 'maybe'])->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Detail, lookup, fields, facets, stats
    // ─────────────────────────────────────────────────────────────────────

    public function test_detail_returns_the_full_document(): void
    {
        $this->getJson('/api/v1/drayage/carriers/lm-1')->assertOk()
            ->assertJsonPath('data.carrier.identity.company_name', 'Alpha Dray')
            ->assertJsonPath('data.carrier.special_cargo.hazmat', true)
            ->assertJsonPath('data.carrier.meta.carrier_key', 'lm-1');

        $this->getJson('/api/v1/drayage/carriers/lm-99999')->assertNotFound();
        $this->getJson('/api/v1/drayage/carriers/lm-1?include=everything')->assertStatus(422);
    }

    public function test_lookup_by_each_identifier(): void
    {
        $this->getJson('/api/v1/drayage/carriers/lookup?usdot=1000002')->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.carriers.0.company_name', 'Bravo Intermodal');

        $this->getJson('/api/v1/drayage/carriers/lookup?mc=MC333333')->assertOk()->assertJsonPath('data.carriers.0.company_name', 'Charlie Alpha Freight');
        $this->getJson('/api/v1/drayage/carriers/lookup?scac=alph')->assertOk()->assertJsonPath('data.carriers.0.company_name', 'Alpha Dray');
        $this->getJson('/api/v1/drayage/carriers/lookup?usdot=4')->assertOk()->assertJsonPath('data.count', 0);
        $this->getJson('/api/v1/drayage/carriers/lookup?usdot=1&mc=2')->assertStatus(422);
        $this->getJson('/api/v1/drayage/carriers/lookup')->assertStatus(422);
    }

    public function test_fields_describes_the_dictionary(): void
    {
        $data = $this->getJson('/api/v1/drayage/fields')->assertOk()->json('data');

        $this->assertCount(119, $data['fields']);
        $this->assertNull(collect($data['fields'])->firstWhere('key', 'profile_url'));
        $this->assertNull(collect($data['fields'])->firstWhere('key', 'directory_url'));
        $checked = collect($data['fields'])->where('default_visible', true)->pluck('key')->sort()->values()->all();
        $expected = ['company_name', 'metros', 'hq_city', 'hq_state', 'scac', 'mc', 'usdot', 'cargo_insurance', 'hazmat', 'drivers_approx', 'twic', 'private_chassis', 'phone', 'pricing_email', 'dispatch_email', 'last_updated'];
        sort($expected);
        $this->assertSame($expected, $checked);
        $hazmat = collect($data['fields'])->firstWhere('key', 'hazmat');
        $this->assertSame(['tri_state'], $hazmat['filters']);
        $this->assertSame('Special cargo & services', $hazmat['group_label']);
        $this->assertTrue(collect($data['fields'])->firstWhere('key', 'cargo_insurance')['sortable']);
    }

    public function test_dataset_facets_and_stats(): void
    {
        $facets = $this->getJson('/api/v1/drayage/facets')->assertOk()->json('data.facets');
        $this->assertSame(4, $facets['total']);
        $this->assertSame(['min' => 100000, 'max' => 250000, 'count' => 2], $facets['bounds']['cargo_insurance']);
        $this->assertSame(['yes' => 1, 'no' => 1, 'unknown' => 2], $facets['booleans']['hazmat']);

        $stats = $this->getJson('/api/v1/drayage/stats')->assertOk()->json('data');
        $this->assertSame(['carriers' => 4, 'full_profiles' => 3, 'listings' => 1], $stats['totals']);
        $this->assertSame(['value' => 'GA - Atlanta', 'count' => 2], $stats['metros'][0]);
    }

    public function test_reads_before_any_import_are_a_clear_404(): void
    {
        config(['drayage.root' => $this->root.'-empty']);

        $this->getJson('/api/v1/drayage/carriers')->assertNotFound()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'No drayage dataset is live yet. An administrator needs to import one.');

        $this->getJson('/api/v1/drayage/fields')->assertOk();
    }
}
