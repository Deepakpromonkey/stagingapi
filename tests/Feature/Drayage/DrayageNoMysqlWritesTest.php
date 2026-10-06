<?php

namespace Tests\Feature\Drayage;

use App\Jobs\ScoreCarrierSearchPage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Drayage data lives in JSON files, never in MySQL: no row, table or
 * migration for carriers, imports or datasets.
 *
 * Listens to every statement on every connection while a full import and a
 * call to each drayage endpoint run, and fails on any INSERT, UPDATE,
 * DELETE, CREATE, ALTER, DROP, REPLACE or TRUNCATE - except against the
 * framework's own tables (cache, cache locks, the job queue, Sanctum's
 * token bookkeeping), which the agreed design allows.
 */
class DrayageNoMysqlWritesTest extends DrayageTestCase
{
    private const FRAMEWORK_TABLES = ['cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'personal_access_tokens'];

    public function test_import_and_every_endpoint_write_nothing_to_mysql(): void
    {
        Bus::fake([ScoreCarrierSearchPage::class]);

        $staff = $this->staffUser();
        $writes = [];

        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (! preg_match('/^\s*(insert|update|delete|create|alter|drop|replace|truncate)\b/i', $query->sql)) {
                return;
            }

            foreach (self::FRAMEWORK_TABLES as $table) {
                if (preg_match('/^\s*\w+\s+(?:into\s+|from\s+|table\s+)?["`]?'.$table.'["`]?[\s(]/i', $query->sql)) {
                    return;
                }
            }

            $writes[] = $query->connectionName.': '.$query->sql;
        });

        $first = $this->import();
        $second = $this->import();

        Sanctum::actingAs($staff);

        $file = new UploadedFile($this->fixturePath(), 'carriers.csv', 'text/csv', null, true);
        $this->post('/api/v1/drayage/imports', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(202);

        $this->getJson('/api/v1/drayage/carriers?hazmat=yes&has[]=scac&sort=-cargo_insurance&include=trust_score,onboarding')->assertOk();
        $this->getJson('/api/v1/drayage/carriers/lm-9224?include=trust_score,onboarding')->assertOk();
        $this->getJson('/api/v1/drayage/carriers/lookup?scac=GEND')->assertOk();
        $this->getJson('/api/v1/drayage/facets')->assertOk();
        $this->getJson('/api/v1/drayage/fields')->assertOk();
        $this->getJson('/api/v1/drayage/stats')->assertOk();
        $this->get('/api/v1/drayage/export', ['Accept' => 'application/json'])->assertOk()->streamedContent();
        $this->getJson('/api/v1/drayage/imports')->assertOk();
        $this->getJson('/api/v1/drayage/datasets')->assertOk();
        $this->postJson("/api/v1/drayage/datasets/{$first['dataset_id']}/activate")->assertOk();
        $this->postJson("/api/v1/drayage/datasets/{$second['dataset_id']}/activate")->assertOk();
        $this->deleteJson("/api/v1/drayage/datasets/{$first['dataset_id']}")->assertOk();

        $this->assertSame([], $writes, "Drayage wrote to MySQL:\n".implode("\n", $writes));
    }

    public function test_the_guard_itself_catches_a_write(): void
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        DB::table('companies')->insert(['uuid' => 'x', 'company_name' => 'x', 'status' => true]);

        $this->assertNotEmpty($writes);
    }
}
