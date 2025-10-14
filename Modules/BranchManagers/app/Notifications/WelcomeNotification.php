<?php

namespace Modules\BranchManagers\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class WelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $defaultPassword
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $loginUrl = config('app.frontend_url') . '/branch-manager/login';

        return (new MailMessage)
            ->subject('Welcome to Assab - Branch Manager')
            ->greeting('Welcome ' . $notifiable->name . '!')
            ->line('Your Branch Manager account has been created successfully.')
            ->line('**Branch:** ' . $notifiable->branch->name)
            ->line('**Email:** ' . $notifiable->email)
            ->line('**Temporary Password:** ' . $this->defaultPassword)
            ->line('Please login and change your password immediately.')
            ->action('Login Now', $loginUrl)
            ->line('For security reasons, you will be required to change your password on first login.')
            ->salutation('Best regards, Assab Team');
    }
}
