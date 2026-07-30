<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InventorySession;

/**
 * Two-worlds bridge (meeting 2026-07-30): a submitted mobile daily-quick
 * inventory session becomes a `module_key='inventory'` operation so the
 * accountant's review/reconciliation screens finally see mobile counts.
 * Payload uses `items` — the key every ASAB reader iterates. Re-synced on
 * approval so the discrepancy figures (purchases/waste/expected) arrive too.
 */
class InventoryBridgeService
{
    public const SOURCE = 'inventory';

    public function __construct(
        private readonly BranchHierarchyLinker $branches,
        private readonly RealtimeBroadcaster $rt,
        private readonly NotificationService $notifications,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    public function sync(InventorySession $session): ?Operation
    {
        $branch = Branch::whereKey($session->branch_id)
            ->first(['id', 'name', 'asab_company_id', 'asab_brand_id', 'asab_restaurant_id']);
        if ($branch === null) {
            $this->log->warning('inventory-bridge: skipped — branch row gone', [
                'session_id' => $session->id, 'branch_id' => $session->branch_id, 'reason' => 'BRANCH_GONE',
            ]);

            return null;
        }

        $this->branches->ensure($branch);

        $companyId = $branch->asab_company_id;
        if ($companyId === null) {
            $this->log->warning('inventory-bridge: skipped — branch not linked to an ASAB company', [
                'session_id' => $session->id, 'branch_id' => $session->branch_id, 'reason' => 'BRANCH_UNLINKED',
                'fix' => 'PATCH /api/v1/admin/branches/{id} with restaurantId, then php artisan asab:bridge-backfill',
            ]);

            return null;
        }

        $existing = Operation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('source_module', self::SOURCE)
            ->where('source_id', $session->id)
            ->first();

        $payload = $this->payload($session);

        if ($existing) {
            // The accountant owns the record once they act on it.
            if ($existing->status === Operation::STATUS_PENDING) {
                $existing->update(['payload' => $payload]);
            }

            return $existing;
        }

        $op = OperationSequence::createWithPublicId(
            'INV',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $session, $companyId, $payload) {
                $op = Operation::create([
                    'public_id' => $publicId,
                    'company_id' => $companyId,
                    'branch_id' => $session->branch_id,
                    'module_key' => 'inventory',
                    'source_module' => self::SOURCE,
                    'source_id' => $session->id,
                    'payload' => $payload,
                    'amount' => 0,
                    'match' => 'exact',
                    'origin' => 'mobile',
                    'channel' => 'mobile_app',
                    'status' => Operation::STATUS_PENDING,
                    'submitted_at' => $session->submitted_at ?? now(),
                    // NOT now(): the accountant's month-over-month buckets by
                    // operation_date — a backfilled session must keep its day.
                    'operation_date' => $session->inventory_date ?? $session->submitted_at ?? now(),
                ]);

                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'submit',
                    'action' => 'جرد يومي من تطبيق الفرع: '.$op->public_id,
                    'actor_label' => 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);

                return $op;
            }),
        );

        $this->rt->operationCreated($op);
        $this->notifications->pushToBranch(
            $companyId, $session->branch_id, 'accountant', 'inventory.submitted',
            'جرد جديد بانتظار المراجعة',
            'جرد يومي — '.$branch->name.' — '.($session->inventory_date?->format('Y-m-d') ?? ''),
            null,
            ['type' => 'operation', 'id' => $op->id],
        );

        return $op;
    }

    /** Canonical `items` payload — the shape every ASAB inventory reader iterates. */
    private function payload(InventorySession $session): array
    {
        $session->loadMissing(['items.item', 'discrepancies']);

        $discrepancies = $session->discrepancies->keyBy('inventory_item_id');

        return [
            'legacySessionId' => $session->id,
            'countType' => 'daily',
            'inventoryDate' => $session->inventory_date?->format('Y-m-d'),
            'items' => $session->items->map(function ($item) use ($discrepancies) {
                $d = $discrepancies->get($item->id);

                return [
                    'itemId' => $item->item_id,
                    'name' => $item->item_name ?? $item->item?->name ?? '',
                    'unit' => $item->item?->unit ?? 'kg',
                    'category' => $item->item?->category ?? '',
                    'actualQty' => (float) $item->quantity_inventory,
                    'purchases' => $d ? (float) $d->purchases : null,
                    'waste' => $d ? (float) $d->recorded_waste : null,
                    'expectedQty' => $d ? (float) $d->theoretically_expected : null,
                ];
            })->values()->all(),
        ];
    }
}
