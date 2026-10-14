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
        \Illuminate\Support\Facades\DB::transaction(function () use ($op, $reviewStatus, $reason) {
            $current = app(\Modules\Shift\Services\ShiftReportReviewService::class)->lockOperation($op);
            $required = $reviewStatus === 'approved' ? Operation::STATUS_FINAL : Operation::STATUS_REJECTED;
            if ($current->status !== $required) {
                return;
            }
            $legacyId = app(\Modules\Shift\Services\ShiftReportReviewService::class)->cashierSource($current);
            if ($legacyId === null) {
                return;
            }
            $legacy = CashierShift::withoutEagerLoads()->whereKey($legacyId)->lockForUpdate()->first();
            if ($legacy === null || $legacy->review_status === $reviewStatus) {
                return;
            }
            $legacy->update(['review_status' => $reviewStatus, 'reviewed_at' => now(), 'review_reason' => $reason]);
            // The return command writes the assigned manager's durable outbox intent.
            // Legacy cashier notifications remain for inherited non-cycle decisions.
            if ($reviewStatus === 'rejected' && ! isset($current->payload['pkg6Review']['return_cycle'])) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => $this->notifyMobileOwner($legacy, $reason));
            }
        });
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
