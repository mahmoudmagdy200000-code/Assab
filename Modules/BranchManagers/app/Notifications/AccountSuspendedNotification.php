<?php

namespace Modules\BranchManagers\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $reason
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Account Suspended - Assab')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your Branch Manager account has been suspended.')
            ->line('**Reason:** '.$this->reason)
            ->line('Please contact the administrator for more information.')
            ->line('Email: support@assab.com')
            ->salutation('Assab Team');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'account_suspended',
            'reason' => $this->reason,
            'message' => 'Your account has been suspended',
        ];
    }
}
