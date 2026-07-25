<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Notifications\BaseNotification;
use Modules\Shift\Models\CashierShift;

/**
 * Two-worlds feedback loop (WS1a): propagate the dashboard's shift-review
 * decision back onto the legacy `cashier_shifts` row so the mobile app can
 * surface «معتمدة»/«مرفوضة». The forward bridge (BridgeLegacyCashierShift) only
 * pulled the mobile close INTO the dashboard; the mobile side then never learned
 * the review outcome and stayed 'completed' forever. This closes that gap.
 *
 * Deliberately writes ONLY the additive review_* columns — never `status`:
 *  - the legacy status enum has no approve/reject member (write would truncate);
 *  - reopening a completed till unfreezes finalized cash + breaks handover;
 *  - touching `status` re-fires CashierShiftObserver → ShiftEndedEvent, which
 *    the dedup guard would then silently drop, diverging the two worlds.
 * Keeping `status` untouched means CashierShiftObserver stays silent (it gates
 * on isDirty('status')), so there is no event loop.
 */
class ShiftFeedbackBridgeService
{
    /**
     * @param  string  $reviewStatus  'approved' | 'rejected'
     */
    public function syncDecision(Operation $op, string $reviewStatus, ?string $reason = null): void
    {
        $shiftId = $op->payload['shiftId'] ?? null;
        if ($shiftId === null) {
            return;
        }

        // Resolve by primary key; drop tenant scopes so this also works from a
        // queued/no-context listener without leaking the lookup.
        $shift = Shift::withoutGlobalScopes()->whereKey($shiftId)->first(['id', 'legacy_shift_id']);
        $legacyId = $shift?->legacy_shift_id;
        if ($legacyId === null) {
            return; // dashboard-native shift — no mobile row exists to update.
        }

        $legacy = CashierShift::find($legacyId);
        if ($legacy === null || $legacy->review_status === $reviewStatus) {
            return; // gone, or already synced (idempotent).
        }

        $legacy->update([
            'review_status' => $reviewStatus,
            'reviewed_at' => now(),
            'review_reason' => $reason,
        ]);

        // On rejection the sheet returns to its mobile owner — push an in-app
        // notification into the mobile world so the cashier learns of it without
        // polling. Previously the only signal was a dashboard-side 'branch' push
        // the mobile app never reads. Best-effort: a notification failure must
        // never roll back the review decision it accompanies.
        if ($reviewStatus === 'rejected') {
            $this->notifyMobileOwner($legacy, $reason);
        }
    }

    private function notifyMobileOwner(CashierShift $legacy, ?string $reason): void
    {
        try {
            $cashier = $legacy->cashier; // Cashier is Notifiable (database channel).
            if ($cashier === null) {
                return;
            }

            $cashier->notify(new BaseNotification(
                NotificationType::SHIFT_SALES_REJECTED,
                ['shift_id' => $legacy->id, 'reason' => $reason],
                NotificationPriority::HIGH,
                [NotificationChannel::IN_APP->value],
            ));
        } catch (\Throwable $e) {
            // Broad catch is deliberate: this is a fire-and-forget side effect at
            // the world boundary; surface it to the error handler, never propagate.
            report($e);
        }
    }
}
