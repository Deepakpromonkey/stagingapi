<?php

namespace App\Mail;

use App\Models\CarrierReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The copy of an incident report sent to the address the broker specified on
 * the report form.
 */
class CarrierReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public CarrierReport $report;

    public string $brokerName;

    public string $companyName;

    public function __construct(CarrierReport $report, string $brokerName, string $companyName)
    {
        $this->report = $report;
        $this->brokerName = $brokerName;
        $this->companyName = $companyName;
    }

    public function build()
    {
        $subject = 'Incident report filed by '.$this->companyName;

        if ($this->report->carrier_dot_number) {
            $subject .= ' (DOT '.$this->report->carrier_dot_number.')';
        }

        return $this->subject($subject)
            ->view('emails.carrier-report', [
                'incidentLabels' => $this->report->incidentLabels(),
            ]);
    }
}
