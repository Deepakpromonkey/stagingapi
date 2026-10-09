<?php

namespace App\Services\Drayage;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only code that reads or writes the drayage JSON files.
 *
 * Layout under config('drayage.root'):
 *
 *   current.json                          live dataset pointer
 *   audit.jsonl                           append-only audit trail
 *   imports/{import_id}.json              import status + report
 *   imports/originals/{import_id}.{ext}   the uploaded file, as received
 *   datasets/{dataset_id}/manifest.json
 *   datasets/{dataset_id}/carriers/{carrier_key}.json
 *   datasets/{dataset_id}/index/records.json
 *   datasets/{dataset_id}/index/lookup_usdot.json | lookup_mc.json | lookup_scac.json
 *   datasets/{dataset_id}/index/facets.json
 *
 * Every write lands in a temp file first and is renamed into place, so a
 * reader never sees half a file. A dataset is built in a `.building-*`
 * folder and renamed to its final name only when every file is written - a
 * folder under datasets/ without that prefix is complete by construction.
 * Going live is a rename of current.json, so a request reads either the old
 * dataset or the new one, never a mix.
 *
 * Identifiers are checked against strict patterns before any path is built
 * from them, so nothing that arrives in a URL can walk out of the root.
 */
class DrayageStorage
{
    public const CARRIER_KEY_PATTERN = '/^(lm-[A-Za-z0-9]{1,32}|ls-[a-f0-9]{12})$/';

    /**
     * Ids sort in creation order: UTC time to the millisecond, then a random
     * suffix. Retention and "newest first" rely on that.
     */
    public const DATASET_ID_PATTERN = '/^ds-\d{8}T\d{9}Z-[a-f0-9]{6}$/';

    public const IMPORT_ID_PATTERN = '/^imp-\d{8}T\d{9}Z-[a-f0-9]{6}$/';

    private const INDEX_FILES = ['records', 'lookup_usdot', 'lookup_mc', 'lookup_scac', 'facets'];

    /*
    | Two users share this store: PHP-FPM (www-data) takes uploads and serves
    | reads, the scheduler's queue worker (the deploy user) runs imports. The
    | storage tree is group www-data with setgid, so folders and files are
    | made group-writable explicitly rather than left to each process's umask
    | - www-data's 022 would otherwise lock the worker out of whatever the API
    | created. The root itself is closed to everyone else.
    |
    | Folders are only chmod'ed when they need it, keeping the setgid bit they
    | inherit: Linux drops setgid when someone outside the folder's group
    | changes its mode, and the deploy user is not in www-data.
    */
    private const ROOT_MODE = 0770;

    private const DIR_MODE = 0775;

