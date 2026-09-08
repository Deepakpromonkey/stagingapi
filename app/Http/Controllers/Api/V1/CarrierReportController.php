<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Carrier\StoreCarrierReportRequest;
use App\Mail\CarrierReportMail;
use App\Models\CarrierReport;
use App\Models\CarrierReportDocument;
use App\Models\Carriers\Carrier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Incident reports a broker files against a carrier.
 *
 * Reports are owned by the company, not the individual, so the whole team sees
 * what has been filed. `is_private` narrows a report back down to the company
 * that wrote it — everything else is readable by any broker viewing that
 * carrier's profile.
 */
class CarrierReportController extends BaseController
{
    /** The checklist the modal renders, so the labels live in one place. */
    public function incidents()
    {
        $incidents = collect(CarrierReport::INCIDENTS)
            ->map(fn ($label, $slug) => ['value' => $slug, 'label' => $label])
            ->values();

        return $this->success($incidents, 'Incident types retrieved.');
    }

    /**
     * Every report against one carrier.
     *
     * A private report is still shown — the incident is a warning the whole
     * network benefits from, and hiding it would let a carrier's history look
     * clean to the next broker. What "private" withholds is the reporter's
     * identity, not the report: for anyone outside the filing company the
     * broker and user names are stripped before the payload is built, so they
     * never reach the client at all.
     */
    public function index(Request $request)
    {
        $request->validate([
            'row_id' => ['required', 'string', 'max:50'],
        ]);

        $rowId = trim((string) $request->input('row_id'));

        $companyId = $request->user()->company_id;

        /*
        | Answered entirely from the local table.
        |
        | This used to resolve the row_id against the carrier database on EC2
        | first, purely to turn it into the carrier_id to filter on. That cost
        | the profile screen a remote round trip it did not need — and worse, a
        | `where row_id = ?` against the `carriers` view is a full scan of the
        | census file, so the reports panel waited on a ~21 second query and
        | frequently timed out before it could render anything.
        |
        | The identifiers are denormalised onto every report for exactly this
        | reason, and both columns are indexed. row_id and dot_number are the
        | same value in different types — the view defines row_id as
        | CAST(dot_number AS CHAR) — so either column answers the question, and
        | matching both keeps older rows that only filled one of them readable.
        |
        | An unknown carrier now yields an empty list rather than a 404: the
        | panel wants "no reports", and the external lookup that could once
        | tell the two apart is the thing being removed.
        */
        $reports = CarrierReport::query()
            ->where(function ($query) use ($rowId) {
                $query->where('carrier_row_id', $rowId)
                    ->orWhere('carrier_dot_number', $rowId);
            })
            ->with([
                'user:id,first_name,last_name',
                'company:id,company_name',
                'documents',
            ])
            ->latest()
            ->get()
            ->map(function (CarrierReport $report) use ($companyId) {
                $isOwn = $report->company_id === $companyId;

                /*
                | The identity is withheld here, in the payload, rather than
                | left to the client to hide. Anything sent is readable in the
                | network tab whether or not it is drawn on screen, so a private
                | report's attribution must not be serialised at all.
                */
                $showsReporter = $isOwn || ! $report->is_private;

                return [
                    'uuid' => $report->uuid,
                    'incident_date' => $report->incident_date?->format('Y-m-d'),
                    'origin' => [
                        'city' => $report->origin_city,
                        'state' => $report->origin_state,
                        'country' => $report->origin_country,
                    ],
                    'destination' => [
                        'city' => $report->destination_city,
                        'state' => $report->destination_state,
                        'country' => $report->destination_country,
                    ],
                    'incidents' => $report->incidents,
                    'incident_labels' => $report->incidentLabels(),
                    'comments' => $report->comments,
                    'is_private' => $report->is_private,
                    'is_own_company' => $isOwn,

                    'reported_by' => $showsReporter && $report->user
                        ? trim($report->user->first_name.' '.$report->user->last_name)
                        : null,
                    'reported_by_company' => $showsReporter
                        ? $report->company?->company_name
                        : null,

                    /*
                    | Evidence. The metadata is always listed, so a reader can
                    | see the report is substantiated, but the file itself
                    | follows the same rule as the reporter's identity: an
                    | attachment is usually a rate confirmation or an email
                    | thread, and those carry the very details `is_private`
                    | exists to keep inside the filing company.
                    */
                    'documents' => $report->documents->map(fn (CarrierReportDocument $document) => [
                        'uuid' => $document->uuid,
                        'name' => $document->name,
                        'size' => $document->size,
                        'mime' => $document->mime,
                        'download_url' => $showsReporter
                            ? url('/api/v1/carrier-reports/documents/'.$document->uuid)
                            : null,
                    ])->values(),

                    // Delivery detail is the reporter's business, not the wider
                    // audience's.
                    'carrier_email' => $isOwn ? $report->carrier_email : null,
                    'emailed_at' => $isOwn ? $report->emailed_at?->toDateTimeString() : null,

                    'reported_at' => $report->created_at?->format('m/d/y'),
                ];
            });

        return $this->success($reports, 'Carrier reports retrieved.');
    }

