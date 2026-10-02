<?php

namespace Tests\Feature\Drayage;

use App\Events\Drayage\DrayageDatasetActivated;
use App\Events\Drayage\DrayageImportFailed;
use App\Exceptions\DrayageException;
use App\Jobs\ImportDrayageDataset;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageImporter;
use App\Services\Drayage\DrayageNormalizer;
use App\Services\Drayage\DrayageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * The import pipeline: parsing, normalization, merging, the validation gate,
 * activation, rollback and the admin endpoints around them.
 */
class DrayageImportTest extends DrayageTestCase
{
    private function storage(): DrayageStorage
    {
        return app(DrayageStorage::class);
    }

    private function carrier(string $key): array
    {
        $storage = $this->storage();

        return DrayageNormalizer::flatten($storage->carrier($storage->currentDatasetId(), $key));
    }

    // ─────────────────────────────────────────────────────────────────────
    // The fixture end to end
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_fixture_imports_with_the_expected_counts_and_report(): void
    {
        $import = $this->import();

        $this->assertSame('completed', $import['status']);
        $this->assertTrue($import['activated']);
        $this->assertSame(['read' => 10, 'imported' => 6, 'merged' => 2, 'rejected' => 2], $import['report']['rows']);

        $reasons = array_column($import['report']['rejected_rows'], 'reason', 'row');
        $this->assertSame('Company is blank.', end($reasons));
        $this->assertStringContainsString('Expected 122 columns, found 3', implode(' ', $reasons));

        $this->assertSame(['Favorite color'], $import['report']['headers']['unknown']);
        $this->assertSame([], $import['report']['headers']['missing']);
        $this->assertCount(2, $import['report']['merges']);
        $this->assertArrayHasKey('cargo_insurance', $import['report']['field_coverage']);

        $manifest = $this->storage()->manifest($import['dataset_id']);
        $this->assertSame(6, $manifest['counts']['total']);
        $this->assertSame(5, $manifest['counts']['full_profiles']);
        $this->assertSame(1, $manifest['counts']['listings']);
        $this->assertSame(hash_file('sha256', $this->fixturePath()), $manifest['source_sha256']);
        $this->assertSame(1, $manifest['schema_version']);
    }

    public function test_genesis_intermodal_has_exactly_the_brief_values(): void
    {
        $this->import();
        $c = $this->carrier('lm-9224');

        $this->assertSame('Genesis Intermodal LLC', $c['company_name']);
        $this->assertSame('GEND', $c['scac']);
        $this->assertSame('1098356', $c['mc']);
        $this->assertSame('3408478', $c['usdot']);
        $this->assertSame('2020-04-03', $c['authority_date']);
        $this->assertSame(250000, $c['cargo_insurance']);
        $this->assertSame(65000, $c['trailer_interchange']);
        $this->assertTrue($c['liquor']);
        $this->assertTrue($c['amazon']);
        $this->assertFalse($c['hazmat']);
        $this->assertNull($c['oog']);
        foreach (['size_20', 'size_40', 'size_45', 'size_53'] as $size) {
            $this->assertTrue($c[$size], $size);
        }
        $this->assertSame(25, $c['drivers_approx']);
        $this->assertSame(25, $c['owner_operators']);
        $this->assertNull($c['company_drivers']);
        $this->assertSame(158, $c['fmcsa_drivers']);
        $this->assertSame(5698399, $c['mcs150_miles']);
        $this->assertSame(['AL', 'FL', 'GA', 'NC', 'SC', 'TN'], $c['states_served']);
        $this->assertSame(['Atlanta', 'Charlotte', 'Dallas', 'Houston', 'Memphis'], $c['terminals']);
        $this->assertTrue($c['iana']);
        $this->assertTrue($c['smartway']);
    }

    public function test_medlog_drayage_has_exactly_the_brief_values(): void
    {
        $this->import();
        $c = $this->carrier('lm-84667');

        $this->assertSame('C&K Trucking LLC', $c['dba_of']);
        $this->assertTrue($c['hazmat']);
        $this->assertStringContainsString('no classes 1.1', $c['hazmat_detail']);
        $this->assertTrue($c['customs_bonded']);
        $this->assertTrue($c['household_goods']);
        $this->assertSame(10, $c['parking_spaces']);
        $this->assertTrue($c['private_chassis']);
        $this->assertSame('tri-axles', $c['private_chassis_detail']);
        $this->assertFalse($c['cross_border_canada']);
        $this->assertNull($c['cross_border_mexico']);
        $this->assertSame(100000, $c['cargo_insurance']);
        $this->assertSame(75000, $c['trailer_interchange']);
    }