    private const FILE_MODE = 0664;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function root(): string
    {
        return rtrim(config('drayage.root'), '/');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Identifiers
    // ─────────────────────────────────────────────────────────────────────

    public static function newImportId(): string
    {
        return 'imp-'.self::timestamp().'-'.bin2hex(random_bytes(3));
    }

    public static function newDatasetId(): string
    {
        return 'ds-'.self::timestamp().'-'.bin2hex(random_bytes(3));
    }

    private static function timestamp(): string
    {
        $now = microtime(true);

        return gmdate('Ymd\THis', (int) $now).sprintf('%03d', (int) (($now - floor($now)) * 1000)).'Z';
    }

    public static function isCarrierKey(string $key): bool
    {
        return (bool) preg_match(self::CARRIER_KEY_PATTERN, $key);
    }

    public static function isDatasetId(string $id): bool
    {
        return (bool) preg_match(self::DATASET_ID_PATTERN, $id);
    }

    public static function isImportId(string $id): bool
    {
        return (bool) preg_match(self::IMPORT_ID_PATTERN, $id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Live pointer
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array{dataset_id: string, activated_at: string, activated_by: array|null, previous_dataset_id: string|null}|null
     */
    public function current(): ?array
    {
        $current = $this->readJson($this->root().'/current.json');

        if (! $current || ! self::isDatasetId($current['dataset_id'] ?? '')) {
            return null;
        }

        return $current;
    }

    public function currentDatasetId(): ?string
    {
        return $this->current()['dataset_id'] ?? null;
    }

    /**
     * Points current.json at a complete dataset.
     */
    public function activate(string $datasetId, ?array $actor): array
    {
        $this->assertDatasetId($datasetId);

        if (! $this->datasetExists($datasetId)) {
            throw new RuntimeException("Dataset {$datasetId} does not exist.");
        }

        return $this->withLock('current', function () use ($datasetId, $actor) {
            $previous = $this->currentDatasetId();

            $pointer = [
                'dataset_id' => $datasetId,
                'activated_at' => now()->toIso8601String(),
                'activated_by' => $actor,
                'previous_dataset_id' => $previous !== $datasetId ? $previous : ($this->current()['previous_dataset_id'] ?? null),
            ];

            $this->writeJson($this->root().'/current.json', $pointer, pretty: true);

            return $pointer;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Imports
    // ─────────────────────────────────────────────────────────────────────

    public function saveImport(array $import): void
    {
        $this->assertImportId($import['import_id']);

        $this->writeJson($this->root()."/imports/{$import['import_id']}.json", $import, pretty: true);
    }

    public function import(string $importId): ?array
    {
        if (! self::isImportId($importId)) {
            return null;
        }

        return $this->readJson($this->root()."/imports/{$importId}.json");
    }

    /**
     * Newest first.
     *
     * @return list<array>
     */
    public function imports(): array
    {
        $files = glob($this->root().'/imports/imp-*.json') ?: [];
        rsort($files);

        return array_values(array_filter(array_map(fn ($f) => $this->readJson($f), $files)));
    }

    /**
     * Copies an uploaded or local file in as an import's original. Returns
     * the path relative to the root, which is what the manifest records.
     */
    public function storeOriginal(string $sourcePath, string $importId, string $extension): string
    {
        $this->assertImportId($importId);

        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'dat';
        $relative = "imports/originals/{$importId}.{$extension}";
        $target = $this->root().'/'.$relative;

        $this->ensureDirectory(dirname($target));

        $temp = $target.'.tmp-'.Str::random(8);

        if (! copy($sourcePath, $temp) || ! chmod($temp, self::FILE_MODE) || ! rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('Could not store the uploaded file.');
        }

        return $relative;
    }

    public function absolute(string $relative): string
    {
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Invalid storage path.');
        }

        return $this->root().'/'.$relative;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Building a dataset
    // ─────────────────────────────────────────────────────────────────────

    public function beginDataset(string $datasetId): void
    {
        $this->assertDatasetId($datasetId);

        $building = $this->buildingPath($datasetId);

        $this->ensureDirectory($building.'/carriers');
        $this->ensureDirectory($building.'/index');
    }

    public function writeCarrier(string $datasetId, string $carrierKey, array $document): void
    {
        $this->assertCarrierKey($carrierKey);

        // Not renamed into place one by one - the whole folder is, later.
        $this->put($this->buildingPath($datasetId)."/carriers/{$carrierKey}.json", json_encode($document, self::JSON_FLAGS));
    }

    public function writeIndex(string $datasetId, string $name, array $data): void
    {
        if (! in_array($name, self::INDEX_FILES, true)) {
            throw new RuntimeException("Unknown index file {$name}.");
        }

        $this->put($this->buildingPath($datasetId)."/index/{$name}.json", json_encode($data, self::JSON_FLAGS));
    }

    /**
     * Writes the manifest and moves the finished folder into place.
     */
    public function commitDataset(string $datasetId, array $manifest): void
    {
        $building = $this->buildingPath($datasetId);

        $this->put($building.'/manifest.json', json_encode($manifest, self::JSON_FLAGS | JSON_PRETTY_PRINT));

        if (! rename($building, $this->datasetPath($datasetId))) {
            throw new RuntimeException("Could not commit dataset {$datasetId}.");
        }
    }

    public function discardBuilding(string $datasetId): void
    {
        $this->assertDatasetId($datasetId);

        $this->deleteTree($this->buildingPath($datasetId));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reading a dataset
    // ─────────────────────────────────────────────────────────────────────

    public function datasetExists(string $datasetId): bool
    {
        return self::isDatasetId($datasetId) && is_file($this->datasetPath($datasetId).'/manifest.json');
    }

    public function manifest(string $datasetId): ?array
    {
        if (! self::isDatasetId($datasetId)) {
            return null;
        }

        return $this->readJson($this->datasetPath($datasetId).'/manifest.json');
    }

    /**
     * Complete datasets, newest first.
     *
     * @return list<array>
     */
    public function datasets(): array
    {
        $dirs = glob($this->root().'/datasets/ds-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);

        return array_values(array_filter(array_map(
            fn ($dir) => $this->manifest(basename($dir)),
            $dirs
        )));
    }

    public function index(string $datasetId, string $name): ?array
    {
        if (! in_array($name, self::INDEX_FILES, true)) {
            throw new RuntimeException("Unknown index file {$name}.");
        }

        if (! self::isDatasetId($datasetId)) {
            return null;
        }

        return $this->readJson($this->datasetPath($datasetId)."/index/{$name}.json");
    }

    public function carrier(string $datasetId, string $carrierKey): ?array
    {
        if (! self::isDatasetId($datasetId) || ! self::isCarrierKey($carrierKey)) {
            return null;
        }

        return $this->readJson($this->datasetPath($datasetId)."/carriers/{$carrierKey}.json");
    }

    public function datasetBytes(string $datasetId): int
    {
        $this->assertDatasetId($datasetId);

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->datasetPath($datasetId), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deleting and pruning
    // ─────────────────────────────────────────────────────────────────────

    public function deleteDataset(string $datasetId): void
    {
        $this->assertDatasetId($datasetId);

        $this->withLock('current', function () use ($datasetId) {
            if ($this->currentDatasetId() === $datasetId) {
                throw new RuntimeException('The live dataset cannot be deleted.');
            }

            $manifest = $this->manifest($datasetId);

            $this->deleteTree($this->datasetPath($datasetId));

            if ($original = $manifest['source_storage_path'] ?? null) {
                @unlink($this->absolute($original));
            }
        });
    }

    /**
     * Keeps the newest `$keep` datasets plus the live one, whichever is
     * older. Only ever called after a successful activation.
     *
     * @return list<string> the dataset ids removed
     */
    public function prune(int $keep): array
    {
        $keep = max(1, $keep);
        $current = $this->currentDatasetId();
        $removed = [];

        foreach (array_slice($this->datasets(), $keep) as $manifest) {
            if ($manifest['dataset_id'] === $current) {
                continue;
            }

            $this->deleteDataset($manifest['dataset_id']);
            $removed[] = $manifest['dataset_id'];
        }

        // A build that died half way (killed worker, full disk) leaves its
        // folder behind; nothing will ever finish it.
        foreach (glob($this->root().'/datasets/.building-*', GLOB_ONLYDIR) ?: [] as $stale) {
            if (filemtime($stale) < time() - 86400) {
                $this->deleteTree($stale);
            }
        }

        // Originals of failed imports have no dataset to be pruned with.
        $failed = array_filter($this->imports(), fn ($i) => ($i['status'] ?? null) === 'failed' && ! empty($i['source_storage_path']));
        foreach (array_slice(array_values($failed), $keep) as $import) {
            @unlink($this->absolute($import['source_storage_path']));
        }

        return $removed;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Audit
    // ─────────────────────────────────────────────────────────────────────

    public function appendAudit(array $entry): void
    {
        $this->ensureDirectory($this->root());

        $path = $this->root().'/audit.jsonl';
        $new = ! is_file($path);

        if (file_put_contents($path, json_encode($entry, self::JSON_FLAGS)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not append to the drayage audit log.');
        }

        if ($new) {
            @chmod($path, self::FILE_MODE);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Backup
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compressed copy of an activated dataset on the backup disk. Best
     * effort: a failed backup is logged and never fails the import.
     */
    public function backup(string $datasetId): ?string
    {
        if (! config('drayage.backup.enabled')) {
            return null;
        }

        $this->assertDatasetId($datasetId);

        $tar = sys_get_temp_dir()."/{$datasetId}.tar";

        try {
            @unlink($tar);
            @unlink($tar.'.gz');

            $archive = new \PharData($tar);
            $archive->buildFromDirectory($this->datasetPath($datasetId));
            $archive->compress(\Phar::GZ);
            unset($archive);

            $key = rtrim(config('drayage.backup.prefix'), '/')."/{$datasetId}.tar.gz";

            $stream = fopen($tar.'.gz', 'r');
            Storage::disk(config('drayage.backup.disk'))->writeStream($key, $stream);
            is_resource($stream) && fclose($stream);

            return $key;
        } catch (\Throwable $e) {
            Log::channel('drayage')->warning('Drayage dataset backup failed', [
                'dataset_id' => $datasetId,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($tar);
            @unlink($tar.'.gz');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Plumbing
    // ─────────────────────────────────────────────────────────────────────

    private function datasetPath(string $datasetId): string
    {
        return $this->root()."/datasets/{$datasetId}";
    }

    private function buildingPath(string $datasetId): string
    {
        return $this->root()."/datasets/.building-{$datasetId}";
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::channel('drayage')->error('Unreadable drayage file', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $path, array $data, bool $pretty = false): void
    {
        $this->ensureDirectory(dirname($path));

        $temp = $path.'.tmp-'.Str::random(8);
        $json = json_encode($data, self::JSON_FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0));

        if (file_put_contents($temp, $json, LOCK_EX) === false || ! chmod($temp, self::FILE_MODE) || ! rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException("Could not write {$path}.");
        }
    }

    /**
     * Serializes read-modify-write cycles on shared files (current.json)
     * across processes - an activation from the API and one from an import
     * job must not interleave. A cache lock rather than flock(): the two run
     * as different users, and a lock file one of them created could be
     * unopenable for the other.
     */
    private function withLock(string $name, callable $callback): mixed
    {
        return self::lockStore()->lock('drayage:'.$name, 30)->block(15, $callback);
    }

    /**
     * The cache store drayage locks live in: the configured drayage store,
     * or the app's default when that is set to `none`.
     */
    public static function lockStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('drayage.cache.store');

        return \Illuminate\Support\Facades\Cache::store($store === 'none' ? null : $store);
    }

    private function put(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }

        chmod($path, self::FILE_MODE);
    }

    /**
     * Creates each missing folder down to $path with the shared mode; the
     * root gets the closed one.
     */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        $this->ensureDirectory(dirname($path));

        if (! @mkdir($path) && ! is_dir($path)) {
            throw new RuntimeException("Could not create {$path}.");
        }

        $perms = fileperms($path);

        if ($perms !== false && ($perms & 0070) !== 0070) {
            @chmod($path, ($perms & 02000) | ($path === $this->root() ? self::ROOT_MODE : self::DIR_MODE));
        }
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }

    private function assertCarrierKey(string $key): void
    {
        if (! self::isCarrierKey($key)) {
            throw new RuntimeException('Invalid carrier key.');
        }
    }

    private function assertDatasetId(string $id): void
    {
        if (! self::isDatasetId($id)) {
            throw new RuntimeException('Invalid dataset id.');
        }
    }

    private function assertImportId(string $id): void
    {
        if (! self::isImportId($id)) {
            throw new RuntimeException('Invalid import id.');
        }
    }
}
