<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email for a brand owner provisioned from POST /admin/brands (B-A6).
 * When a fresh account is created it carries the one-time password; when an
 * existing user is merely linked to the brand it omits the password and tells
 * them to sign in with their current credentials. Mail channel only — the
 * password is never logged.
 */
class BrandOwnerWelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $brandName,
        private ?string $oneTimePassword = null,
    ) {}

    /** @return string[] */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $loginUrl = config('app.frontend_url').'/login';

        $mail = (new MailMessage)
            ->subject('مرحباً بك في أساب — Welcome to ASAB')
            ->greeting('مرحباً '.$notifiable->name)
            ->line('تم منحك ملكية العلامة التجارية "'.$this->brandName.'" على منصة أساب.')
            ->line('**البريد الإلكتروني / Email:** '.$notifiable->email);

        if ($this->oneTimePassword !== null) {
            $mail->line('**كلمة المرور المؤقتة / One-time Password:** '.$this->oneTimePassword)
                ->action('تسجيل الدخول / Login', $loginUrl)
                ->line('يرجى تسجيل الدخول وتغيير كلمة المرور فوراً.');
        } else {
            $mail->line('يمكنك الدخول باستخدام بيانات حسابك الحالية.')
                ->action('تسجيل الدخول / Login', $loginUrl);
        }

        return $mail->salutation('فريق أساب — ASAB Team');
    }
}
