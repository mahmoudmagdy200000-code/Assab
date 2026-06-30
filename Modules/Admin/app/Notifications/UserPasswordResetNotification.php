<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-triggered password reset (Admin dashboard contract batch 1, A3/A6).
 * Carries the freshly generated temporary password — delivered via the mail
 * channel only, never returned in the API response or logged in plain text.
 */
class UserPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $temporaryPassword,
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
            ->subject('تم إعادة تعيين كلمة المرور — Your password was reset')
            ->greeting('مرحباً '.$notifiable->name)
            ->line('قام مسؤول النظام بإعادة تعيين كلمة المرور الخاصة بحسابك.')
            ->line('**كلمة المرور المؤقتة / Temporary Password:** '.$this->temporaryPassword)
            ->action('تسجيل الدخول / Login', $loginUrl)
            ->line('يرجى تسجيل الدخول وتغيير كلمة المرور فوراً.')
            ->salutation('فريق أساب — ASAB Team');
    }
}
