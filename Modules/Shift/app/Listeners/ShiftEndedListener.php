<?php

namespace Modules\Shift\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Services\ShiftNotificationService;

class ShiftEndedListener
{
    public function __construct(
        private ShiftNotificationService $notificationService
    ) {}

    public function handle(ShiftEndedEvent $event): void
    {
        // Log shift end
        Log::info('Shift ended', [
            'shift_id' => $event->shift->id,
            'cashier_id' => $event->shift->cashier_id,
            'has_handover' => $event->hasHandover,
        ]);

        // Send notification if handover exists
        if ($event->hasHandover && $event->shift->next_cashier_id) {
            $this->notificationService->notifyHandoverPending($event->shift);
        }

        // Update branch statistics
        // Trigger other necessary actions
    }
}
