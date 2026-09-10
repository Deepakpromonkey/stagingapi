<?php

namespace App\Http\Requests\Carrier;

use App\Models\CarrierReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarrierReportRequest extends FormRequest
{
    /** Enough for the paperwork and a handful of photographs. */
    public const MAX_DOCUMENTS = 10;

    /** Per file, in kilobytes. 20MB — a phone photo clears this comfortably. */
    public const MAX_DOCUMENT_KB = 20480;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row_id' => ['required', 'string', 'max:50'],

            // An incident cannot have happened tomorrow.
            'incident_date' => ['required', 'date', 'before_or_equal:today'],

            'origin_city' => ['required', 'string', 'max:120'],
            'origin_state' => ['required', 'string', 'max:100'],
            'origin_country' => ['required', 'string', 'max:100'],

            'destination_city' => ['required', 'string', 'max:120'],
            'destination_state' => ['required', 'string', 'max:100'],
            'destination_country' => ['required', 'string', 'max:100'],

            'incidents' => ['required', 'array', 'min:1'],
            'incidents.*' => [Rule::in(array_keys(CarrierReport::INCIDENTS))],

            'comments' => ['nullable', 'string', 'max:5000'],

            'is_private' => ['sometimes', 'boolean'],

            'carrier_email' => ['nullable', 'email', 'max:255'],

            /*
            | Evidence for the incident. Optional, but when files are sent they
            | have to be declared here — anything absent from these rules is
            | dropped by validated(), which is how attachments were being
            | silently discarded before.
            |
            | The extensions are the ones a broker actually has to hand: the
            | paperwork, and photographs of the freight or equipment.
            */
            'documents' => ['sometimes', 'array', 'max:'.self::MAX_DOCUMENTS],
            'documents.*' => [
                'file',
                'max:'.self::MAX_DOCUMENT_KB,
                'mimes:pdf,jpg,jpeg,png,heic,webp,doc,docx,xls,xlsx,csv,txt,eml,msg',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'row_id.required' => 'Select a carrier to report.',
            'incident_date.before_or_equal' => 'The incident date cannot be in the future.',
            'incidents.required' => 'Check at least one incident.',
            'incidents.min' => 'Check at least one incident.',
            'incidents.*.in' => 'One of the selected incidents is not recognised.',

            'documents.max' => 'You can attach at most '.self::MAX_DOCUMENTS.' files to a report.',
            'documents.*.max' => 'Each attachment must be '.(self::MAX_DOCUMENT_KB / 1024).'MB or smaller.',
            'documents.*.mimes' => 'Attachments must be a document, spreadsheet, email or image.',
        ];
    }
}
