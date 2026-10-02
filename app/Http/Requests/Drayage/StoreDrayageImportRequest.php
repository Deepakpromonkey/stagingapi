<?php

namespace App\Http\Requests\Drayage;

use App\Services\Drayage\DrayageImporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /drayage/imports - one CSV (or LoadMatch JSON / JSONL) file.
 *
 * Checked three ways: the extension, the MIME type PHP detects from the
 * content, and - for text - that it really is UTF-8. A spreadsheet saved as
 * .xlsx and renamed .csv fails the MIME check here, rather than importing
 * as a page of binary noise.
 */
class StoreDrayageImportRequest extends FormRequest
{
    private const MIME_TYPES = [
        'text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel',
        'application/json', 'application/x-ndjson', 'application/jsonl', 'text/json',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('drayage.import.max_upload_kb'),
                'extensions:'.implode(',', DrayageImporter::FORMATS),
                'mimetypes:'.implode(',', self::MIME_TYPES),
            ],
            'format' => ['nullable', 'string', 'in:'.implode(',', DrayageImporter::FORMATS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $file = $this->file('file');

            if (! $file || ! $file->isValid() || $validator->errors()->has('file')) {
                return;
            }

            $handle = fopen($file->getRealPath(), 'r');
            $sample = fread($handle, 65536);
            fclose($handle);

            // A multibyte character cut at the sample's end is not an error.
            if (! mb_check_encoding($sample, 'UTF-8') && ! mb_check_encoding(mb_strcut($sample, 0, strlen($sample) - 3, 'UTF-8'), 'UTF-8')) {
                $validator->errors()->add('file', 'The file must be UTF-8 encoded.');
            }
        });
    }

    public function sourceFormat(): string
    {
        return $this->input('format') ?: strtolower($this->file('file')->getClientOriginalExtension());
    }
}
