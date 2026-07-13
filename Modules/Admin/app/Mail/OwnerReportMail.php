<?php

namespace Modules\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * RPT-3 / BRO-1.2 — the financial report a brand owner receives by email, with
 * the rendered PDF/Excel attached. Sent from inside SendOwnerReportJob (already
 * queued), so this mailable itself stays synchronous.
 */
class OwnerReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public string $attachmentContents,
        public string $attachmentName,
        public string $attachmentMime,
    ) {}

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->html('<div dir="rtl" style="font-family:sans-serif;font-size:14px">'.$this->bodyHtml.'</div>')
            ->attachData($this->attachmentContents, $this->attachmentName, ['mime' => $this->attachmentMime]);
    }
}
