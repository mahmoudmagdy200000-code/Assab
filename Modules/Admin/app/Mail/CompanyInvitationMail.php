<?php

namespace Modules\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * CMP-3 — the company-staff invitation email carrying the accept link. Queued so
 * inviting a member never blocks the request.
 */
class CompanyInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $roleLabel,
        public string $acceptUrl,
    ) {}

    public function build(): self
    {
        $body = '<div dir="rtl" style="font-family:sans-serif;font-size:14px">'
            .'<p>تمت دعوتك للانضمام إلى <strong>'.e($this->companyName).'</strong> بصفة «'.e($this->roleLabel).'».</p>'
            .'<p>لإكمال إنشاء حسابك، افتح الرابط التالي:</p>'
            .'<p><a href="'.e($this->acceptUrl).'">'.e($this->acceptUrl).'</a></p>'
            .'<p style="color:#888">تنتهي صلاحية الدعوة خلال 7 أيام.</p></div>';

        return $this->subject('دعوة للانضمام إلى '.$this->companyName)->html($body);
    }
}
