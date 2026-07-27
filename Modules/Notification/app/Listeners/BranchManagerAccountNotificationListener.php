<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\BranchManagers\Events\BranchManagerCreatedEvent;
use Modules\BranchManagers\Events\BranchManagerSuspendedEvent;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;

/**
 * Branch manager account lifecycle → push/in-app.
 *
 * As with cashiers, the default password on the created event is never copied
 * into the notification payload.
 */
class BranchManagerAccountNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(BranchManagerCreatedEvent|BranchManagerSuspendedEvent $event): void
    {
        $manager = $event->manager;

        $payload = [
            'branch_manager_id' => $manager->id,
            'branch_manager_name' => $manager->name,
            'branch_id' => $manager->branch_id,
        ];

        if ($event instanceof BranchManagerSuspendedEvent) {
            $this->notificationService->send(
                $manager,
                NotificationType::BRANCH_MANAGER_SUSPENDED,
                $payload + ['reason' => $event->reason]
            );

            return;
        }

        $this->notificationService->send(
            $manager,
            NotificationType::BRANCH_MANAGER_ACCOUNT_CREATED,
            $payload
        );
    }
}