    public function test_incompass_logistics_has_exactly_the_brief_values(): void
    {
        $this->import();
        $c = $this->carrier('lm-86239');

        $this->assertSame('83043514', $c['bond_number']);
        $this->assertTrue($c['twic']);
        $this->assertSame(8, $c['company_drivers']);
        $this->assertSame(17, $c['owner_operators']);
        $this->assertSame(68, $c['owner_op_pct']);
        $this->assertFalse($c['size_53']);
        $this->assertTrue($c['ocean_port']);
        $this->assertTrue($c['flat_rack']);
        $this->assertTrue($c['flatbed']);
        $this->assertFalse($c['cross_border_mexico']);
        $this->assertSame('Beach City', $c['hq_city']);
        $this->assertSame('77524', $c['hq_zip']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Normalization
    // ─────────────────────────────────────────────────────────────────────

    public function test_identifiers_keep_their_leading_zeros(): void
    {
        $this->import();
        $c = $this->carrier('lm-70001');

        $this->assertSame('07105', $c['hq_zip']);
        $this->assertSame('0123456', $c['usdot']);
        $this->assertSame('007788', $c['mc'], 'MC- prefix stripped, zeros kept');
        $this->assertSame('PNDL', $c['scac'], 'SCAC uppercased');
        $this->assertSame('0045', $c['bond_number']);
        $this->assertSame(1000000, $c['cargo_insurance'], '$ and , stripped');
    }

    public function test_blank_booleans_stay_null_rather_than_false(): void
    {
        $this->import();
        $c = $this->carrier('lm-70001');

        $this->assertNull($c['hazmat']);
        $this->assertTrue($c['twic']);
        $this->assertNull($this->carrier('lm-84667')['cross_border_mexico']);
    }

    public function test_the_bom_is_stripped_from_the_first_header(): void
    {
        $import = $this->import();

        // Were the BOM kept, "Company" would not map and the file would be
        // refused for missing it.
        $this->assertSame('completed', $import['status']);
        $this->assertNotContains('company_name', $import['report']['headers']['missing']);
    }

    public function test_headers_match_regardless_of_case_spacing_and_dashes(): void
    {
        $csv = "  COMPANY ,record TYPE,LoadMatch   ID,20' containers,U.S.-Canada cross-border\n"
            ."Acme Dray,Full profile,123,Yes,No\n";

        $import = $this->importCsv($csv);
        $c = $this->carrier('lm-123');

        $this->assertSame('completed', $import['status']);
        $this->assertTrue($c['size_20']);
        $this->assertFalse($c['cross_border_canada']);
        $this->assertContains('hazmat', $import['report']['headers']['missing']);
    }

    public function test_list_fields_are_split_trimmed_and_deduplicated_in_order(): void
    {
        $import = $this->importCsv($this->csv([[
            'Company' => 'Acme Dray',
            'LoadMatch ID' => '5',
            'Terminals in' => ' Savannah ;Houston;; houston ; Dallas ',
            'States served' => 'tx; GA; ZZ; TX',
            'Provinces served' => 'on; QC; XX',
        ]]));

        $c = $this->carrier('lm-5');

        $this->assertSame(['Savannah', 'Houston', 'Dallas'], $c['terminals']);
        $this->assertSame(['TX', 'GA'], $c['states_served']);
        $this->assertSame(['ON', 'QC'], $c['provinces_served']);
        $this->assertSame(2, $import['report']['warnings']['by_field']['states_served'] + $import['report']['warnings']['by_field']['provinces_served']);
    }

    public function test_unreadable_values_become_null_with_a_warning_not_a_rejection(): void
    {
        $import = $this->import();
        $c = $this->carrier('lm-70002');

        $this->assertNull($c['cargo_insurance']);
        $this->assertNull($c['authority_date']);
        $this->assertNull($c['scac']);
        $this->assertNull($c['hazmat']);
        $this->assertSame(['GA'], $c['states_served']);

        $byField = $import['report']['warnings']['by_field'];
        foreach (['cargo_insurance', 'authority_date', 'scac', 'hazmat', 'states_served'] as $field) {
            $this->assertArrayHasKey($field, $byField, $field);
        }
    }

    public function test_warnings_never_quote_contact_values(): void
    {
        $import = $this->importCsv($this->csv([[
            'Company' => 'Acme Dray',
            'LoadMatch ID' => '6',
            'Cargo insurance' => 'lots',
        ]]));

        foreach ($import['report']['warnings']['samples'] as $warning) {
            if (in_array($warning['field'], ['phone', 'emails', 'pricing_email', 'dispatch_email'], true)) {
                $this->assertArrayNotHasKey('value', $warning);
            }
        }

        $this->assertSame('lots', $import['report']['warnings']['samples'][0]['value']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Keys, merging, derived fields
    // ─────────────────────────────────────────────────────────────────────

    public function test_carrier_keys_are_stable_across_reimports(): void
    {
        $first = $this->import();
        $firstKeys = array_column($this->storage()->index($first['dataset_id'], 'records'), 'carrier_key');

        $second = $this->import();
        $secondKeys = array_column($this->storage()->index($second['dataset_id'], 'records'), 'carrier_key');

        sort($firstKeys);
        sort($secondKeys);
        $this->assertSame($firstKeys, $secondKeys);
        $this->assertNotSame($first['dataset_id'], $second['dataset_id']);

        $listingKeys = array_values(array_filter($firstKeys, fn ($k) => str_starts_with($k, 'ls-')));
        $this->assertCount(1, $listingKeys);
        $this->assertMatchesRegularExpression('/^ls-[a-f0-9]{12}$/', $listingKeys[0]);
    }

    public function test_duplicate_rows_merge_into_the_fuller_one_with_lists_unioned(): void
    {
        $this->import();
        $genesis = $this->carrier('lm-9224');

        $this->assertSame(['GA - Atlanta', 'TN - Memphis'], $genesis['metros']);
        $this->assertSame(['ATL', 'MEM'], $genesis['city_codes']);
        $this->assertContains('memphis@example.test', $genesis['emails']);
        $this->assertNotNull($genesis['description'], 'the fuller row is the base');

        $storage = $this->storage();
        $listing = collect($storage->index($storage->currentDatasetId(), 'records'))->firstWhere('record_type', 'Directory listing');
        $this->assertCount(2, $listing['metros']);

        $document = $storage->carrier($storage->currentDatasetId(), 'lm-9224');
        $this->assertCount(2, $document['meta']['source_rows']);
    }

    public function test_derived_fields_are_recomputed(): void
    {
        $this->import();

        $genesis = $this->carrier('lm-9224');
        $this->assertSame((int) now()->format('Y') - 2020, $genesis['years_in_business'], 'from the authority year');
        $this->assertSame(92, $genesis['completeness'], '11 of 12');

        // The file says 99%; without company drivers there is nothing to
        // compute it from.
        $this->assertNull($this->carrier('lm-70001')['owner_op_pct']);
        $this->assertSame(68, $this->carrier('lm-86239')['owner_op_pct']);

        $storage = $this->storage();
        $row = collect($storage->index($storage->currentDatasetId(), 'records'))->firstWhere('carrier_key', 'lm-9224');
        $this->assertStringContainsString('genesis intermodal llc', $row['search_text']);
        $this->assertStringContainsString('gend', $row['search_text']);
        $this->assertSame(2020, $row['authority_year']);
    }

    public function test_the_carrier_document_is_sectioned_in_order(): void
    {
        $this->import();
        $storage = $this->storage();
        $document = $storage->carrier($storage->currentDatasetId(), 'lm-9224');

        $this->assertSame([
            'meta', 'identity', 'location', 'coverage', 'authority', 'insurance', 'compliance',
            'drayage', 'special_cargo', 'fleet', 'equipment', 'contact', 'profile_dates', 'links', 'extra',
        ], array_keys($document));

        $this->assertSame('LoadMatch / Drayage.com directory (imported)', $document['meta']['source']);
        $this->assertSame(['Favorite color' => 'blue'], $document['extra']);
        $this->assertStringContainsString('Genesis Intermodal LLC', $document['meta']['summary']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate, activation, rollback, lock
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_file_over_the_rejection_limit_fails_and_leaves_the_previous_dataset_live(): void
    {
        Event::fake([DrayageImportFailed::class]);

        $first = $this->import();

        config(['drayage.import.max_rejected_percent' => 5]);
        $second = $this->import();

        $this->assertSame('failed', $second['status']);
        $this->assertStringContainsString('20.0% of rows were rejected', $second['error']);
        $this->assertFalse($second['activated']);
        $this->assertSame($first['dataset_id'], $this->storage()->currentDatasetId());
        $this->assertCount(1, $this->storage()->datasets(), 'nothing was written for the failed import');
        $this->assertSame([], glob($this->root.'/datasets/.building-*'));

        Event::assertDispatched(DrayageImportFailed::class);
    }

    public function test_a_file_with_no_importable_rows_fails(): void
    {
        $import = $this->importCsv($this->csv([['Company' => '', 'LoadMatch ID' => '1']]));

        $this->assertSame('failed', $import['status']);
        $this->assertSame('No rows could be imported.', $import['error']);
        $this->assertNull($this->storage()->currentDatasetId());
    }

    public function test_a_file_without_the_required_columns_is_refused(): void
    {
        $import = $this->importCsv("Company,HQ city\nAcme,Savannah\n");

        $this->assertSame('failed', $import['status']);
        $this->assertStringContainsString('LoadMatch ID or Directory metros', $import['error']);
    }

    public function test_activation_swaps_the_pointer_and_rollback_needs_no_restart(): void
    {
        Event::fake([DrayageDatasetActivated::class]);

        $first = $this->import();
        $second = $this->import();

        $this->assertSame($second['dataset_id'], $this->storage()->currentDatasetId());
        $this->assertSame($first['dataset_id'], $second['previous_dataset_id']);

        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/drayage/stats')->assertOk()
            ->assertJsonPath('data.dataset.dataset_id', $second['dataset_id']);

        $this->postJson("/api/v1/drayage/datasets/{$first['dataset_id']}/activate")
            ->assertOk()
            ->assertJsonPath('data.current.dataset_id', $first['dataset_id']);

        $this->getJson('/api/v1/drayage/stats')->assertOk()
            ->assertJsonPath('data.dataset.dataset_id', $first['dataset_id']);

        Event::assertDispatched(DrayageDatasetActivated::class, fn ($e) => $e->reason === 'manual' && $e->datasetId === $first['dataset_id']);

        $audit = file($this->root.'/audit.jsonl');
        $this->assertStringContainsString('"action":"dataset.activated"', end($audit));
    }

    public function test_the_live_dataset_cannot_be_deleted_but_an_old_one_can(): void
    {
        $first = $this->import();
        $second = $this->import();

        Sanctum::actingAs($this->staffUser());

        $this->deleteJson("/api/v1/drayage/datasets/{$second['dataset_id']}")->assertStatus(409);
        $this->deleteJson("/api/v1/drayage/datasets/{$first['dataset_id']}")->assertOk();

        $this->assertFalse($this->storage()->datasetExists($first['dataset_id']));
        $this->assertTrue($this->storage()->datasetExists($second['dataset_id']));
    }

    public function test_retention_prunes_the_oldest_datasets_after_activation(): void
    {
        config(['drayage.retention' => 2]);

        $first = $this->import();
        $this->import();
        $third = $this->import();

        $ids = array_column($this->storage()->datasets(), 'dataset_id');

        $this->assertCount(2, $ids);
        $this->assertNotContains($first['dataset_id'], $ids);
        $this->assertSame([$first['dataset_id']], $third['report']['pruned_datasets']);
    }

    public function test_a_second_import_is_blocked_while_one_holds_the_lock(): void
    {
        $importer = app(DrayageImporter::class);
        $import = $importer->queue($this->fixturePath(), 'carriers.csv', 'csv', DrayageAudit::console('test'), null);

        $lock = DrayageStorage::lockStore()->lock('drayage:import', 60);
        $this->assertTrue($lock->get());

        try {
            $importer->run($import['import_id']);
            $this->fail('The import ran while another held the lock.');
        } catch (DrayageException $e) {
            $this->assertSame(409, $e->status);
        } finally {
            $lock->release();
        }

        $this->assertSame('queued', $this->storage()->import($import['import_id'])['status']);
        $this->assertSame('completed', $importer->run($import['import_id'])['status']);
    }

    public function test_the_job_releases_itself_back_onto_the_queue_when_locked(): void
    {
        $importer = app(DrayageImporter::class);
        $import = $importer->queue($this->fixturePath(), 'carriers.csv', 'csv', DrayageAudit::console('test'), null);

        $lock = DrayageStorage::lockStore()->lock('drayage:import', 60);
        $lock->get();

        $job = (new ImportDrayageDataset($import['import_id']))->withFakeQueueInteractions();
        $job->handle($importer);
        $lock->release();

        $job->assertReleased(30);
    }

    public function test_path_unsafe_identifiers_are_refused(): void
    {
        $storage = $this->storage();

        $this->assertNull($storage->import('../../etc/passwd'));
        $this->assertNull($storage->manifest('ds-../../x'));
        $this->assertNull($storage->carrier('ds-20261002T000000000Z-abcdef', '../secret'));
        $this->assertFalse(DrayageStorage::isCarrierKey('lm-1/../../x'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // JSON input
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_json_array_imports_the_same_as_csv(): void
    {
        $path = $this->root.'-input.json';
        file_put_contents($path, json_encode([
            ['Company' => 'Json Dray', 'LoadMatch ID' => '900', 'Hazmat' => 'Yes', 'States served' => 'GA; TX', 'HQ ZIP/postal' => '07105'],
            ['company_name' => 'Keyed Dray', 'loadmatch_id' => 901, 'hazmat' => false, 'states_served' => ['FL'], 'mystery' => 'x'],
            ['identity' => ['company_name' => 'Nested Dray'], 'meta' => ['loadmatch_id' => '902'], 'special_cargo' => ['hazmat' => true]],
        ]));

        $import = $this->import($path, 'json');
        @unlink($path);

        $this->assertSame('completed', $import['status']);
        $this->assertSame(3, $import['report']['rows']['imported']);
        $this->assertTrue($this->carrier('lm-900')['hazmat']);
        $this->assertSame('07105', $this->carrier('lm-900')['hq_zip']);
        $this->assertFalse($this->carrier('lm-901')['hazmat']);
        $this->assertSame(['FL'], $this->carrier('lm-901')['states_served']);
        $this->assertSame('Nested Dray', $this->carrier('lm-902')['company_name']);
        $this->assertSame(['mystery'], $import['report']['headers']['unknown']);
    }

    public function test_jsonl_imports_one_record_per_line(): void
    {
        $path = $this->root.'-input.jsonl';
        file_put_contents($path, "{\"Company\":\"Line One\",\"LoadMatch ID\":\"1\"}\n\nnot json\n{\"Company\":\"Line Two\",\"LoadMatch ID\":\"2\"}\n");

        config(['drayage.import.max_rejected_percent' => 50]);
        $import = $this->import($path, 'jsonl');
        @unlink($path);

        $this->assertSame(['read' => 3, 'imported' => 2, 'merged' => 0, 'rejected' => 1], $import['report']['rows']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Admin endpoints
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_upload_is_queued_and_answered_with_202(): void
    {
        Bus::fake([ImportDrayageDataset::class]);
        Sanctum::actingAs($this->staffUser());

        $file = new UploadedFile($this->fixturePath(), 'carriers.csv', 'text/csv', null, true);

        $response = $this->post('/api/v1/drayage/imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        $importId = $response->json('data.import_id');
        Bus::assertDispatched(ImportDrayageDataset::class, fn ($job) => $job->importId === $importId);

        $stored = $this->storage()->import($importId);
        $this->assertSame('queued', $stored['status']);
        $this->assertFileExists($this->root.'/'.$stored['source_storage_path']);

        $this->getJson("/api/v1/drayage/imports/{$importId}")->assertOk()->assertJsonPath('data.import.status', 'queued');
        $this->getJson('/api/v1/drayage/imports')->assertOk()->assertJsonPath('data.imports.0.import_id', $importId);
    }

    public function test_an_upload_that_is_not_text_is_refused(): void
    {
        Sanctum::actingAs($this->staffUser());

        $file = UploadedFile::fake()->create('carriers.csv', 10, 'application/pdf');

        $this->post('/api/v1/drayage/imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_the_datasets_endpoint_lists_versions_with_the_live_one_marked(): void
    {
        $first = $this->import();
        $second = $this->import();

        Sanctum::actingAs($this->staffUser());

        $datasets = $this->getJson('/api/v1/drayage/datasets')->assertOk()->json('data.datasets');

        $this->assertSame([$second['dataset_id'], $first['dataset_id']], array_column($datasets, 'dataset_id'));
        $this->assertSame([true, false], array_column($datasets, 'is_current'));
    }
}
