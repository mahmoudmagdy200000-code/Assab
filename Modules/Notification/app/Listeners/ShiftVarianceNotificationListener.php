<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Shift\Events\VarianceRecorded;

/**
 * Cash variance on a closed shift → the branch manager, and the cashier who
 * recorded it.
 *
 * High priority: an unexplained cash gap is the single event this system exists
 * to surface quickly.
 */
class ShiftVarianceNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(VarianceRecorded $event): void
    {
        $shift = $event->shift;
        $shift->loadMissing('cashier');

        // CashierShift carries no branch column; the branch comes from the
        // cashier who worked it.
        $branchId = $shift->cashier?->branch_id;

        $payload = [
            'shift_id' => $shift->id,
            'shift_date' => $shift->shift_date?->toDateString(),
            'branch_id' => $branchId,
            'cashier_name' => $shift->cashier->name ?? '—',
        ];

        if ($shift->cashier !== null) {
            $this->notificationService->send(
                $shift->cashier,
                NotificationType::CASH_VARIANCE_HIGH,
                $payload,
                NotificationPriority::HIGH
            );
        }

        if ($branchId) {
            $this->notificationService->sendToRole(
                'branch_manager',
                NotificationType::CASH_VARIANCE_HIGH,
                $payload,
                NotificationPriority::HIGH,
                $branchId
            );
        }
    }
}
