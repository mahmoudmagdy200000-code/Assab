<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Shift\Events\ShiftEndedEvent;

class ShiftNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle shift ended event
     */
    public function handle(ShiftEndedEvent $event): void
    {
        $shift = $event->shift;

        // Notify about handover if exists
        if ($event->hasHandover && $shift->next_cashier_id) {
            $nextCashier = $shift->nextCashier;
            if ($nextCashier) {
                $this->notificationService->send(
                    $nextCashier,
                    NotificationType::SHIFT_HANDOVER_PENDING,
                    [
                        'shift_id' => $shift->id,
                        'shift_date' => $shift->shift_date->toDateString(),
                        'cashier_name' => $shift->cashier->name ?? 'Cashier',
                    ],
                    NotificationPriority::MEDIUM
                );
            }
        }
    }
}

