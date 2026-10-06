<?php

namespace Tests\Feature\Drayage;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageImporter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Shared set-up for the drayage directory tests: a throwaway store per test,
 * the seeded permission matrix, and helpers for users and imports.
 *
 * The fixture (tests/Fixtures/drayage/carriers.csv) is cut from the real
 * Drayage Carrier Finder export with contact details replaced, and adds the
 * awkward cases on purpose: a leading-zero ZIP and USDOT, two duplicate
 * rows to merge, a row of unreadable values, a blank company, a row with
 * the wrong column count and an unknown extra column. Of its 10 data rows,
 * 2 are rejected and 2 merge, leaving 6 carriers.
 */
abstract class DrayageTestCase extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/drayage-test-'.Str::random(10);

        config([
            'drayage.root' => $this->root,
            // The fixture rejects 2 of 10 rows on purpose.
            'drayage.import.max_rejected_percent' => 25,
            'drayage.queue.connection' => 'sync',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->root);
        }

        parent::tearDown();
    }

    protected function fixturePath(string $name = 'carriers.csv'): string
    {
        return base_path('tests/Fixtures/drayage/'.$name);
    }

    /**
     * Runs a file through the real pipeline and returns the finished import.
     */
    protected function import(?string $path = null, string $format = 'csv'): array
    {
        $importer = app(DrayageImporter::class);
        $path ??= $this->fixturePath();

        $import = $importer->queue($path, basename($path), $format, DrayageAudit::console('test'), null);

        return $importer->run($import['import_id']);
    }

    /**
     * Writes CSV text to a temp file and imports it.
     */
    protected function importCsv(string $csv): array
    {
        $path = $this->root.'-input-'.Str::random(6).'.csv';
        file_put_contents($path, $csv);

        try {
            return $this->import($path);
        } finally {
            @unlink($path);
        }
    }

    protected function company(): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics '.Str::random(4),
            'status' => true,
        ]);
    }

    protected function brokerUser(string $roleSlug, ?Company $company = null): User
    {
        $company ??= $this->company();

        $user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => $roleSlug.'-'.Str::random(6).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => $roleSlug === 'owner_admin',
        ]);

        $user->assignRole(Role::where('slug', $roleSlug)->firstOrFail());

        return $user->fresh();
    }

    /** A DollarTraq staff account: a seat, plus drayage:grant's permissions. */
    protected function staffUser(): User
    {
        $user = $this->brokerUser('viewer');
        $user->givePermissionTo(['manage-drayage-directory', 'export-drayage-directory']);

        return $user->fresh();
    }

    /** Header row of the real export, for hand-built CSVs. */
    protected function header(): array
    {
        $handle = fopen($this->fixturePath(), 'r');
        $header = fgetcsv($handle, null, ',', '"', '');
        fclose($handle);

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        return array_slice($header, 0, 121);
    }

    /**
     * A CSV of the real header with the given rows, each a label => value
     * map; unnamed columns are blank.
     */
    protected function csv(array $rows, ?array $header = null): string
    {
        $header ??= $this->header();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($label) => $row[$label] ?? '', $header), escape: '');
        }

        rewind($out);

        return stream_get_contents($out);
    }
}
