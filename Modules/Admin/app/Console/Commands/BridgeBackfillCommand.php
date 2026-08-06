<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Listeners\BridgeLegacyCashierShift;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\CashierShift;

/**
 * Meeting 2026-07-29 «الفاتورة اتبعتت ومجاتش»: mobile expenses and closed
 * cashier shifts whose bridge run was silently skipped (unlinked branch,
 * unmirrored cashier, missing ASAB actor) never re-sync on their own — the
 * bridge only fires on the submit/close event. After fixing the link
 * (PATCH /admin/branches/{id}, asab:mirror-mobile-cashiers), run this to
 * re-drive every stranded record through the SAME bridge code path.
 *
 * Idempotent by construction: candidates are selected by the absence of their
 * bridged row, and both bridges carry their own once-only guards
 * (ExpenseBridgeService keys on source_module+source_id; the shift listener
 * skips any asab_shifts row already past active|late).
 */
class BridgeBackfillCommand extends Command
{
    protected $signature = 'asab:bridge-backfill
        {--dry-run : List the stranded records and why, without bridging}
        {--catalog : Also re-run the supplier-item catalog bridge (heals supplier links dropped by the old price-0/platform-supplier guards)}
        {--resync-payloads : Also re-map ALREADY-mirrored expenses that are still pending, so payload fixes reach operations minted by older code}';

    protected $description = 'Re-bridge mobile expenses and closed shifts that never reached the dashboard';

    public function handle(ExpenseBridgeService $expenses, BridgeLegacyCashierShift $shifts): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->backfillExpenses($expenses, $dry);
        $this->backfillShifts($shifts, $dry);
        $this->backfillPurchaseOrders($dry);
        $this->backfillInventorySessions($dry);
        $this->backfillAssetReceipts($dry);
        $this->backfillBranchRawMaterials($dry);
        $this->backfillDailyInventoryLists($dry);

        if ($this->option('resync-payloads')) {
            $this->resyncExpensePayloads($expenses, $dry);
        }

        if ($this->option('catalog')) {
            $this->backfillCatalog($dry);
        }

        if ($dry) {
            $this->comment('Dry run — nothing was written. Re-run without --dry-run to bridge.');
        }

