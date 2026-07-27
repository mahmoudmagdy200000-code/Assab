<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Models\AsabUser;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;

/**
 * ASAB dashboard approval chain → the submitter's devices.
 *
 * Only the person who submitted the operation is notified. Approvers already
 * see the outcome in their queue, and a fan-out to every approver on a
 * high-volume chain would be noise.
 */
class OperationDecisionNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(OperationFinalApproved|OperationRejected $event): void
    {
        $operation = $event->operation;
        $submitter = $operation->submitted_by_id
            ? AsabUser::query()->find($operation->submitted_by_id)
            : null;

        if ($submitter === null) {
            return;
        }

        // Do not notify someone about their own action.
        if ((string) $submitter->getKey() === (string) $event->actor->getKey()) {
            return;
        }

        $payload = [
            'operation_id' => $operation->id,
            'operation_number' => $operation->public_id,
            'module_key' => $operation->module_key,
            'branch_id' => $operation->branch_id,
        ];

        if ($event instanceof OperationRejected) {
            $this->notificationService->send(
                $submitter,
                NotificationType::OPERATION_REJECTED,
                $payload + ['reason' => $event->reason ?? $operation->reject_reason ?? '—']
            );

            return;
        }

        $this->notificationService->send(
            $submitter,
            NotificationType::OPERATION_FINAL_APPROVED,
            $payload
        );
    }
}
