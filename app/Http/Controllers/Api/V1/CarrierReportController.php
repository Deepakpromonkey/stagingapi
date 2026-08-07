<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Carrier\StoreCarrierReportRequest;
use App\Mail\CarrierReportMail;
use App\Models\CarrierReport;
use App\Models\Carriers\Carrier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $carrier = Carrier::where('row_id', $request->input('row_id'))->first();

        if (! $carrier) {
            return $this->error('Carrier not found in system.', null, 404);
        }

        $companyId = $request->user()->company_id;

        $reports = CarrierReport::where('carrier_id', $carrier->id)
            ->with(['user:id,first_name,last_name', 'company:id,company_name'])
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
        $carrier = Carrier::where('row_id', $request->row_id)->first();

        if (! $carrier) {
            return $this->error('Carrier not found in system.', null, 404);
        }

        $user = $request->user();

        $report = CarrierReport::create([
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
        ], $this->storeMessage($report, $emailed, $emailError), 201);
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
