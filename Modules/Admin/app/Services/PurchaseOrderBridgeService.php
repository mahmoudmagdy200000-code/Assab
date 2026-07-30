<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Two-worlds bridge (meeting 2026-07-30 «المشتريات ليست مرتبطة بالمحاسب»):
 * a MOBILE purchase order becomes a `module_key='purchases'` asab operation so
 * the branch's accountant reviews it (approve → head final-approve / reject)
 * exactly like expenses. Payload uses the shape PurchasePresenterService
 * already renders, and `source_module='purchase'` finally feeds
 * PurchaseReceivingBridge so rcvQty/3-way match resolve on real data.
 *
 * Internal transfers stay out — moving stock between the brand's own branches
 * is not a purchase the accountant certifies.
 */
class PurchaseOrderBridgeService
{
    public const SOURCE = 'purchase';

    private const BRIDGED_TYPES = [
        OrderType::DIRECT_SUPPLIER,
        OrderType::VIA_PURCHASING_OFFICER,
        OrderType::MULTIPLE_SOURCES,
    ];

    public function __construct(
        private readonly BranchHierarchyLinker $branches,
        private readonly RealtimeBroadcaster $rt,
        private readonly NotificationService $notifications,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    /** @return Operation|null null when the order is out of scope / unlinked */
    public function sync(PurchaseOrder $order): ?Operation
    {
        if (! in_array($order->order_type, self::BRIDGED_TYPES, true)) {
            return null;
        }

        $branch = Branch::whereKey($order->branch_id)
            ->first(['id', 'asab_company_id', 'asab_brand_id', 'asab_restaurant_id']);
        if ($branch === null) {
            $this->log->warning('purchase-bridge: skipped — branch row gone', [
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'reason' => 'BRANCH_GONE',
            ]);

            return null;
        }

        $this->branches->ensure($branch);

        $companyId = $branch->asab_company_id;
        if ($companyId === null) {
            $this->log->warning('purchase-bridge: skipped — branch not linked to an ASAB company', [
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'reason' => 'BRANCH_UNLINKED',
                'fix' => 'PATCH /api/v1/admin/branches/{id} with restaurantId, then php artisan asab:bridge-backfill',
            ]);

            return null;
        }

        // Mobile callers carry no ASAB tenant context — drop the scopes and pin
        // the source explicitly. Latest first: a resend mints a NEW op, so the
        // freshest one is the live review record.
        $existing = Operation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('source_module', self::SOURCE)
            ->where('source_id', $order->id)
            ->latest('created_at')
            ->orderByDesc('id') // uuid v7 — same-second ties resolve by creation order
            ->first();

        $payload = $this->payload($order);
        $amount = (int) round(((float) $order->total_amount) * 100);

        if ($existing) {
            if ($existing->status === Operation::STATUS_PENDING) {
                $existing->update(['payload' => $payload, 'amount' => $amount]);

                return $existing;
            }

            // A rejected op is permanently locked on the dashboard side — the
            // branch's edit + resend must therefore mint a NEW pending op (the
            // meeting's re-approval leg). Approved records stay untouched.
            if ($existing->status !== Operation::STATUS_REJECTED) {
                return $existing;
            }

            $payload['supersedesOperationId'] = $existing->id;
        }

        $op = OperationSequence::createWithPublicId(
            'PUR',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $order, $companyId, $payload, $amount, $existing) {
                $op = Operation::create([
                    'public_id' => $publicId,
                    'company_id' => $companyId,
                    'branch_id' => $order->branch_id,
                    'module_key' => 'purchases',
                    'source_module' => self::SOURCE,
                    'source_id' => $order->id,
                    'payload' => $payload,
                    'amount' => $amount,
                    'match' => 'exact',
                    'origin' => 'mobile',
                    'channel' => 'mobile_app',
                    'status' => Operation::STATUS_PENDING,
                    'submitted_at' => $order->submitted_at ?? $order->created_at,
                    'operation_date' => $order->submitted_at ?? $order->created_at,
                ]);

                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'submit',
                    'action' => $existing
                        ? 'أُعيد الإرسال بعد التعديل من تطبيق الفرع: '.$op->public_id
                        : 'أُنشئ أمر شراء من تطبيق الفرع: '.($order->order_number ?? $op->public_id),
                    'actor_label' => $order->requestedBy?->name ?? 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);

                return $op;
            }),
        );

        $this->rt->operationCreated($op);
        $this->notifications->pushToRole(
            $companyId, 'accountant', 'operation.created',
            'أمر شراء بانتظار المراجعة', $op->public_id.' — '.($order->order_number ?? ''),
            null, ['type' => 'operation', 'id' => $op->id],
        );

        return $op;
    }

    /** Canonical purchases payload — the shape PurchasePresenterService reads. */
    private function payload(PurchaseOrder $order): array
    {
        $order->loadMissing(['items', 'supplier:id,name', 'requestedBy:id,name']);

        return [
            'legacyOrderId' => $order->id,
            'orderNumber' => $order->order_number,
            'orderType' => $order->order_type?->value,
            'supplierId' => $order->supplier_id,
            'supplierName' => $order->supplier?->name,
            'submittedBy' => $order->requestedBy?->name,
            'purchaseItems' => $order->items->map(fn ($item) => [
                'rowId' => $item->id,
                'itemId' => $item->item_id,
                'item' => $item->item_name,
                'unit' => $item->unit_of_measurement,
                'ordQty' => (float) $item->quantity_ordered,
                'rcvQty' => $item->quantity_received !== null ? (float) $item->quantity_received : null,
                'unitPriceHalalas' => (int) round(((float) $item->unit_price) * 100),
                'orderedUnitPriceHalalas' => (int) round(((float) $item->unit_price) * 100),
            ])->values()->all(),
        ];
    }
}
