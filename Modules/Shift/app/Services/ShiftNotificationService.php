<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\CashierShift;
use Modules\Notification\Notifications\{
    ShiftReassignedNotification,
    ShiftVarianceAlertNotification,
    HandoverPendingNotification
};
use Illuminate\Support\Facades\Notification;

class ShiftNotificationService
{
    // public function notifyShiftReassigned(CashierShift $shift): void
    // {
    //     // Notify original cashier
    //     if ($shift->originalCashier) {
    //         $shift->originalCashier->notify(
    //             new ShiftReassignedNotification($shift, 'removed')
    //         );
    //     }

    //     // Notify new cashier
    //     $shift->cashier->notify(
    //         new ShiftReassignedNotification($shift, 'assigned')
    //     );
    // }

    // public function notifyHandoverPending(CashierShift $shift): void
    // {
    //     if ($shift->nextCashier) {
    //         $shift->nextCashier->notify(
    //             new HandoverPendingNotification($shift)
    //         );
    //     }
    // }

    // public function notifyHandoverApproved(CashierShift $shift): void
    // {
    //     $shift->cashier->notify(
    //         new \Modules\Notification\Notifications\HandoverApprovedNotification($shift)
    //     );
    // }

    // public function notifyHandoverRejected(CashierShift $shift): void
    // {
    //     $shift->cashier->notify(
    //         new \Modules\Notification\Notifications\HandoverRejectedNotification($shift)
    //     );
    // }

    // public function notifyVarianceAlert(CashierShift $shift, float $amount, float $percentage): void
    // {
    //     // Notify branch manager
    //     $shift->shift->branch->manager->notify(
    //         new ShiftVarianceAlertNotification($shift, $amount, $percentage)
    //     );
    // }

    // public function notifyShiftStart(CashierShift $shift): void
    // {
    //     // Notify cashier 15 minutes before shift
    //     $shift->cashier->notify(
    //         new \Modules\Notification\Notifications\ShiftStartReminderNotification($shift)
    //     );
    // }

    // public function notifyShiftEnd(CashierShift $shift): void
    // {
    //     $shift->cashier->notify(
    //         new \Modules\Notification\Notifications\ShiftEndReminderNotification($shift)
    //     );
    // }
}
