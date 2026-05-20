<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Expense\Events\ExpenseRejectedEvent;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

class ExpenseRejectedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle expense rejected event
     */
    public function handle(ExpenseRejectedEvent $event): void
    {
        $expense = $event->expense;

        // Notify branch manager
        if ($expense->branchManager) {
            $this->notificationService->send(
                $expense->branchManager,
                NotificationType::EXPENSE_REJECTED,
                [
                    'expense_id' => $expense->id,
                    'reason' => $event->reason,
                ],
                NotificationPriority::MEDIUM
            );
        }
    }
}
