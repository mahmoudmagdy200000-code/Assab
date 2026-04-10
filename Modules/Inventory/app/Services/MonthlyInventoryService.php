<?php

namespace Modules\Inventory\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Events\MonthlyInventorySessionUpdated;
use Modules\Inventory\Enums\MonthlyInventoryStatus;
use Modules\Inventory\Enums\MonthlyInventoryTimelineEventType;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Inventory\Models\MonthlyInventoryFeedback;
use Modules\Inventory\Models\MonthlyInventoryProduct;
use Modules\Inventory\Models\MonthlyInventoryStaff;
use Modules\Inventory\Models\MonthlyInventoryTimeline;
use Modules\Inventory\Repositories\MonthlyInventoryRepository;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrderItem;

class MonthlyInventoryService
{
    private const EXPECTED_MINUTES_PER_PRODUCT = 0.5;
    private const MIN_EXPECTED_MINUTES = 45;
    private const MAX_EXPECTED_MINUTES = 60;

    public function __construct(
        private readonly MonthlyInventoryRepository $repository,
        private readonly InventorySessionService $sessionService
    ) {}

    /**
     * Get setup info: date, product count, expected time.
     */
    public function getSetupInfo(string $branchId): array
    {
        $items = $this->sessionService->getBranchItems($branchId, true);
        $count = $items->count();

        $expectedMinutes = (int) min(
            max(self::MIN_EXPECTED_MINUTES, ceil($count * self::EXPECTED_MINUTES_PER_PRODUCT)),
            self::MAX_EXPECTED_MINUTES
        );

        return [
            'date' => now()->format('Y-m-d'),
            'number_of_products' => $count,
            'expected_time_minutes' => $expectedMinutes,
            'expected_time_label' => $expectedMinutes . '-' . min($expectedMinutes + 15, self::MAX_EXPECTED_MINUTES) . ' Minutes',
        ];
    }

    /**
     * Get staff options for dropdowns (Cashiers in branch).
     */
    public function getStaffOptions(string $branchId): Collection
    {
        return $this->sessionService->getAvailableCashiers($branchId);
    }

    /**
     * Get last inventory team for "Use Same Team" suggestion.
     */
    public function getLastTeam(string $branchId): array
    {
        $last = $this->repository->getLastCompletedForBranch($branchId);
        if (!$last || $last->staff->isEmpty()) {
            return [];
        }

        return $last->staff->map(function (MonthlyInventoryStaff $s) {
            $user = $s->user;
            return [
                'id' => $s->user_id,
                'type' => $s->user_type,
                'name' => $user?->name ?? 'Unknown',
                'role' => $s->role,
            ];
        })->values()->all();
    }

    /**
     * Get status counts for list tabs (in_progress, draft, completed, etc.).
     *
     * @param array{branch_id: string, created_by?: string} $filters
     * @return array<string, int>
     */
    public function getStatusCounts(array $filters): array
    {
        return $this->repository->getStatusCounts($filters);
    }

    /**
     * Get status counts for a cashier (inventories where they are in staff).
     *
     * @return array<string, int>
     */
    public function getStatusCountsForStaff(string $branchId, string $cashierId): array
    {
        return $this->repository->getStatusCountsForStaff($branchId, $cashierId);
    }

    /**
     * Create and start a monthly inventory.
     *
     * @param array{inventory_date: string, staff?: array<string>} $data
     */
    public function create(array $data, BranchManager $manager): MonthlyInventory
    {
        return DB::transaction(function () use ($data, $manager) {
            $branchId = $manager->branch_id;
            $items = $this->sessionService->getBranchItems($branchId, true);
            $count = $items->count();
            $expectedMinutes = (int) min(
                max(self::MIN_EXPECTED_MINUTES, ceil($count * self::EXPECTED_MINUTES_PER_PRODUCT)),
                self::MAX_EXPECTED_MINUTES
            );

            $inventory = $this->repository->create([
                'branch_id' => $branchId,
                'created_by' => $manager->id,
                'inventory_date' => $data['inventory_date'],
                'start_time' => now(),
                'number_of_products' => $count,
                'expected_time_minutes' => $expectedMinutes,
                'status' => MonthlyInventoryStatus::IN_PROGRESS,
            ]);

            $this->attachTeam($inventory, $manager, $data['staff'] ?? []);
            $this->seedProductsFromAssignedItems($inventory, $items, $branchId);

            MonthlyInventoryTimeline::log(
                $inventory,
                MonthlyInventoryTimelineEventType::CREATED,
                'Monthly inventory created',
                'Team assigned, products initialized.',
                null,
                MonthlyInventoryStatus::IN_PROGRESS->value
            );

            return $inventory->fresh(['branch', 'createdBy', 'staff', 'products']);
        });
    }

