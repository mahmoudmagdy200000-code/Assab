<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Purchase\Events\OrderStatusChanged;

class OrderStatusChangedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle order status changed event
     */
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        // Notify supplier
        if ($order->supplier) {
            $this->notificationService->send(
                $order->supplier,
                NotificationType::ORDER_STATUS_CHANGED,
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'old_status' => $event->oldStatus->value,
                    'new_status' => $event->newStatus->value,
                ],
                NotificationPriority::MEDIUM
            );
        }
    }
}

