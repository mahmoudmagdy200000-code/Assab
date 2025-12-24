<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NotificationType $type,
        public array $data = [],
        public NotificationPriority $priority = null
    ) {
        $this->priority = $priority ?? $this->type->defaultPriority();
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'priority' => $this->priority->value,
            'category' => $this->type->category()->value,
            'data' => $this->data,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get notification title
     */
    protected function getTitle(): string
    {
        return mb_substr($this->type->label(), 0, 60);
    }

    /**
     * Get notification message
     */
    protected function getMessage(): string
    {
        $message = $this->generateMessage();
        return mb_substr($message, 0, 120);
    }

    /**
     * Generate message based on type and data
     */
    protected function generateMessage(): string
    {
        return match ($this->type) {
            NotificationType::SHIFT_START_REMINDER => "Your shift starts in 15 minutes",
            NotificationType::SHIFT_START_OVERDUE => "Your shift start is overdue",
            NotificationType::SHIFT_END_REMINDER => "Your shift ends in 30 minutes",
            NotificationType::SHIFT_HANDOVER_PENDING => "You have a pending handover",
            NotificationType::SHIFT_HANDOVER_APPROVED => "Your handover has been approved",
            NotificationType::SHIFT_HANDOVER_REJECTED => "Your handover has been rejected: " . ($this->data['reason'] ?? 'No reason provided'),
            NotificationType::EXPENSE_SUBMITTED => "New expense submitted for approval",
            NotificationType::EXPENSE_APPROVED => "Your expense has been approved",
            NotificationType::EXPENSE_REJECTED => "Your expense has been rejected",
            NotificationType::CUSTODY_REQUEST_APPROVED => "Your custody request has been approved",
            NotificationType::CUSTODY_LOW_BALANCE => "Your custody balance is low",
            default => $this->type->label(),
        };
    }
}

