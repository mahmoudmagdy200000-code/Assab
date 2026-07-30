<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Operation;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Reverse leg of the purchase bridge: when the dashboard reaches a terminal
 * decision on a purchases operation, write the outcome back onto the legacy
 * `purchase_orders` row so the branch sees it and can edit + resend after a
 * rejection.
 *
 * Direct-DB write on purpose: routing through PurchaseOrder::transitionTo would
 * dispatch OrderStatusChanged and loop back into the forward bridge.
 */
class PurchaseFeedbackBridgeService
{
    public function syncFromOperation(Operation $op): void
    {
        if ($op->source_module !== PurchaseOrderBridgeService::SOURCE || $op->source_id === null) {
            return;
        }

        $order = PurchaseOrder::find($op->source_id);
        if ($order === null) {
            return;
        }

        DB::transaction(function () use ($order, $op) {
            if ($op->status === Operation::STATUS_REJECTED) {
                // Quietly stamp the rejection; the branch edits items via the
                // existing PUT /orders/{id}/items then resubmits. rejected_at +
                // decided_by mirror what transitionTo(REJECTED) would have set —
                // the mobile app shows both on the rejection card.
                $actor = auth()->user();
                PurchaseOrder::whereKey($order->id)->update([
                    'status' => \Modules\Purchase\Enums\OrderStatus::REJECTED->value,
                    'rejection_reason' => $op->reject_reason,
                    'rejected_at' => now(),
                    'decided_at' => now(),
                    'decided_by_asab_user_id' => $actor instanceof \Modules\Admin\Models\AsabUser ? $actor->id : null,
                ]);

                $order->timelines()->create([
                    'event_type' => \Modules\Purchase\Enums\TimelineEventType::APPROVAL_DENIED,
                    'title' => 'رفض من الحسابات',
                    'description' => 'رفض من الحسابات: '.($op->reject_reason ?? ''),
                    'actor_role' => 'accountant',
                    'occurred_at' => now(),
                ]);

                return;
            }

            if ($op->status === Operation::STATUS_FINAL) {
                // Accounting certification only — never move the operational
                // status (the order may already be delivered/closed).
                $order->timelines()->create([
                    'event_type' => \Modules\Purchase\Enums\TimelineEventType::APPROVAL_GRANTED,
                    'title' => 'اعتماد نهائي من الحسابات',
                    'description' => 'اعتماد نهائي من الحسابات: '.$op->public_id,
                    'actor_role' => 'accountant',
                    'occurred_at' => now(),
                ]);
            }
        });
    }
}
