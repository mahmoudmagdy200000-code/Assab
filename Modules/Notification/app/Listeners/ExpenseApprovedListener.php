<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

class ExpenseApprovedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle expense approved event
     */
    public function handle(ExpenseApprovedEvent $event): void
    {
        $expense = $event->expense;

        // Notify branch manager
        if ($expense->branchManager) {
            $this->notificationService->send(
                $expense->branchManager,
                NotificationType::EXPENSE_APPROVED,
                [
                    'expense_id' => $expense->id,
                    'amount' => $expense->total_amount,
                ],
                NotificationPriority::MEDIUM
            );
        }
    }
}