    public function store(StoreCarrierReportRequest $request)
    {
        /*
        | findByRowId, not where('row_id', ...).
        |
        | `carriers` defines row_id as CAST(dot_number AS CHAR), so filtering on
        | it wraps the indexed column in a function and MySQL scans the whole
        | census file — around 21 seconds against the live database, which is
        | most of what made filing a report feel like it had hung. The helper
        | matches on dot_number instead and returns the same row from the index.
        */
        $carrier = Carrier::findByRowId($request->row_id);

        if (! $carrier) {
            return $this->error('Carrier not found in system.', null, 404);
        }

        $user = $request->user();

        /*
        | The uuid is settled before anything is written so the attachments can
        | be filed under it, which keeps one report's evidence together on disk
        | and off the guessable path a sequential id would give.
        */
        $uuid = (string) Str::uuid();

        /*
        | Files first. A report whose evidence failed to save is worse than no
        | report at all — the broker would believe the attachment was filed and
        | never send it again — so nothing is written to the database until
        | every file is safely on the disk.
        */
        try {
            $stored = $this->storeDocuments($request->file('documents', []), $user->company_id, $uuid);
        } catch (\Throwable $e) {
            Log::error('Carrier report attachment failed', [
                'company_id' => $user->company_id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(
                'Could not save the attachments. Please try again.',
                null,
                500
            );
        }

        $report = CarrierReport::create([
            'uuid' => $uuid,

            'company_id' => $user->company_id,
            'user_id' => $user->id,

            'carrier_id' => $carrier->id,
            'carrier_row_id' => $carrier->row_id,
            'carrier_dot_number' => $carrier->dot_number,
            'carrier_legal_name' => $carrier->legal_name ?? $carrier->dba_name,

            'incident_date' => $request->incident_date,

            'origin_city' => $request->origin_city,
            'origin_state' => $request->origin_state,
            'origin_country' => $request->origin_country,

            'destination_city' => $request->destination_city,
            'destination_state' => $request->destination_state,
            'destination_country' => $request->destination_country,

            // Duplicate checkboxes would inflate the list without changing it.
            'incidents' => array_values(array_unique($request->incidents)),

            'comments' => $request->comments,
            'is_private' => $request->boolean('is_private'),

            'carrier_email' => $request->carrier_email,
        ]);

        foreach ($stored as $document) {
            $report->documents()->create($document);
        }

        $report->load('documents');

        $emailed = false;
        $emailError = null;

        if ($report->carrier_email) {
            $brokerName = trim($user->first_name.' '.$user->last_name);
            $companyName = $user->company?->company_name ?? $brokerName;

            try {
                Mail::to($report->carrier_email)->send(
                    new CarrierReportMail($report, $brokerName, $companyName)
                );

                $report->forceFill(['emailed_at' => now(), 'email_error' => null])->save();

                $emailed = true;
            } catch (\Throwable $e) {
                // The report itself is the record of value — a bad mailbox or a
                // down SMTP host must not cost the broker their submission, so
                // the failure is recorded on the row and reported back instead.
                $emailError = $e->getMessage();

                $report->forceFill(['email_error' => $emailError])->save();

                Log::error('Carrier report email failed', [
                    'report_uuid' => $report->uuid,
                    'error' => $emailError,
                ]);
            }
        }

        return $this->success([
            'uuid' => $report->uuid,
            'incidents' => $report->incidents,
            'incident_labels' => $report->incidentLabels(),
            'is_private' => $report->is_private,
            'carrier_email' => $report->carrier_email,
            'emailed' => $emailed,
            'email_error' => $emailError,

            // Echoed back so the client can show what was filed rather than
            // assume the upload worked.
            'documents' => $report->documents->map(fn (CarrierReportDocument $document) => [
                'uuid' => $document->uuid,
                'name' => $document->name,
                'size' => $document->size,
                'mime' => $document->mime,
                'download_url' => url('/api/v1/carrier-reports/documents/'.$document->uuid),
            ])->values(),
        ], $this->storeMessage($report, $emailed, $emailError), 201);
    }

    /**
     * Stream one attachment back to a broker entitled to see it.
     *
     * Files are held on a private disk and served through here rather than
     * linked to directly, so entitlement is checked on every fetch.
     */
    public function download(Request $request, string $uuid)
    {
        $document = CarrierReportDocument::with('report')->where('uuid', $uuid)->first();

        if (! $document || ! $document->report) {
            return $this->error('Attachment not found.', null, 404);
        }

        $report = $document->report;

        /*
        | The same rule the listing applies: the filing company always, and
        | everyone else only while the report is not private. A 404 rather than
        | a 403 — confirming a private report's attachment exists would leak
        | the very thing the flag protects.
        */
        $isOwn = $report->company_id === $request->user()->company_id;

        if (! $isOwn && $report->is_private) {
            return $this->error('Attachment not found.', null, 404);
        }

        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            Log::warning('Carrier report attachment missing from disk', [
                'document_uuid' => $document->uuid,
                'disk' => $document->disk,
                'path' => $document->path,
            ]);

            return $this->error('Attachment is no longer available.', null, 404);
        }

        return $disk->download($document->path, $document->name);
    }

    /**
     * Put every attachment on the disk, returning the rows to record.
     *
     * All or nothing: if one file fails, those already written are removed
     * again so a half-filed report cannot reach the database.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @return list<array{disk: string, path: string, name: string, size: ?int, mime: ?string}>
     */
    private function storeDocuments(array $files, int $companyId, string $reportUuid): array
    {
        if (! $files) {
            return [];
        }

        $disk = config('filesystems.default');

        // Under the company, then the report: the same shape the onboarding
        // documents use, so one company's evidence never mixes with another's.
        $directory = 'carrier-reports/'.$companyId.'/'.$reportUuid;

        $stored = [];

        try {
            foreach ($files as $file) {
                $extension = $file->getClientOriginalExtension();

                $name = Str::random(20).($extension ? '.'.$extension : '');

                $path = Storage::disk($disk)->putFileAs($directory, $file, $name);

                if (! $path) {
                    throw new \RuntimeException('Storage rejected '.$file->getClientOriginalName());
                }

                $stored[] = [
                    'disk' => $disk,
                    'path' => $path,

                    // The broker's own filename is kept for display and for the
                    // download, but never used as the path — it is user input.
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                ];
            }
        } catch (\Throwable $e) {
            foreach ($stored as $document) {
                Storage::disk($document['disk'])->delete($document['path']);
            }

            throw $e;
        }

        return $stored;
    }

    private function storeMessage(CarrierReport $report, bool $emailed, ?string $emailError): string
    {
        if ($emailed) {
            return 'Report saved and sent to '.$report->carrier_email.'.';
        }

        if ($emailError) {
            return 'Report saved, but the email could not be delivered to '.$report->carrier_email.'.';
        }

        return 'Report saved.';
    }
}
