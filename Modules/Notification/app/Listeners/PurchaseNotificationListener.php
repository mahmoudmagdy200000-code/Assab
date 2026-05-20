<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Purchase\Events\OrderCreated;

class PurchaseNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle order created event
     */
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        // Notify supplier if order has supplier
        if ($order->supplier) {
            $this->notificationService->send(
                $order->supplier,
                NotificationType::ORDER_CREATED,
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ],
                NotificationPriority::MEDIUM
            );
        }
    }
}
