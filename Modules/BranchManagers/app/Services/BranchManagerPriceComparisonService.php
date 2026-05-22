<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\SavedPriceComparison;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Supplier\Models\Supplier;

/**
 * Branch-manager price-comparison screen.
 *
 * Reads the saved comparisons recorded by the Purchase module
 * (SavedPriceComparison) and turns a saved recommendation into a real purchase
 * order. Every read and write is scoped to the caller's branch — tenant
 * isolation is enforced through SavedPriceComparison::forBranch().
 */
class BranchManagerPriceComparisonService
{
    /** timeKey enum value => number of days to look back. */
    private const TIME_WINDOWS = [
        'last_24_hours' => 1,
        'last_7_days' => 7,
        'last_30_days' => 30,
    ];

    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly PriceComparisonExportService $exporter,
    ) {}

    /**
     * Paginated list of saved comparisons for a branch.
     *
     * @param  array{timeKey?: ?string, itemId?: ?string, supplierId?: ?string}  $filters
     */
    public function list(string $branchId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = SavedPriceComparison::query()->forBranch($branchId);

        $timeKey = $filters['timeKey'] ?? null;
        if ($timeKey !== null && isset(self::TIME_WINDOWS[$timeKey])) {
            $query->where('created_at', '>=', now()->subDays(self::TIME_WINDOWS[$timeKey]));
        }

        if (! empty($filters['itemId'])) {
            $query->where('item_id', $filters['itemId']);
        }

        if (! empty($filters['supplierId'])) {
            // Supplier / branch source ids live inside the JSON snapshot; a
            // LIKE on the encoded column matches a UUID without collisions and
            // keeps pagination correct on both MySQL and SQLite.
            $query->where('snapshot', 'like', '%'.$filters['supplierId'].'%');
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Find one saved comparison scoped to the branch (tenant isolation).
     */
    public function find(string $id, string $branchId): ?SavedPriceComparison
    {
        return SavedPriceComparison::forBranch($branchId)->find($id);
    }

    /**
     * Export one comparison; returns the public-disk relative path.
     */
    public function export(SavedPriceComparison $comparison, string $formatType): string
    {
        return $this->exporter->export($comparison, $formatType);
    }

    /**
     * Create a purchase order from a saved comparison.
     *
     * With no overrides the recommended source (best_option) from the saved
     * snapshot is used; a client may override the source, quantity, message
     * or notification channels.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws \InvalidArgumentException
     */
    public function createOrderFromComparison(
        SavedPriceComparison $comparison,
        string $branchId,
        string $userId,
        array $overrides
    ): Collection {
        $snapshot = is_array($comparison->snapshot) ? $comparison->snapshot : [];

        $itemId = $snapshot['item_id'] ?? $comparison->item_id;
        if (! $itemId) {
            throw new \InvalidArgumentException('This comparison has no item to order.');
        }
        if (! Item::whereKey($itemId)->exists()) {
            throw new \InvalidArgumentException('The item in this comparison no longer exists.');
        }

        $quantity = isset($overrides['quantity'])
            ? (float) $overrides['quantity']
            : (float) ($snapshot['quantity'] ?? $comparison->quantity ?? 1);

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Order quantity must be greater than zero.');
        }

        $recommended = is_array($snapshot['best_option'] ?? null) ? $snapshot['best_option'] : [];

        $sourceType = $overrides['source_type']
            ?? $recommended['source_type']
            ?? $recommended['type']
            ?? null;

        if (! $sourceType) {
            throw new \InvalidArgumentException(
                'This comparison has no recommended source. Provide source_type explicitly.'
            );
        }

        // An explicit source_type override means the recommended source_id no
        // longer applies; require the client to supply the matching id.
        $sourceId = array_key_exists('source_type', $overrides)
            ? ($overrides['source_id'] ?? null)
            : ($recommended['source_id'] ?? null);

        $payload = $this->buildOrderPayload($sourceType, $sourceId, $itemId, $quantity, $overrides);

        return $this->orderService->createMultipleOrders(
            $payload,
            $branchId,
            $userId,
            (bool) ($overrides['is_draft'] ?? false),
            false
        );
    }

    /**
     * Build the createMultipleOrders() payload for a single recommended source.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildOrderPayload(
        string $sourceType,
        ?string $sourceId,
        string $itemId,
        float $quantity,
        array $overrides
    ): array {
        $item = ['item_id' => $itemId, 'quantity' => $quantity];
        $message = $overrides['message'] ?? null;

        return match ($sourceType) {
            'direct_supplier' => [
                'direct_supplier' => [[
                    'supplier_id' => $this->requireSupplier($sourceId),
                    'notification_channels' => $overrides['notification_channels'] ?? ['in_app'],
                    'message' => $message,
                    'items' => [$item],
                ]],
            ],
            'internal_transfer' => [
                'branches' => [[
                    'branch_id' => $this->requireBranch($sourceId),
                    'justification' => $message,
                    'items' => [$item],
                ]],
            ],
            'via_purchasing_officer' => [
                'purchase_officer' => [[
                    'message' => $message,
                    'items' => [$item],
                ]],
            ],
            default => throw new \InvalidArgumentException("Unsupported source type: {$sourceType}"),
        };
    }

    private function requireSupplier(?string $supplierId): string
    {
        if (! $supplierId) {
            throw new \InvalidArgumentException('A supplier is required to order from a direct supplier.');
        }
        if (! Supplier::whereKey($supplierId)->exists()) {
            throw new \InvalidArgumentException('The selected supplier no longer exists.');
        }

        return $supplierId;
    }

    private function requireBranch(?string $branchId): string
    {
        if (! $branchId) {
            throw new \InvalidArgumentException('A source branch is required for an internal transfer.');
        }
        if (! Branch::whereKey($branchId)->exists()) {
            throw new \InvalidArgumentException('The selected source branch no longer exists.');
        }

        return $branchId;
    }
}
