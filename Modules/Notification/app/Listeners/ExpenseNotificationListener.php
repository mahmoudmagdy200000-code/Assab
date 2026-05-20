<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

class ExpenseNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    /**
     * Handle expense submitted event
     */
    public function handle(ExpenseSubmittedEvent $event): void
    {
        $expense = $event->expense;

        // Notify brand owner
        // TODO: Get brand owner from expense relationship
        // $brandOwner = $expense->brandOwner;
        // if ($brandOwner) {
        //     $this->notificationService->send(
        //         $brandOwner,
        //         NotificationType::EXPENSE_SUBMITTED,
        //         [
        //             'expense_id' => $expense->id,
        //             'amount' => $expense->total_amount,
        //             'branch_manager_name' => $expense->branchManager->name ?? 'Branch Manager',
        //         ],
        //         NotificationPriority::HIGH
        //     );
        // }
    }
}
