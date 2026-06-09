<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email for an auto-created company-admin (FE completion request §1.4).
 * Carries the one-time password generated at creation — delivered via the mail
 * channel, never logged in plain text.
 */
class CompanyAdminWelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $companyName,
        private string $oneTimePassword,
    ) {}

    /** @return string[] */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $loginUrl = config('app.frontend_url').'/login';

        return (new MailMessage)
            ->subject('مرحباً بك في أساب — Welcome to ASAB')
            ->greeting('مرحباً '.$notifiable->name)
            ->line('تم إنشاء حساب مدير الشركة الخاص بك لـ "'.$this->companyName.'".')
            ->line('**البريد الإلكتروني / Email:** '.$notifiable->email)
            ->line('**كلمة المرور المؤقتة / One-time Password:** '.$this->oneTimePassword)
            ->action('تسجيل الدخول / Login', $loginUrl)
            ->line('يرجى تسجيل الدخول وتغيير كلمة المرور فوراً.')
            ->salutation('فريق أساب — ASAB Team');
    }
}
