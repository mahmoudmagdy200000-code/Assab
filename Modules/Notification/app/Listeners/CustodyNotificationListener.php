<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Custody\Events\HandoverApproved;

class CustodyNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle handover approved event
     */
    public function handle(HandoverApproved $event): void
    {
        $handover = $event->handover;

        // Notify the cashier who initiated the handover
        if ($handover->cashierShift && $handover->cashierShift->cashier) {
            $this->notificationService->send(
                $handover->cashierShift->cashier,
                NotificationType::CUSTODY_CASH_TRANSFER,
                [
                    'handover_id' => $handover->id,
                    'amount' => $handover->handover_amount,
                ],
                NotificationPriority::MEDIUM
            );
        }
    }
}