    /**
     * @param array<string> $staffIds Cashier IDs
     */
    private function attachTeam(MonthlyInventory $inventory, BranchManager $manager, array $staffIds): void
    {
        $cashiers = Cashier::where('branch_id', $manager->branch_id)
            ->where('status', 'active')
            ->whereIn('id', $staffIds)
            ->pluck('id')
            ->all();

        MonthlyInventoryStaff::create([
            'monthly_inventory_id' => $inventory->id,
            'user_id' => $manager->id,
            'user_type' => $manager->getMorphClass(),
            'role' => MonthlyInventoryStaff::ROLE_TEAM_LEADER,
        ]);

        foreach ($cashiers as $cashierId) {
            MonthlyInventoryStaff::create([
                'monthly_inventory_id' => $inventory->id,
                'user_id' => $cashierId,
                'user_type' => (new Cashier)->getMorphClass(),
                'role' => MonthlyInventoryStaff::ROLE_STAFF,
            ]);
        }
    }

    private function seedProductsFromAssignedItems(MonthlyInventory $inventory, Collection $items, string $branchId): void
    {
        $seenItemIds = [];
        foreach ($items as $row) {
            $itemId = $row['item_id'] ?? null;
            if (!$itemId || isset($seenItemIds[$itemId])) {
                continue;
            }
            $seenItemIds[$itemId] = true;

            $poItem = null;
            if (!empty($row['purchase_order_item_id'])) {
                $poItem = PurchaseOrderItem::with('item')->find($row['purchase_order_item_id']);
            }
            $unit = $row['item_unit'] ?? $poItem?->item?->unit ?? $poItem?->unit_of_measurement ?? 'unit';
            if (is_array($unit) || is_object($unit)) {
                $unit = 'unit';
            }

            MonthlyInventoryProduct::create([
                'monthly_inventory_id' => $inventory->id,
                'item_id' => $itemId,
                'purchase_order_item_id' => $poItem?->id,
                'item_name' => $row['item_name'] ?? $poItem?->item_name ?? 'Unknown',
                'unit' => $unit,
                'quantity_inventory' => 0,
                'unit_price' => (float) ($row['price'] ?? $row['unit_price'] ?? $poItem?->unit_price ?? 0),
                'category' => $row['category'] ?? $poItem?->category,
                'subcategory' => $row['subcategory'] ?? $poItem?->subcategory,
                'branch_id' => $branchId,
            ]);
        }
    }

