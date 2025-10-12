<?php

namespace Modules\Cashier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CashierActivationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $token,
        private string $defaultPassword
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $activationUrl = config('app.frontend_url') . '/cashier/activate?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Welcome to Assab - Activate Your Account')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your cashier account has been created by your branch manager.')
            ->line('Please click the button below to activate your account and set your password.')
            ->line('**Temporary Password:** ' . $this->defaultPassword)
            ->line('You will be required to change this password upon first login.')
            ->action('Activate Account', $activationUrl)
            ->line('This activation link will expire in 24 hours.')
            ->line('If you did not expect this email, please contact your branch manager.')
            ->salutation('Best regards, Assab Team');
    }
}
