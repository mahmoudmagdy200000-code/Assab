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

        if (! $event->hasHandover) {
            return;
        }

        // Resolve the designated receiving cashier from the authoritative handover record first,
        // then fall back to the next_cashier_id column on the shift.
        $shift->loadMissing(['handover', 'nextCashier', 'cashier']);
        $handover = $shift->handover;
        $nextCashier = null;

        if ($handover && $handover->handover_to_type === 'cashier' && $handover->handover_to_id) {
            $nextCashier = \Modules\Cashier\Models\Cashier::find($handover->handover_to_id);
        } elseif ($shift->next_cashier_id) {
            $nextCashier = $shift->nextCashier;
        }

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
