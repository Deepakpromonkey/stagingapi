<?php

namespace App\Mail;

use App\Models\CarrierReport;
use App\Models\User;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms to the teammate who filed an incident report that it was received.
 * Not to be confused with CarrierReportMail, the copy sent to the carrier.
 */
class CarrierReportReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public CarrierReport $report;

    public User $reporter;

    public function __construct(CarrierReport $report, User $reporter)
    {
        $this->report = $report;
        $this->reporter = $reporter;
    }

    public function build()
    {
        $report = $this->report;

        $rendered = app(EmailTemplateService::class)->resolve($report->company_id, 'carrier_report', [
            'first_name' => $this->reporter->first_name,
            'carrier_name' => $report->carrier_legal_name,
            'dot_number' => $report->carrier_dot_number,
            'report_id' => $report->uuid,
            'report_url' => rtrim((string) config('app.frontend_url'), '/').'/carriers/'.$report->carrier_row_id,
            'company_name' => $this->reporter->company?->company_name,
        ]);

        return $this->subject($rendered['subject'])
            ->view('emails.layouts.template', [
                'title' => $rendered['subject'],
                'body' => $rendered['body_html'],
            ]);
    }
}
