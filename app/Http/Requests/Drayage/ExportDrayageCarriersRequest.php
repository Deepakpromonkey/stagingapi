<?php

namespace App\Http\Requests\Drayage;

/**
 * GET /drayage/export - the list endpoint's filters and sort, plus a format.
 *
 * Pagination and facets mean nothing for a file and are refused like any
 * other unknown parameter. XLSX is part of the contract but not built yet
 * (it needs a spreadsheet library this codebase does not have); asking for
 * it is a 422 that says so rather than a CSV with the wrong extension.
 */
class ExportDrayageCarriersRequest extends ListDrayageCarriersRequest
{
    public const INCLUDES = [];

    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['page'], $rules['per_page'], $rules['facets'], $rules['include']);

        $rules['format'] = ['nullable', 'string', 'in:csv'];

        return $rules;
    }

    public function messages(): array
    {
        return parent::messages() + [
            'format.in' => 'Only format=csv is available. XLSX export is not enabled yet.',
        ];
    }
}
