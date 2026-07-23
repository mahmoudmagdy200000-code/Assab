<?php

namespace Modules\BrandOwner\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A Brand Owner financial report delivered by email with the rendered PDF/Excel
 * attached. Dispatched from SendFinancialReportJob (already queued), so this
 * mailable itself stays synchronous.
 */
class FinancialReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $reportTitle,
        public string $attachmentContents,
        public string $attachmentName,
        public string $attachmentMime,
    ) {}

    public function build(): self
    {
        $body = 'Please find attached your report: '.e($this->reportTitle).'.';

        return $this->subject($this->reportTitle)
            ->html('<div style="font-family:sans-serif;font-size:14px">'.$body.'</div>')
            ->attachData($this->attachmentContents, $this->attachmentName, ['mime' => $this->attachmentMime]);
    }
}
