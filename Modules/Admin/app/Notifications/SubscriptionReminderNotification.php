<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-triggered subscription renewal reminder to a company admin
 * (Admin dashboard contract batch 1, A8). Mail channel; best-effort.
 */
class SubscriptionReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $companyName,
        private string $message,
    ) {}

    /** @return string[] */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $billingUrl = config('app.frontend_url').'/company/me/billing';

        return (new MailMessage)
            ->subject('تذكير بتجديد الاشتراك — Subscription Renewal Reminder')
            ->greeting('مرحباً '.$notifiable->name)
            ->line('بخصوص اشتراك "'.$this->companyName.'":')
            ->line($this->message)
            ->action('إدارة الاشتراك / Manage Subscription', $billingUrl)
            ->salutation('فريق أساب — ASAB Team');
    }
}