    /**
     * @param array{branch_id?: string, created_by?: string, status?: string, date_from?: string, date_to?: string} $filters
     */
    public function listByStatus(string $branchId, ?string $status, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $filters['branch_id'] = $branchId;
        if ($status) {
            $filters['status'] = $status;
        }

        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * List monthly inventories for a cashier (where they are in staff).
     *
     * @param array{date_from?: string, date_to?: string} $filters
     */
    public function listByStatusForStaff(string $branchId, string $cashierId, ?string $status, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        if ($status) {
            $filters['status'] = $status;
        }

        return $this->repository->getPaginatedForStaff($branchId, $cashierId, $filters, $perPage);
    }

    public function findForBranch(string $id, string $branchId, ?string $createdBy = null, array $relations = []): ?MonthlyInventory
    {
        if ($createdBy) {
            return $this->repository->findByBranchAndCreator($id, $branchId, $createdBy, $relations);
        }

        return $this->repository->findByBranch($id, $branchId, $relations);
    }

    /**
     * Find inventory by branch and either creator (manager) or staff membership (cashier).
     */
    public function findForBranchOrStaff(string $id, string $branchId, ?string $createdBy, ?string $staffCashierId, array $relations = []): ?MonthlyInventory
    {
        return $this->repository->findByBranchOrStaff($id, $branchId, $createdBy, $staffCashierId, $relations);
    }

    /**
     * Get pending products (not yet counted or for list). Optional search.
     */
    public function getPendingProducts(string $inventoryId, ?string $search = null): Collection
    {
        $query = MonthlyInventoryProduct::where('monthly_inventory_id', $inventoryId)
            ->with(['item', 'purchaseOrderItem', 'handledBy', 'countedBy']);

        if ($search !== null && $search !== '') {
            $query->where('item_name', 'like', '%' . $search . '%');
        }

        return $query->orderBy('item_name')->get();
    }

    /**
     * Get all products with optional search (for completed list).
     */
    public function getProducts(string $inventoryId, ?string $search = null, bool $completedOnly = false): Collection
    {
        $query = MonthlyInventoryProduct::where('monthly_inventory_id', $inventoryId)
            ->with(['item', 'purchaseOrderItem', 'handledBy', 'countedBy']);

        if ($search !== null && $search !== '') {
            $query->where('item_name', 'like', '%' . $search . '%');
        }

        if ($completedOnly) {
            $query->where('quantity_inventory', '>', 0);
        }

        return $query->orderBy('item_name')->get();
    }

    /**
     * Update product quantity. Optional count_method and count_metadata for slider.
     *
     * @param array{quantity_inventory: float, count_method?: string, count_metadata?: array} $data
     */
    public function updateProductQuantity(string $inventoryId, string $productId, array $data, BranchManager|Cashier $actor): MonthlyInventoryProduct
    {
        Log::info('updateProductQuantity CALLED', [
            'inventory_id' => $inventoryId,
            'product_id' => $productId,
            'actor' => get_class($actor) . ':' . $actor->getKey(),
        ]);

        $fresh = DB::transaction(function () use ($inventoryId, $productId, $data, $actor) {
            $product = MonthlyInventoryProduct::where('monthly_inventory_id', $inventoryId)
                ->where('id', $productId)
                ->firstOrFail();

            $inventory = $product->monthlyInventory;
            $this->ensureProductBelongsToInventoryBranch($inventory, $product);
            if (!$inventory->status->isEditable()) {
                throw new \InvalidArgumentException('Inventory is not editable in current status.');
            }

            if (
                $product->handled_by_id !== null
                && (
                    $product->handled_by_id !== $actor->getKey()
                    || $product->handled_by_type !== $actor->getMorphClass()
                )
            ) {
                throw new \InvalidArgumentException('This product is locked by another staff member.');
            }

            $update = [
                'quantity_inventory' => $data['quantity_inventory'] ?? $product->quantity_inventory,
            ];
            $update['counted_by_id'] = $actor->getKey();
            $update['counted_by_type'] = $actor->getMorphClass();
            if (isset($data['count_method'])) {
                $update['count_method'] = $data['count_method'];
            }
            if (isset($data['count_metadata'])) {
                $update['count_metadata'] = $data['count_metadata'];
            }

            $product->update($update);
            MonthlyInventoryTimeline::log(
                $inventory,
                MonthlyInventoryTimelineEventType::PRODUCT_COUNT_UPDATED,
                'Product quantity updated',
                $product->item_name,
                null,
                null,
                [
                    'product_id' => $product->id,
                    'item_id' => $product->item_id,
                    'quantity_inventory' => (float) $product->quantity_inventory,
                ]
            );

            return $product->fresh(['item', 'purchaseOrderItem', 'handledBy', 'countedBy']);
        });

        $this->broadcastInventoryEvent($inventoryId, 'product.updated', [
            'product' => $fresh->toArray(),
        ]);

        return $fresh;
    }

    public function claimProduct(string $inventoryId, string $productId, BranchManager|Cashier $user): MonthlyInventoryProduct
    {
        $fresh = DB::transaction(function () use ($inventoryId, $productId, $user) {
            $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();
            if (!$inventory->status->isEditable()) {
                throw new \InvalidArgumentException('Inventory is not editable in current status.');
            }

            $updatedRows = MonthlyInventoryProduct::where('monthly_inventory_id', $inventoryId)
                ->where('id', $productId)
                ->where(function ($query) use ($user) {
                    $query->whereNull('handled_by_id')
                        ->orWhere(function ($subQuery) use ($user) {
                            $subQuery->where('handled_by_id', $user->getKey())
                                ->where('handled_by_type', $user->getMorphClass());
                        });
                })
                ->update([
                'handled_by_id' => $user->getKey(),
                'handled_by_type' => $user->getMorphClass(),
                'locked_at' => now(),
            ]);

            if ($updatedRows === 0) {
                throw new \InvalidArgumentException('This product is already claimed by another staff member.');
            }

            $product = MonthlyInventoryProduct::where('monthly_inventory_id', $inventoryId)
                ->where('id', $productId)
                ->firstOrFail();
            $this->ensureProductBelongsToInventoryBranch($inventory, $product);

            return $product->fresh(['handledBy', 'countedBy']);
        });

        $this->broadcastInventoryEvent($inventoryId, 'product.claimed', [
            'product' => $fresh->toArray(),
            'actor' => [
                'id' => $user->getKey(),
                'type' => $user->getMorphClass(),
                'name' => $user->name,
            ],
        ]);

        return $fresh;
    }

    public function releaseProduct(string $inventoryId, string $productId, BranchManager|Cashier $actor): MonthlyInventoryProduct
    {
        $fresh = DB::transaction(function () use ($inventoryId, $productId, $actor) {
            $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();
            if (!$inventory->status->isEditable()) {
                throw new \InvalidArgumentException('Inventory is not editable in current status.');
            }

            $product = MonthlyInventoryProduct::where('monthly_inventory_id', $inventoryId)
                ->where('id', $productId)
                ->firstOrFail();
            $this->ensureProductBelongsToInventoryBranch($inventory, $product);

            if (
                $product->handled_by_id !== null
                && (
                    $product->handled_by_id !== $actor->getKey()
                    || $product->handled_by_type !== $actor->getMorphClass()
                )
            ) {
                throw new AuthorizationException('Only the current handler can release this product.');
            }

            $product->update([
                'handled_by_id' => null,
                'handled_by_type' => null,
                'locked_at' => null,
            ]);

            return $product->fresh(['handledBy', 'countedBy']);
        });

        $this->broadcastInventoryEvent($inventoryId, 'product.released', [
            'product' => $fresh->toArray(),
            'actor' => [
                'id' => $actor->getKey(),
                'type' => $actor->getMorphClass(),
                'name' => $actor->name,
            ],
        ]);

        return $fresh;
    }

    /**
     * Get progress: elapsed, completed/total, completed products.
     */
    public function getProgress(string $inventoryId, ?string $search = null): array
    {
        $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();
        $products = $this->getProducts($inventoryId, $search, true);
        $total = $inventory->products()->count();
        $completed = $inventory->products()->where('quantity_inventory', '>', 0)->count();

        $elapsed = $inventory->start_time ? (int) $inventory->start_time->diffInSeconds(now()) : 0;

        return [
            'elapsed_seconds' => $elapsed,
            'elapsed_formatted' => $this->formatElapsed($elapsed),
            'completed' => $completed,
            'total' => $total,
            'completed_products' => $products->values()->all(),
        ];
    }

    private function formatElapsed(int $seconds): string
    {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = (int) ($seconds % 60);

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * Save progress. Optionally move to draft.
     */
    public function saveProgress(string $inventoryId, bool $moveToDraft = false): MonthlyInventory
    {
        $inventory = DB::transaction(function () use ($inventoryId, $moveToDraft) {
            $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();

            if (!$inventory->status->isEditable()) {
                throw new \InvalidArgumentException('Inventory is not editable.');
            }

            if ($moveToDraft && $inventory->status === MonthlyInventoryStatus::IN_PROGRESS) {
                $old = $inventory->status->value;
                $inventory->update(['status' => MonthlyInventoryStatus::DRAFT]);
                MonthlyInventoryTimeline::log(
                    $inventory,
                    MonthlyInventoryTimelineEventType::SAVED,
                    'Progress saved to draft',
                    null,
                    $old,
                    MonthlyInventoryStatus::DRAFT->value
                );
            }

            return $inventory->fresh();
        });

        $this->broadcastInventoryEvent($inventoryId, 'inventory.progress_saved', [
            'status' => $inventory->status->value,
            'move_to_draft' => $moveToDraft,
        ]);

        return $inventory;
    }

    /**
     * Mark for review (all products counted) -> status completed.
     */
    public function markForReview(string $inventoryId): MonthlyInventory
    {
        return DB::transaction(function () use ($inventoryId) {
            $inventory = MonthlyInventory::with('products')->where('id', $inventoryId)->firstOrFail();

            if (!$inventory->status->isEditable()) {
                throw new \InvalidArgumentException('Inventory is not editable.');
            }

            $total = $inventory->products->count();
            $counted = $inventory->products->where('quantity_inventory', '>', 0)->count();
            if ($total > 0 && $counted < $total) {
                throw new \InvalidArgumentException('All products must be counted before review.');
            }

            $old = $inventory->status->value;
            $inventory->update([
                'status' => MonthlyInventoryStatus::COMPLETED,
                'end_time' => now(),
            ]);
            $inventory->calculateTimeTaken();
            $inventory->save();

            MonthlyInventoryTimeline::log(
                $inventory,
                MonthlyInventoryTimelineEventType::REVIEWED,
                'Marked for review',
                'All products counted.',
                $old,
                MonthlyInventoryStatus::COMPLETED->value
            );

            return $inventory->fresh(['products', 'staff']);
        });
    }

    /**
     * Submit for approval. Allowed for Branch Manager (creator) or Cashier (staff member).
     */
    public function submitForApproval(string $inventoryId, BranchManager|Cashier $actor): MonthlyInventory
    {
        $inventory = DB::transaction(function () use ($inventoryId, $actor) {
            $branchId = $actor->branch_id;
            $createdBy = $actor instanceof BranchManager ? $actor->id : null;
            $staffCashierId = $actor instanceof Cashier ? $actor->id : null;
            $inventory = $this->findForBranchOrStaff($inventoryId, $branchId, $createdBy, $staffCashierId);
            if (!$inventory) {
                throw new \InvalidArgumentException('Inventory not found.');
            }

            if (!$inventory->status->canSubmit()) {
                throw new \InvalidArgumentException('Inventory cannot be submitted in current status.');
            }

            $old = $inventory->status->value;
            $inventory->update([
                'status' => MonthlyInventoryStatus::SUBMITTED,
                'submitted_at' => now(),
            ]);

            MonthlyInventoryTimeline::log(
                $inventory,
                MonthlyInventoryTimelineEventType::SUBMITTED,
                'Submitted for approval',
                'Sent to management/finance for review.',
                $old,
                MonthlyInventoryStatus::SUBMITTED->value
            );

            return $inventory->fresh();
        });

        $this->broadcastInventoryEvent($inventoryId, 'inventory.submitted', [
            'status' => MonthlyInventoryStatus::SUBMITTED->value,
            'submitted_at' => now()->toIso8601String(),
        ]);

        return $inventory;
    }

    /**
     * Approve inventory (finance).
     */
    public function approve(string $inventoryId): MonthlyInventory
    {
        return DB::transaction(function () use ($inventoryId) {
            $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();

            if ($inventory->status !== MonthlyInventoryStatus::SUBMITTED && $inventory->status !== MonthlyInventoryStatus::PENDING_FINANCE_REVIEW) {
                throw new \InvalidArgumentException('Only submitted inventories can be approved.');
            }

            $old = $inventory->status->value;
            $inventory->update([
                'status' => MonthlyInventoryStatus::APPROVED,
                'approved_at' => now(),
            ]);

            MonthlyInventoryTimeline::log(
                $inventory,
                MonthlyInventoryTimelineEventType::APPROVED,
                'Inventory approved',
                'Official record for the period.',
                $old,
                MonthlyInventoryStatus::APPROVED->value
            );

            return $inventory->fresh();
        });
    }

    /**
     * Return to draft with feedback.
     */
    public function returnToDraft(string $inventoryId, string $feedback, $author = null): MonthlyInventory
    {
        return DB::transaction(function () use ($inventoryId, $feedback, $author) {
            $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();

            if ($inventory->status !== MonthlyInventoryStatus::SUBMITTED && $inventory->status !== MonthlyInventoryStatus::PENDING_FINANCE_REVIEW) {
                throw new \InvalidArgumentException('Only submitted inventories can be returned to draft.');
            }

            $actor = $author ?? auth()->user();
            MonthlyInventoryFeedback::create([
                'monthly_inventory_id' => $inventory->id,
                'author_id' => $actor?->getKey(),
                'author_type' => $actor ? $actor->getMorphClass() : null,
                'author_name' => $actor?->name ?? 'Management',
                'message' => $feedback,
            ]);

            $old = $inventory->status->value;
            $inventory->update(['status' => MonthlyInventoryStatus::RETURNED_TO_DRAFT]);

            MonthlyInventoryTimeline::log(
                $inventory,
                MonthlyInventoryTimelineEventType::RETURNED_TO_DRAFT,
                'Returned to draft',
                $feedback,
                $old,
                MonthlyInventoryStatus::RETURNED_TO_DRAFT->value
            );

            return $inventory->fresh(['feedback']);
        });
    }

    /**
     * Get full report: summary, team contributions, value, categories.
     */
    public function getReport(string $inventoryId): array
    {
        $inventory = MonthlyInventory::with(['products', 'staff.user', 'branch'])
            ->where('id', $inventoryId)
            ->firstOrFail();

        $products = $inventory->products;
        $totalValue = $products->sum(fn (MonthlyInventoryProduct $p) => $p->line_value);
        $completed = $products->where('quantity_inventory', '>', 0)->count();
        $total = $products->count();

        $byCategory = $products->filter(fn ($p) => (string) $p->category !== '')
            ->groupBy('category')
            ->map(function ($items, $cat) use ($totalValue) {
                $value = $items->sum(fn (MonthlyInventoryProduct $p) => $p->line_value);
                $percentage = $totalValue > 0 ? round((float) $value / (float) $totalValue * 100, 0) : 0;
                return ['category' => $cat, 'value' => round($value, 2), 'percentage' => (int) $percentage, 'items' => $items->count()];
            })
            ->values()
            ->all();

        $countedByCounts = $products->filter(fn (MonthlyInventoryProduct $p) => $p->counted_by_id !== null)
            ->groupBy(fn (MonthlyInventoryProduct $p) => $p->counted_by_type . ':' . $p->counted_by_id)
            ->map->count()
            ->all();

        $teamContributions = [];
        foreach ($inventory->staff as $s) {
            $key = $s->user_type . ':' . $s->user_id;
            $teamContributions[] = [
                'user_id' => $s->user_id,
                'user_type' => $s->user_type,
                'name' => $s->user?->name ?? 'Unknown',
                'role' => $s->role,
                'products_count' => (int) ($countedByCounts[$key] ?? 0),
                'performance' => 'Good',
            ];
        }
        $this->assignPerformanceLabels($teamContributions);

        $comparisonToLastMonth = $this->getComparisonToLastMonthForReport($inventory->branch_id, $inventory->inventory_date, round($totalValue, 2));
        $overallAssessment = $this->deriveOverallAssessment($inventory, $completed, $total, $totalValue);
        $recommendations = $this->deriveRecommendations($inventory, $completed, $total, $teamContributions);

        return [
            'inventory' => $inventory,
            'summary' => [
                'products_complete' => $completed,
                'products_total' => $total,
                'time_taken_seconds' => $inventory->time_taken,
                'time_taken_formatted' => $inventory->time_taken_formatted,
                'participants_count' => $inventory->staff->count(),
                'total_value' => round($totalValue, 2),
                'status' => $inventory->status->value,
            ],
            'overall_assessment' => $overallAssessment,
            'recommendations' => $recommendations,
            'comparison_to_last_month' => $comparisonToLastMonth,
            'team_contributions' => $teamContributions,
            'value_by_category' => $byCategory,
            'products' => $products,
        ];
    }

    /**
     * Compare total value to previous month's inventory for report.
     *
     * @return array{total_value_previous: float, change_percent: float, direction: string}
     */
    private function getComparisonToLastMonthForReport(string $branchId, $inventoryDate, float $currentTotalValue): array
    {
        $date = $inventoryDate instanceof \Carbon\Carbon ? $inventoryDate : \Carbon\Carbon::parse($inventoryDate);
        $prevMonth = $date->copy()->subMonth();
        $previous = MonthlyInventory::where('branch_id', $branchId)
            ->whereYear('inventory_date', $prevMonth->year)
            ->whereMonth('inventory_date', $prevMonth->month)
            ->whereIn('status', [MonthlyInventoryStatus::COMPLETED, MonthlyInventoryStatus::SUBMITTED, MonthlyInventoryStatus::APPROVED])
            ->with('products')
            ->orderBy('inventory_date', 'desc')
            ->first();

        $previousTotal = $previous ? $previous->products->sum(fn (MonthlyInventoryProduct $p) => $p->line_value) : 0.0;
        $previousTotal = round((float) $previousTotal, 2);
        if ($previousTotal <= 0) {
            return [
                'total_value_previous' => $previousTotal,
                'change_percent' => $currentTotalValue > 0 ? 100.0 : 0.0,
                'direction' => $currentTotalValue > 0 ? 'higher' : 'same',
            ];
        }
        $changePercent = (($currentTotalValue - $previousTotal) / $previousTotal) * 100;
        $direction = $changePercent > 0 ? 'higher' : ($changePercent < 0 ? 'lower' : 'same');
        return [
            'total_value_previous' => $previousTotal,
            'change_percent' => round($changePercent, 2),
            'direction' => $direction,
        ];
    }

    private function deriveOverallAssessment(MonthlyInventory $inventory, int $completed, int $total, float $totalValue): string
    {
        $completionPct = $total > 0 ? ($completed / $total) * 100 : 0;
        $expectedMinutes = $inventory->expected_time_minutes ?? 60;
        $timeTakenSeconds = $inventory->time_taken ?? 0;
        $timeTakenMinutes = $timeTakenSeconds / 60;
        $withinTime = $timeTakenMinutes <= ($expectedMinutes + 15);

        if ($completionPct >= 100 && $withinTime && $totalValue > 0) {
            return 'Excellent';
        }
        if ($completionPct >= 90 && $withinTime) {
            return 'Very Good';
        }
        return 'Good';
    }

    /**
     * Rule-based recommendations for next month.
     *
     * @param array<int, array{products_count: int, performance: string}> $teamContributions
     * @return array<int, string>
     */
    private function deriveRecommendations(MonthlyInventory $inventory, int $completed, int $total, array $teamContributions): array
    {
        $recommendations = [];
        $completionPct = $total > 0 ? ($completed / $total) * 100 : 0;
        $expectedMinutes = $inventory->expected_time_minutes ?? 60;
        $timeTakenSeconds = $inventory->time_taken ?? 0;
        $timeTakenMinutes = $timeTakenSeconds / 60;

        if ($completionPct >= 100 && $timeTakenMinutes <= $expectedMinutes + 15) {
            $recommendations[] = 'Maintain current performance level';
        }
        if ($timeTakenMinutes > $expectedMinutes + 15 || ($total > 0 && $completionPct < 100)) {
            $recommendations[] = 'Improve inventory timing';
        }
        $excellentCount = count(array_filter($teamContributions, fn ($t) => ($t['performance'] ?? '') === 'Excellent'));
        if ($excellentCount < count($teamContributions) && count($teamContributions) > 0) {
            $recommendations[] = 'Increase team training';
        }
        if ($recommendations === []) {
            $recommendations[] = 'Maintain current performance level';
        }
        return array_values(array_unique($recommendations));
    }

    /**
     * Assign performance labels (Excellent, Very Good, Good) by products_count rank.
     *
     * @param array<int, array{products_count: int, performance: string}> $teamContributions
     */
    private function assignPerformanceLabels(array &$teamContributions): void
    {
        $sorted = $teamContributions;
        usort($sorted, fn ($a, $b) => ($b['products_count'] ?? 0) <=> ($a['products_count'] ?? 0));
        $rank = 0;
        foreach ($sorted as &$row) {
            $row['performance'] = match ($rank) {
                0 => 'Excellent',
                1 => 'Very Good',
                default => 'Good',
            };
            $rank++;
        }
        unset($row);
        $byKey = collect($sorted)->keyBy(fn ($r) => $r['user_type'] . ':' . $r['user_id'])->all();
        foreach ($teamContributions as &$row) {
            $key = $row['user_type'] . ':' . $row['user_id'];
            if (isset($byKey[$key])) {
                $row['performance'] = $byKey[$key]['performance'];
            }
        }
        unset($row);
    }

    /**
     * Get month-over-month comparison.
     */
    public function getMonthlyComparison(string $branchId, int $year, int $month): array
    {
        $thisMonth = \Carbon\Carbon::createFromDate($year, $month, 1);
        $prevMonth = $thisMonth->copy()->subMonth();

        $current = MonthlyInventory::where('branch_id', $branchId)
            ->whereYear('inventory_date', $thisMonth->year)
            ->whereMonth('inventory_date', $thisMonth->month)
            ->whereIn('status', [MonthlyInventoryStatus::COMPLETED, MonthlyInventoryStatus::SUBMITTED, MonthlyInventoryStatus::APPROVED])
            ->with('products')
            ->orderBy('inventory_date', 'desc')
            ->first();

        $previous = MonthlyInventory::where('branch_id', $branchId)
            ->whereYear('inventory_date', $prevMonth->year)
            ->whereMonth('inventory_date', $prevMonth->month)
            ->whereIn('status', [MonthlyInventoryStatus::COMPLETED, MonthlyInventoryStatus::SUBMITTED, MonthlyInventoryStatus::APPROVED])
            ->with('products')
            ->orderBy('inventory_date', 'desc')
            ->first();

        $currByItem = $current ? $current->products->keyBy('item_id') : collect();
        $prevByItem = $previous ? $previous->products->keyBy('item_id') : collect();

        $allItemIds = $currByItem->keys()->merge($prevByItem->keys())->unique()->filter();

        $items = [];
        foreach ($allItemIds as $itemId) {
            $c = $currByItem->get($itemId);
            $p = $prevByItem->get($itemId);
            $currQty = $c ? (float) $c->quantity_inventory : 0.0;
            $prevQty = $p ? (float) $p->quantity_inventory : 0.0;

            $changePct = $prevQty != 0
                ? (($currQty - $prevQty) / $prevQty) * 100
                : ($currQty > 0 ? 100.0 : 0.0);

            $items[] = [
                'item_id' => $itemId,
                'item_name' => $c?->item_name ?? $p?->item_name ?? 'Unknown',
                'unit' => $c?->unit ?? $p?->unit ?? 'unit',
                'current_quantity' => $currQty,
                'previous_quantity' => $prevQty,
                'change_percent' => round($changePct, 2),
                'not_in_current_report' => !$c && $p,
            ];
        }

        return [
            'period' => ['year' => $year, 'month' => $month],
            'items' => $items,
        ];
    }

    public function getTimelines(string $inventoryId): Collection
    {
        return MonthlyInventoryTimeline::where('monthly_inventory_id', $inventoryId)
            ->orderBy('occurred_at', 'asc')
            ->get();
    }

    public function getFeedback(string $inventoryId): Collection
    {
        return MonthlyInventoryFeedback::where('monthly_inventory_id', $inventoryId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function addFeedback(string $inventoryId, string $message, $author = null): MonthlyInventoryFeedback
    {
        $inventory = MonthlyInventory::where('id', $inventoryId)->firstOrFail();
        $actor = $author ?? auth()->user();

        return MonthlyInventoryFeedback::create([
            'monthly_inventory_id' => $inventory->id,
            'author_id' => $actor?->getKey(),
            'author_type' => $actor ? $actor->getMorphClass() : null,
            'author_name' => $actor?->name ?? 'Management',
            'message' => $message,
        ]);
    }

    private function ensureProductBelongsToInventoryBranch(MonthlyInventory $inventory, MonthlyInventoryProduct $product): void
    {
        if ($product->branch_id !== $inventory->branch_id) {
            throw new \InvalidArgumentException('Product does not belong to this inventory assignment scope.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function broadcastInventoryEvent(string $inventoryId, string $eventType, array $payload): void
    {
        Log::info('Broadcasting inventory event', [
            'inventory_id' => $inventoryId,
            'event_type' => $eventType,
            'channel' => 'inventory.monthly.' . $inventoryId,
        ]);

        event(new MonthlyInventorySessionUpdated($inventoryId, $eventType, $payload));
    }
}
