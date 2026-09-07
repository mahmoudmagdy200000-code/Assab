<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Self-service password reset (POST /auth/forgot-password). Carries the
 * single-use reset token — the flow wrote the `password_reset_tokens` row and
 * sent nothing, so the user waited on a mail that was never queued.
 *
 * Mail channel only, and always dispatched through CredentialMailer: the token
 * is a credential and must never reach the `log` mailer.
 */
class PasswordResetLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $token,
    ) {}

    /** @return string[] */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $resetUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.$this->token;

        return (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور — Reset your password')
            ->greeting('مرحباً '.$notifiable->name)
            ->line('تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك.')
            ->action('إعادة تعيين كلمة المرور / Reset Password', $resetUrl)
            ->line('الرابط صالح لمدة 60 دقيقة / This link expires in 60 minutes.')
            ->line('إذا لم تطلب ذلك، تجاهل هذه الرسالة.')
            ->salutation('فريق أساب — ASAB Team');
    }
}
