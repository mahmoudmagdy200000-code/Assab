<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Purchase\Models\BranchItem;

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

    public const SOURCE_MONTHLY = 'monthly_inventory';

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
            // If returned/rejected before final approval and resubmitted from the app,
            // increment revision and re-open for accountant review while preserving history.
            if ($existing->status === Operation::STATUS_PENDING) {
                $existingPayload = $existing->payload ?? [];
                $payload['revision'] = (int) ($existingPayload['revision'] ?? 1);
                $existing->update(['payload' => $payload]);
            } elseif ($existing->status === Operation::STATUS_REJECTED) {
                $existingPayload = $existing->payload ?? [];
                $nextRev = (int) ($existingPayload['revision'] ?? 1) + 1;
                $payload['revision'] = $nextRev;
                $existing->update([
                    'payload' => $payload,
                    'status' => Operation::STATUS_PENDING,
                    'rejected_by_id' => null,
                    'rejected_at' => null,
                    'reject_reason' => null,
                    'submitted_at' => now(),
                ]);
                ApprovalStep::create([
                    'operation_id' => $existing->id,
                    'stage_id' => 'submit',
                    'action' => 'إعادة إرسال جرد يومي (نسخة '.$nextRev.'): '.$existing->public_id,
                    'actor_label' => 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);
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
        $session->loadMissing(['items.item', 'discrepancies', 'createdBy', 'assignedTo']);

        $discrepancies = $session->discrepancies->keyBy('inventory_item_id');
        $prices = $this->branchPrices($session);

        return [
            'legacySessionId' => $session->id,
            'countType' => 'daily',
            'revision' => 1,
            'inventoryDate' => $session->inventory_date?->format('Y-m-d'),
            'assignedToType' => $session->assigned_to_type ?? 'personal',
            'delegatedBy' => $session->createdBy?->name,
            'executedBy' => $session->assigned_to_type === 'staff'
                ? ($session->assignedTo?->name ?? $session->createdBy?->name)
                : $session->createdBy?->name,
            'items' => $session->items->map(function ($item) use ($discrepancies, $prices) {
                $d = $discrepancies->get($item->id);

                return [
                    'itemId' => $item->item_id,
                    'name' => $item->item_name ?? $item->item?->name ?? '',
                    'unit' => $item->item?->unit ?? 'kg',
                    'category' => $item->item?->category ?? '',
                    'actualQty' => (float) $item->quantity_inventory,
                    'purchases' => $d ? (float) $d->purchases : null,
                    'waste' => $d ? (float) $d->recorded_waste : null,
                    'expectedQty' => $this->expectedQty($d),
                    'unitPriceHalalas' => $prices[$item->item_id] ?? null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Bridge a submitted MonthlyInventory into an `inventory` Operation
     * (`countType = 'monthly'`) so it follows Accountant review -> Head final approval.
     */
    public function syncMonthly(MonthlyInventory $inventory): ?Operation
    {
        $branch = Branch::whereKey($inventory->branch_id)
            ->first(['id', 'name', 'asab_company_id', 'asab_brand_id', 'asab_restaurant_id']);
        if ($branch === null) {
            return null;
        }

        $this->branches->ensure($branch);
        $companyId = $branch->asab_company_id;
        if ($companyId === null) {
            return null;
        }

        $existing = Operation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('source_module', self::SOURCE_MONTHLY)
            ->where('source_id', $inventory->id)
            ->first();

        $inventory->loadMissing(['products.item', 'createdBy']);
        $itemIds = $inventory->products->pluck('item_id')->filter()->unique();
        $prices = $itemIds->isEmpty() ? [] : BranchItem::where('branch_id', $inventory->branch_id)
            ->whereIn('item_id', $itemIds)
            ->pluck('price', 'item_id')
            ->map(fn ($price) => (int) round(((float) $price) * 100))
            ->all();

        $payload = [
            'legacyMonthlyInventoryId' => $inventory->id,
            'inventoryNumber' => $inventory->inventory_number,
            'countType' => 'monthly',
            'revision' => 1,
            'inventoryDate' => $inventory->inventory_date?->format('Y-m-d'),
            'delegatedBy' => $inventory->createdBy?->name,
            'executedBy' => $inventory->createdBy?->name,
            'items' => $inventory->products->map(function ($p) use ($prices) {
                return [
                    'itemId' => $p->item_id,
                    'name' => $p->item_name ?? $p->item?->name ?? '',
                    'unit' => $p->unit ?? $p->item?->unit ?? 'kg',
                    'category' => $p->category ?? $p->item?->category ?? '',
                    'actualQty' => (float) $p->quantity_inventory,
                    'expectedQty' => null,
                    'unitPriceHalalas' => $prices[$p->item_id] ?? (int) round(((float) ($p->unit_price ?? 0)) * 100),
                ];
            })->values()->all(),
        ];

        if ($existing) {
            if ($existing->status === Operation::STATUS_PENDING) {
                $payload['revision'] = (int) ($existing->payload['revision'] ?? 1);
                $existing->update(['payload' => $payload]);
            } elseif ($existing->status === Operation::STATUS_REJECTED) {
                $nextRev = (int) ($existing->payload['revision'] ?? 1) + 1;
                $payload['revision'] = $nextRev;
                $existing->update([
                    'payload' => $payload,
                    'status' => Operation::STATUS_PENDING,
                    'rejected_by_id' => null,
                    'rejected_at' => null,
                    'reject_reason' => null,
                    'submitted_at' => now(),
                ]);
                ApprovalStep::create([
                    'operation_id' => $existing->id,
                    'stage_id' => 'submit',
                    'action' => 'إعادة إرسال جرد شهري (نسخة '.$nextRev.'): '.$existing->public_id,
                    'actor_label' => 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);
            }

            return $existing;
        }

        $op = OperationSequence::createWithPublicId(
            'INV',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $inventory, $companyId, $payload) {
                $op = Operation::create([
                    'public_id' => $publicId,
                    'company_id' => $companyId,
                    'branch_id' => $inventory->branch_id,
                    'module_key' => 'inventory',
                    'source_module' => self::SOURCE_MONTHLY,
                    'source_id' => $inventory->id,
                    'payload' => $payload,
                    'amount' => 0,
                    'match' => 'exact',
                    'origin' => 'mobile',
                    'channel' => 'mobile_app',
                    'status' => Operation::STATUS_PENDING,
                    'submitted_at' => $inventory->submitted_at ?? now(),
                    'operation_date' => $inventory->inventory_date ?? $inventory->submitted_at ?? now(),
                ]);

                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'submit',
                    'action' => 'جرد شهري من تطبيق الفرع: '.$op->public_id,
                    'actor_label' => 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);

                return $op;
            }),
        );

        $this->rt->operationCreated($op);
        $this->notifications->pushToBranch(
            $companyId, $inventory->branch_id, 'accountant', 'inventory.submitted',
            'جرد شهري بانتظار المراجعة',
            'جرد شهري — '.$branch->name.' — '.($inventory->inventory_date?->format('Y-m-d') ?? ''),
            null,
            ['type' => 'operation', 'id' => $op->id],
        );

        return $op;
    }

    /**
     * A branch's FIRST count has no prior approved session, so the mobile
     * calculation opens at zero and subtracts the day's sales and waste — which
     * yields a NEGATIVE theoretical stock (prod E2E 2026-07-31: «الكمية المتوقعة
     * −1.5 كيس»). Stock can never be negative: the number does not mean "minus
     * one and a half", it means the opening baseline is unknown. Report it as
     * unknown — the contract already renders a null expectedQty as «—».
     */
    private function expectedQty(?object $discrepancy): ?float
    {
        if ($discrepancy === null) {
            return null;
        }

        $expected = (float) $discrepancy->theoretically_expected;

        return $expected < 0 ? null : $expected;
    }

    /**
     * The reconciliation screen prices variances from the ASAB catalog, but a
     * mobile payload carries LEGACY item ids, so every lookup missed and every
     * variance was valued at 0.00 ر.س. Carry the branch's own price across the
     * bridge so the reader never has to resolve the id space. One query for the
     * whole session, not one per line.
     *
     * @return array<string, int> legacy item_id → unit price in halalas
     */
    private function branchPrices(InventorySession $session): array
    {
        $itemIds = $session->items->pluck('item_id')->filter()->unique();
        if ($itemIds->isEmpty()) {
            return [];
        }

        return BranchItem::where('branch_id', $session->branch_id)
            ->whereIn('item_id', $itemIds)
            ->pluck('price', 'item_id')
            ->map(fn ($price) => (int) round(((float) $price) * 100))
            ->all();
    }
}