        return self::SUCCESS;
    }

    /**
     * Re-run the catalog bridge over every dashboard/portal supplier item so
     * `supplier_items` links dropped by the old guards (price <= 0, platform
     * suppliers with company_id NULL) exist again — the mobile order screen
     * reads that table to suggest suppliers per item.
     */
    private function backfillCatalog(bool $dry): void
    {
        $bridge = app(\Modules\Admin\Services\ProcurementCatalogBridgeService::class);
        $synced = 0;

        \Modules\Admin\Models\SupplierItem::withoutGlobalScopes()
            ->whereNotNull('supplier_id')
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$synced) {
                foreach ($chunk as $item) {
                    if ($dry) {
                        $this->line("[dry] catalog item {$item->id} ({$item->name}) → re-sync supplier link");
                        $synced++;

                        continue;
                    }

                    try {
                        $bridge->syncItem($item);
                        $synced++;
                    } catch (\Throwable $e) {
                        $this->warn("catalog item {$item->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dry ? 'Would re-sync ' : 'Re-synced ')."{$synced} catalog item(s).");
    }

    /**
     * Branch-assigned dashboard assets with no mobile receive request.
     *
     * The bulk fixed-assets importer wrote asab_assets without dispatching
     * AssetAssignedToBranch, so every uploaded register is invisible to the
     * branch — «لا توجد بيانات الأصول الثابتة التي رفعناها» (2026-08-03). Runs
     * the same listener the single-asset path uses; PendingReceipt is keyed on
     * asab_asset_id, so re-running never duplicates a request.
     */
    private function backfillAssetReceipts(bool $dry): void
    {
        $bridge = app(\Modules\Admin\Listeners\BridgeAssetToBranchReceipt::class);
        $bridged = 0;

        \Modules\Admin\Models\Asset::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('branch_id')
            ->where('status', 'pending_branch')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('fixed_asset_pending_receipts')
                    ->whereColumn('fixed_asset_pending_receipts.asab_asset_id', 'asab_assets.id');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$bridged) {
                foreach ($chunk as $asset) {
                    if ($dry) {
                        $this->line("[dry] asset {$asset->public_id} ({$asset->name}) → receive request for branch {$asset->branch_id}");
                        $bridged++;

                        continue;
                    }

                    $bridge->handle(new \Modules\Admin\Events\AssetAssignedToBranch($asset));
                    $bridged++;
                }
            });

        $this->info(($dry ? 'Would create ' : 'Created ')."{$bridged} mobile asset receive request(s).");
    }

    /**
     * Re-project every brand's raw-material catalog onto ITS BRANCHES.
     *
     * The upload seeds `branch_item` for the branches that exist at upload
     * time, so a branch created later opens the app's purchasing picker empty —
     * or half-full: «عدد منتجات المشتريات يفترض 30 وليس 15» (2026-08-05). Also
     * fills blank units on the legacy rows, which is what made the app label
     * every material «(Kg)».
     */
    private function backfillBranchRawMaterials(bool $dry): void
    {
        $sync = app(\Modules\Admin\Services\RawMaterialBranchSyncService::class);
        $seeded = 0;
        $units = 0;

        \Modules\Admin\Models\AsabBrand::withoutGlobalScopes()
            ->orderBy('created_at')
            ->chunkById(50, function ($chunk) use ($sync, $dry, &$seeded, &$units) {
                foreach ($chunk as $brand) {
                    $branchIds = $sync->brandBranchIds($brand);
                    $items = \Modules\Admin\Models\InventoryCatalogItem::where('brand_id', $brand->id)
                        ->where('type', \Modules\Admin\Models\InventoryCatalogItem::TYPE_RAW_MATERIAL)
                        ->count();

                    if ($branchIds === [] || $items === 0) {
                        continue;
                    }

                    if ($dry) {
                        $this->line("[dry] brand {$brand->name}: {$items} raw material(s) × ".count($branchIds).' branch(es)');

                        continue;
                    }

                    try {
                        $state = $sync->syncBrand($brand, $branchIds);
                        $seeded += $state['seeded'];
                        $units += $state['unitsFilled'];
                    } catch (\Throwable $e) {
                        $this->warn("brand {$brand->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dry ? 'Would seed ' : 'Seeded ')."{$seeded} branch item row(s); units filled: {$units}.");
    }

    /**
     * The accountant's «تحديد أصناف الجرد اليومي» rows that never reached the
     * app's count sheet — saved before the daily-list bridge existed, or landed
     * in a DEACTIVATED schedule the app's active() scope hides («0 products» on
     * the manager's Daily Quick Inventory screen, 2026-08-05).
     */
    private function backfillDailyInventoryLists(bool $dry): void
    {
        $bridge = app(\Modules\Admin\Services\DailyInventoryListBridgeService::class);
        $branches = 0;
        $items = 0;

        \Modules\Admin\Models\BranchInventoryList::query()
            ->select('branch_id')
            ->groupBy('branch_id')
            ->pluck('branch_id')
            ->each(function ($branchId) use ($bridge, $dry, &$branches, &$items) {
                $catalogIds = \Modules\Admin\Models\BranchInventoryList::where('branch_id', $branchId)
                    ->pluck('catalog_item_id')->unique()->values()->all();

                if ($catalogIds === []) {
                    return;
                }

                if ($dry) {
                    $this->line('[dry] branch '.$branchId.': '.count($catalogIds).' selected item(s) → app count sheet');
                    $branches++;

                    return;
                }

                try {
                    $state = $bridge->sync($branchId, $catalogIds);
                    $branches++;
                    $items += $state['items'];
                } catch (\Throwable $e) {
                    $this->warn("branch {$branchId}: {$e->getMessage()}");
                }
            });

        $this->info(($dry ? 'Would push ' : 'Pushed ')."{$items} item(s) onto the count sheet of {$branches} branch(es).");
    }

    /**
     * Submitted mobile inventory sessions with no live INV- operation mirror.
     */
    private function backfillInventorySessions(bool $dry): void
    {
        $bridge = app(\Modules\Admin\Services\InventoryBridgeService::class);
        $bridged = 0;
        $skipped = 0;

        \Modules\Inventory\Models\InventorySession::query()
            ->whereNotNull('submitted_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_operations')
                    ->whereColumn('asab_operations.source_id', 'inventory_sessions.id')
                    ->where('asab_operations.source_module', \Modules\Admin\Services\InventoryBridgeService::SOURCE)
                    ->whereNull('asab_operations.deleted_at');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$bridged, &$skipped) {
                foreach ($chunk as $session) {
                    if ($dry) {
                        $this->line("[dry] inventory session {$session->id} → bridge to INV op");
                        $bridged++;

                        continue;
                    }

                    try {
                        $bridge->sync($session) !== null ? $bridged++ : $skipped++;
                    } catch (\Throwable $e) {
                        $skipped++;
                        $this->warn("inventory session {$session->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dry ? 'Would bridge ' : 'Bridged ')."{$bridged} inventory session(s), skipped: {$skipped}.");
    }

    /**
     * Submitted mobile purchase orders with no live PUR- operation mirror —
     * the pre-bridge backlog the meeting called «المشتريات مش واصلة للمحاسب».
     */
    private function backfillPurchaseOrders(bool $dry): void
    {
        $bridge = app(\Modules\Admin\Services\PurchaseOrderBridgeService::class);
        $bridged = 0;
        $skipped = 0;

        \Modules\Purchase\Models\PurchaseOrder::query()
            ->where('status', '!=', \Modules\Purchase\Enums\OrderStatus::DRAFT->value)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_operations')
                    ->whereColumn('asab_operations.source_id', 'purchase_orders.id')
                    ->where('asab_operations.source_module', \Modules\Admin\Services\PurchaseOrderBridgeService::SOURCE)
                    ->whereNull('asab_operations.deleted_at');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$bridged, &$skipped) {
                foreach ($chunk as $order) {
                    if ($dry) {
                        $this->line("[dry] purchase order {$order->order_number} ({$order->id}) → bridge to PUR op");
                        $bridged++;

                        continue;
                    }

                    try {
                        $bridge->sync($order) !== null ? $bridged++ : $skipped++;
                    } catch (\Throwable $e) {
                        $skipped++;
                        $this->warn("purchase order {$order->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dry ? 'Would bridge ' : 'Bridged ')."{$bridged} purchase order(s), skipped: {$skipped}.");
    }

    private function backfillExpenses(ExpenseBridgeService $bridge, bool $dry): void
    {
        $bridged = 0;
        $skipped = [];

        // Submitted mobile expenses with no live asab_operations mirror. Drafts
        // never bridge; a soft-deleted op means the dashboard removed it — do
        // not resurrect those, so the NOT EXISTS ignores deleted_at on purpose
        // only for live rows.
        Expense::query()
            ->where('status', '!=', 'draft')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_operations')
                    ->whereColumn('asab_operations.source_id', 'expenses.id')
                    ->where('asab_operations.source_module', ExpenseBridgeService::SOURCE)
                    ->whereNull('asab_operations.deleted_at');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$bridged, &$skipped) {
                foreach ($chunk as $expense) {
                    $reason = $this->diagnoseExpense($expense);

                    if ($dry) {
                        $reason === null ? $bridged++ : $skipped[] = [$expense->id, $reason];

                        continue;
                    }

                    try {
                        $bridge->sync($expense) === null
                            ? $skipped[] = [$expense->id, $reason ?? 'SKIPPED']
                            : $bridged++;
                    } catch (\Throwable $e) {
                        $skipped[] = [$expense->id, 'ERROR: '.$e->getMessage()];
                    }
                }
            });

        $this->info(($dry ? '[dry] ' : '')."Expenses — bridgeable: {$bridged}, stranded: ".count($skipped).'.');
        $this->printSkips($skipped, 'expense');
    }

    /**
     * Re-map expenses that ARE mirrored but whose operation still shows the old
     * payload. backfillExpenses() selects on the ABSENCE of a mirror, so a payload
     * bug fixed after the op was minted never reaches production data (2026-07-31:
     * a non-tax invoice bridged with amountHalalas 0 stayed 0 after the fix).
     *
     * Only PENDING operations are candidates — sync() refuses to touch a record
     * the accountant has already approved or rejected.
     *
     * PENDING IS NOT ENOUGH ON ITS OWN, though: invoice verification, document
     * matching and expense→asset conversion all write into the SAME payload while
     * the record is still pending, and sync() replaces that payload wholesale. Any
     * operation carrying that work is skipped, so a data fix can never cost an
     * accountant their review.
     */
    private function resyncExpensePayloads(ExpenseBridgeService $bridge, bool $dry): void
    {
        $resynced = 0;
        $preserved = 0;
        $failed = [];

        Expense::query()
            ->where('status', '!=', 'draft')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_operations')
                    ->whereColumn('asab_operations.source_id', 'expenses.id')
                    ->where('asab_operations.source_module', ExpenseBridgeService::SOURCE)
                    ->where('asab_operations.status', 'pending')
                    ->whereNull('asab_operations.deleted_at');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$resynced, &$preserved, &$failed) {
                foreach ($chunk as $expense) {
                    $op = Operation::withoutGlobalScopes()
                        ->where('source_module', ExpenseBridgeService::SOURCE)
                        ->where('source_id', $expense->id)
                        ->whereNull('deleted_at')
                        ->first();

                    if ($op && $this->carriesAccountantWork($op)) {
                        $preserved++;

                        continue;
                    }

                    if ($dry) {
                        $resynced++;

                        continue;
                    }

                    try {
                        $bridge->sync($expense) === null
                            ? $failed[] = [$expense->id, 'SKIPPED']
                            : $resynced++;
                    } catch (\Throwable $e) {
                        $failed[] = [$expense->id, 'ERROR: '.$e->getMessage()];
                    }
                }
            });

        $this->info(($dry ? '[dry] ' : '')."Expense payloads re-synced: {$resynced}, preserved (accountant work): {$preserved}, failed: ".count($failed).'.');
        $this->printSkips($failed, 'expense');
    }

    /**
     * True when the accountant has already written into this pending operation's
     * payload — invoice verified, document matched, or converted to an asset.
     */
    private function carriesAccountantWork(Operation $op): bool
    {
        foreach (($op->payload['invoices'] ?? []) as $invoice) {
            if (! is_array($invoice)) {
                continue;
            }

            if (! empty($invoice['verified'])
                || ! empty($invoice['convertedToAsset'])
                || ! empty($invoice['assetDraftId'])
                || isset($invoice['documentAmountHalalas'], $invoice['documentInvNum'])
                || ! empty($invoice['documentVendor'])
                || ! empty($invoice['documentDate'])) {
                return true;
            }
        }

        return false;
    }

    private function backfillShifts(BridgeLegacyCashierShift $listener, bool $dry): void
    {
        $bridged = 0;
        $skipped = [];

        // Completed cashier shifts whose asab mirror is absent or never closed.
        CashierShift::query()
            ->where('status', ShiftStatus::COMPLETED->value)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_shifts')
                    ->whereColumn('asab_shifts.legacy_shift_id', 'cashier_shifts.id')
                    ->whereNotIn('asab_shifts.status', ['active', 'late']);
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($listener, $dry, &$bridged, &$skipped) {
                foreach ($chunk as $shift) {
                    if ($dry) {
                        $bridged++;

                        continue;
                    }

                    try {
                        $listener->handle(new ShiftEndedEvent($shift, false));
                        $bridged++;
                    } catch (\Throwable $e) {
                        $skipped[] = [$shift->id, 'ERROR: '.$e->getMessage()];
                    }
                }
            });

        $this->info(($dry ? '[dry] ' : '')."Closed shifts — re-driven through the bridge: {$bridged}, failed: ".count($skipped).'.');
        $this->printSkips($skipped, 'cashier_shift');
        if (! $dry && $bridged > 0) {
            $this->comment('Shifts whose cashier/actor is still unlinked were logged by the bridge (grep "shift-bridge: skipped").');
        }
    }

    /** Mirror of ExpenseBridgeService::sync's skip conditions, read-only. */
    private function diagnoseExpense(Expense $expense): ?string
    {
        $branchId = $expense->branchManager?->branch_id;
        if ($branchId === null) {
            return 'BRANCH_MISSING — submitter has no branch';
        }

        $branch = Branch::whereKey($branchId)
            ->first(['id', 'name', 'asab_company_id', 'asab_restaurant_id', 'asab_brand_id']);
        if ($branch === null) {
            return 'BRANCH_GONE — branch row deleted';
        }

        if ($branch->asab_company_id === null && $branch->asab_restaurant_id === null) {
            return "BRANCH_UNLINKED — link branch «{$branch->name}» ({$branch->id}) via PATCH /admin/branches/{id} restaurantId";
        }

        return null; // bridgeable (the linker can heal the rest)
    }

    /** @param array<int, array{0: string, 1: string}> $skipped */
    private function printSkips(array $skipped, string $label): void
    {
        if ($skipped === []) {
            return;
        }

        $this->table(["{$label} id", 'why'], array_slice($skipped, 0, 50));
        if (count($skipped) > 50) {
            $this->comment(count($skipped) - 50 .' more — full detail in the log (grep "bridge: skipped").');
        }
    }
}
