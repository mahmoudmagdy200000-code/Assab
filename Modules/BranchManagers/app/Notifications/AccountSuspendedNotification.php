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

    /**
     * Mail only.
     *
     * The in-app record for a suspension is now written by the unified
     * notification pipeline (BranchManagerAccountNotificationListener →
     * NotificationType::BRANCH_MANAGER_SUSPENDED), which also delivers the
     * device push. Keeping `database` here as well produced two rows in the
     * user's notification list for one suspension.
     *
     * Clients that switched on the old in-app payload `data.type ===
     * 'account_suspended'` should read `'branch_manager_suspended'`, which
     * carries the same reason plus title, message, priority and category.
     */
    public function via($notifiable): array
    {
        return ['mail'];
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
