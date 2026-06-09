<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a 2FA login code (FE completion request §3.1, SMS method). Ships over
 * the mail channel by default; swap `via()` to an SMS channel (e.g. vonage) once
 * a provider is configured for AsabUser.
 */
class TwoFactorCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private string $code) {}

    /** @return string[] */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رمز التحقق — ASAB verification code')
            ->line('رمز الدخول الخاص بك / Your verification code:')
            ->line('**'.$this->code.'**')
            ->line('ينتهي خلال 30 ثانية / Expires in ~30 seconds.');
    }
}
