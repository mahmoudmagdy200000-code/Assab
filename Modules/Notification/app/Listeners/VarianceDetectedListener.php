<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Purchase\Events\VarianceDetected;

class VarianceDetectedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle variance detected event
     */
    public function handle(VarianceDetected $event): void
    {
        $variance = $event->variance;

        // Notify branch manager
        // TODO: Get branch manager from variance relationship
        // if ($variance->branch && $variance->branch->manager) {
        //     $this->notificationService->send(
        //         $variance->branch->manager,
        //         NotificationType::ORDER_VARIANCE_DETECTED,
        //         [
        //             'variance_id' => $variance->id,
        //             'amount' => $variance->variance_amount,
        //         ],
        //         NotificationPriority::HIGH
        //     );
        // }
    }
}
