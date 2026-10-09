<?php

namespace Tests\Feature\Drayage;

use App\Events\Drayage\DrayageExportCreated;
use App\Http\Controllers\Api\V1\Drayage\DrayageExportController;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

class DrayageExportTest extends DrayageTestCase
{
    private function exportCsv(array $params = []): array
    {
        $response = $this->get('/api/v1/drayage/export?'.http_build_query($params), ['Accept' => 'application/json'])->assertOk();

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return ['response' => $response, 'header' => array_shift($rows), 'rows' => $rows];
    }

    public function test_the_export_uses_the_export_labels_and_formats(): void
    {
        $this->import();
        Event::fake([DrayageExportCreated::class]);
        Sanctum::actingAs($this->brokerUser('owner_admin'));

        $csv = $this->exportCsv(['q' => 'genesis']);

        $this->assertCount(119, $csv['header']);
        $this->assertSame('Company', $csv['header'][0]);
        $this->assertContains('20′ containers', $csv['header']);
        $this->assertCount(1, $csv['rows']);

        $row = array_combine($csv['header'], $csv['rows'][0]);
        $this->assertSame('Genesis Intermodal LLC', $row['Company']);
        $this->assertSame('Yes', $row['Liquor / alcohol']);
        $this->assertSame('No', $row['Hazmat']);
        $this->assertSame('', $row['Out-of-gauge (OOG)'], 'null stays blank');
        $this->assertSame('AL; FL; GA; NC; SC; TN', $row['States served']);
        $this->assertSame('250000', $row['Cargo insurance']);

        $this->assertStringContainsString('drayage_carriers_', $csv['response']->headers->get('Content-Disposition'));

        Event::assertDispatched(DrayageExportCreated::class, fn ($e) => $e->rowCount === 1 && $e->format === 'csv');
    }

    public function test_an_export_reimports_to_the_same_carriers(): void
    {
        $this->import();
        Sanctum::actingAs($this->brokerUser('owner_admin'));

        $body = $this->get('/api/v1/drayage/export', ['Accept' => 'application/json'])->streamedContent();
        $path = $this->root.'-export.csv';
        file_put_contents($path, $body);

        $import = $this->import($path);
        @unlink($path);

        $this->assertSame('completed', $import['status']);
        $this->assertSame(6, $import['report']['rows']['imported']);
        $this->assertSame(0, $import['report']['rows']['rejected']);
    }

    public function test_fields_narrow_the_columns(): void
    {
        $this->import();
        Sanctum::actingAs($this->brokerUser('compliance_manager'));

        $csv = $this->exportCsv(['fields' => 'company_name,usdot,hq_zip', 'sort' => 'company_name']);

        $this->assertSame(['Company', 'USDOT #', 'HQ ZIP/postal'], $csv['header']);
        $this->assertContains(['Port Newark Drayage LLC', '0123456', '07105'], $csv['rows']);
    }

    public function test_formula_like_cells_are_defused(): void
    {
        $this->importCsv($this->csv([[
            'Company' => '=HYPERLINK("http://evil.test","click")',
            'LoadMatch ID' => '42',
            'Description' => '+1 call now',
            'Equipment notes' => '@SUM(A1)',
            'Reefer details' => '-2+3',
        ]]));
        Sanctum::actingAs($this->brokerUser('owner_admin'));

        $csv = $this->exportCsv();
        $row = array_combine($csv['header'], $csv['rows'][0]);

        $this->assertSame('\'=HYPERLINK("http://evil.test","click")', $row['Company']);
        $this->assertSame("'+1 call now", $row['Description']);
        $this->assertSame("'@SUM(A1)", $row['Equipment notes']);
        $this->assertSame("'-2+3", $row['Reefer details']);
    }

    public function test_cell_formatting(): void
    {
        $this->assertSame('Yes', DrayageExportController::cell(true, 'bool'));
        $this->assertSame('No', DrayageExportController::cell(false, 'bool'));
        $this->assertSame('', DrayageExportController::cell(null, 'bool'));
        $this->assertSame('a; b', DrayageExportController::cell(['a', 'b'], 'list'));
        $this->assertSame("'=1+1", DrayageExportController::cell('=1+1', 'text'));
        $this->assertSame("'\tx", DrayageExportController::cell("\tx", 'text'));
    }

    public function test_xlsx_is_refused_with_a_clear_message(): void
    {
        $this->import();
        Sanctum::actingAs($this->brokerUser('owner_admin'));

        $this->getJson('/api/v1/drayage/export?format=xlsx')->assertStatus(422)
            ->assertJsonPath('errors.format.0', 'Only format=csv is available. XLSX export is not enabled yet.');
        $this->getJson('/api/v1/drayage/export?page=2')->assertStatus(422);
    }

    public function test_exports_are_audited_without_contact_values(): void
    {
        $this->import();
        Sanctum::actingAs($this->brokerUser('owner_admin'));

        $this->get('/api/v1/drayage/export?q='.urlencode('dispatch1@example.test'), ['Accept' => 'application/json'])->assertOk();
        $this->get('/api/v1/drayage/export?q='.urlencode('555-010-0001'), ['Accept' => 'application/json'])->assertOk();

        $audit = file_get_contents($this->root.'/audit.jsonl');

        $this->assertStringContainsString('"action":"export.created"', $audit);
        $this->assertStringNotContainsString('dispatch1@example.test', $audit);
        $this->assertStringContainsString('d***@example.test', $audit);
        $this->assertStringNotContainsString('555-010-0001', $audit);
        $this->assertStringContainsString('"row_count":1', $audit);
    }

    public function test_the_export_rate_limit(): void
    {
        $this->import();
        config(['drayage.rate_limits.export' => 5]);
        Sanctum::actingAs($this->brokerUser('owner_admin'));

        for ($i = 0; $i < 5; $i++) {
            $this->get('/api/v1/drayage/export?fields=company_name', ['Accept' => 'application/json'])->assertOk();
        }

        $this->get('/api/v1/drayage/export?fields=company_name', ['Accept' => 'application/json'])->assertStatus(429);

        // A separate counter: reads still work.
        $this->getJson('/api/v1/drayage/stats')->assertOk();
    }
}
