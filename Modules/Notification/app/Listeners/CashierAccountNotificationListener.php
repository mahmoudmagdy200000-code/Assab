<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Cashier\Events\CashierActivatedEvent;
use Modules\Cashier\Events\CashierCreatedEvent;
use Modules\Cashier\Events\CashierDeactivatedEvent;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;

/**
 * Cashier account lifecycle → push/in-app.
 *
 * The created event carries the generated default password; it is deliberately
 * NOT forwarded into the notification payload. A push payload lands in the OS
 * notification log and in FCM's delivery pipeline — credentials must travel over
 * the credential channel only.
 */
class CashierAccountNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(CashierCreatedEvent|CashierActivatedEvent|CashierDeactivatedEvent $event): void
    {
        $cashier = $event->cashier;

        $payload = [
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'branch_id' => $cashier->branch_id,
        ];

        match (true) {
            $event instanceof CashierCreatedEvent => $this->notificationService->send(
                $cashier,
                NotificationType::CASHIER_ACCOUNT_CREATED,
                $payload
            ),
            $event instanceof CashierActivatedEvent => $this->notificationService->send(
                $cashier,
                NotificationType::CASHIER_ACCOUNT_ACTIVATED,
                $payload
            ),
            $event instanceof CashierDeactivatedEvent => $this->onDeactivated($cashier, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onDeactivated(object $cashier, array $payload): void
    {
        $this->notificationService->send(
            $cashier,
            NotificationType::CASHIER_ACCOUNT_DEACTIVATED,
            $payload
        );

        // The branch manager owns the roster; a deactivation they did not
        // trigger themselves is something they need to see.
        if ($cashier->branch_id) {
            $this->notificationService->sendToRole(
                'branch_manager',
                NotificationType::CASHIER_ACCOUNT_DEACTIVATED,
                $payload,
                null,
                $cashier->branch_id
            );
        }
    }
}
