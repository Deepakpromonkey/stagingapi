<?php

namespace App\Services;

use App\Models\BrokerAgreementDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Broker agreement documents, stored on S3 and owned by the company.
 */
class AgreementDocumentService
{
    protected function disk(): string
    {
        return config('agreements.disk', 's3');
    }

    public function list(User $user, ?bool $activeOnly = null, int $perPage = 15)
    {
        return BrokerAgreementDocument::forCompany($user->company_id)
            ->with('uploader:id,first_name,last_name')
            ->when($activeOnly !== null, fn ($query) => $query->where('is_active', $activeOnly))
            ->latest()
            ->paginate($perPage);
    }

    public function create(User $user, array $data, UploadedFile $file): BrokerAgreementDocument
    {
        $path = $this->store($user->company_id, $file);

        try {
            return BrokerAgreementDocument::create([
                'uuid' => Str::uuid(),
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'disk' => $this->disk(),
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_active' => $data['is_active'] ?? true,
            ]);
        } catch (\Throwable $e) {
            // Don't leave an orphaned object in the bucket.
            Storage::disk($this->disk())->delete($path);

            throw $e;
        }
    }

    public function update(BrokerAgreementDocument $document, array $data, ?UploadedFile $file = null): BrokerAgreementDocument
    {
        return DB::transaction(function () use ($document, $data, $file) {
            $previousPath = null;

            if ($file) {
                $previousPath = $document->file_path;

                $document->fill([
                    'disk' => $this->disk(),
                    'file_path' => $this->store($document->company_id, $file),
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            $document->fill(array_filter(
                [
                    'title' => $data['title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'is_active' => $data['is_active'] ?? null,
                ],
                fn ($value) => $value !== null
            ));

            // description is nullable on purpose: an explicit null clears it.
            if (array_key_exists('description', $data)) {
                $document->description = $data['description'];
            }

            $document->save();

            if ($previousPath) {
                $this->deleteFile($document->disk, $previousPath);
            }

            return $document->fresh('uploader');
        });
    }

    public function delete(BrokerAgreementDocument $document): void
    {
        $disk = $document->disk;
        $path = $document->file_path;

        $document->delete();

        $this->deleteFile($disk, $path);
    }

    /**
     * Objects are namespaced per company and given a random name so the
     * original filename can never collide or leak into the object key.
     */
    protected function store(int $companyId, UploadedFile $file): string
    {
        return $file->storeAs(
            "agreements/{$companyId}",
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            ['disk' => $this->disk()]
        );
    }

    /**
     * A failed cleanup must not fail the request — the row is already gone.
     */
    protected function deleteFile(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Agreement document file cleanup failed', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
