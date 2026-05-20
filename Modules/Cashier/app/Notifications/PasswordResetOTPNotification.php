<?php

namespace Modules\Cashier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOTPNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $otp
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password Reset OTP - Assab')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You have requested to reset your password.')
            ->line('Your OTP code is: **'.$this->otp.'**')
            ->line('This code will expire in 10 minutes.')
            ->line('If you did not request a password reset, please ignore this email.')
            ->salutation('Best regards, Assab Team');
    }
}
