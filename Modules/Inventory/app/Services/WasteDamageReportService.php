<?php

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\CauseOfDamage;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Enums\WasteDamageReportTimelineEventType;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Models\WasteDamageReportItem;
use Modules\Inventory\Models\WasteDamageReportItemEmployee;
use Modules\Inventory\Models\WasteDamageReportTimeline;
use Modules\Inventory\Repositories\WasteDamageReportItemRepository;
use Modules\Inventory\Repositories\WasteDamageReportRepository;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrderItem;

class WasteDamageReportService
{
    private const PHOTO_REQUIRED_THRESHOLD_SAR = 20;

    private const REPORT_NOT_EDITABLE_MESSAGE = 'Report not found or not editable.';

    public function __construct(
        private readonly WasteDamageReportRepository $reportRepository,
        private readonly WasteDamageReportItemRepository $itemRepository
    ) {}

    /**
     * List reports for branch (paginated). Optionally filter by status and by assignee (for cashier scope).
     */
    public function listReportsByBranch(string $branchId, int $perPage = 15, ?string $status = null, ?string $assignedToId = null): LengthAwarePaginator
    {
        $filters = ['branch_id' => $branchId];
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }
        if ($assignedToId !== null && $assignedToId !== '') {
            $filters['assigned_to_id'] = $assignedToId;
        }

        return $this->reportRepository->getPaginated($filters, $perPage);
    }

    /**
     * Counts by status for filter tabs (Draft, Pending, Pending your confirmation, Completed).
     *
     * @return array{draft: int, pending: int, pending_your_confirmation: int, completed: int}
     */
    public function getFilterCountsByBranch(string $branchId): array
    {
        return $this->reportRepository->getFilterCountsByBranch($branchId);
    }

    /**
     * Find report by id and branch (for show). Optionally scope by assignee for cashier.
     *
     * @param  array<int, string>  $relations
     */
    public function findReportForBranch(string $reportId, string $branchId, array $relations = [], ?string $assignedToId = null): ?WasteDamageReport
    {
        return $this->reportRepository->findByBranch($reportId, $branchId, $relations, $assignedToId);
    }

    /**
     * Create a draft waste & damage report.
     *
     * @param  'personal'|'staff'  $assignedToType
     */
    public function createReport(string $branchId, string $createdBy, string $assignedToType = 'personal', ?string $assignedToId = null, string $createdByType = 'branch_manager'): WasteDamageReport
    {
        $report = $this->reportRepository->create([
            'branch_id' => $branchId,
            'created_by' => $createdBy,
            'created_by_type' => $createdByType,
            'assigned_to_type' => $assignedToType,
            'assigned_to_id' => $assignedToType === 'staff' ? $assignedToId : null,
            'status' => WasteDamageReportStatus::PENDING,
        ]);

        WasteDamageReportTimeline::log(
            $report,
            WasteDamageReportTimelineEventType::CREATED,
            WasteDamageReportTimelineEventType::CREATED->label()
        );

        return $report;
    }

    /**
     * Create a draft report and add multiple items in one go (single transaction).
     *
     * @param  'personal'|'staff'  $assignedToType
     * @param  array<int, array{item_id: string, purchase_order_item_id?: string|null, problem_type: string, cause_of_damage?: string|null, quantity: float, reason: string, unit?: string|null, justification_text?: string|null, photo_path?: string|null, price_per_unit?: float|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>}>  $items
     */
    public function createReportWithItems(string $branchId, string $createdBy, string $assignedToType = 'personal', ?string $assignedToId = null, array $items = []): WasteDamageReport
    {
        return DB::transaction(function () use ($branchId, $createdBy, $assignedToType, $assignedToId, $items) {
            $report = $this->createReport($branchId, $createdBy, $assignedToType, $assignedToId);

            foreach ($items as $itemData) {
                $pricePerUnit = $itemData['price_per_unit'] ?? $this->resolvePricePerUnit($itemData['item_id'], $branchId);
                $unit = $itemData['unit'] ?? $this->resolveUnit($itemData['item_id']);
                $quantity = (float) $itemData['quantity'];
                $totalValue = -1 * $quantity * (float) $pricePerUnit;
                $itemData = $this->normalizeResponsibleForIWasResponsible($itemData, $quantity);

                $this->validateItemData($itemData, $quantity, $totalValue);

                $purchaseOrderItemId = $itemData['purchase_order_item_id'] ?? $this->resolvePurchaseOrderItemId($itemData['item_id'], $branchId);

                $item = $this->itemRepository->create([
                    'waste_damage_report_id' => $report->id,
                    'branch_id' => $branchId,
                    'item_id' => $itemData['item_id'],
                    'purchase_order_item_id' => $purchaseOrderItemId,
                    'problem_type' => $itemData['problem_type'],
                    'cause_of_damage' => $itemData['cause_of_damage'] ?? null,
                    'quantity' => $quantity,
                    'reason' => $itemData['reason'],
                    'unit' => $unit,
                    'total_value' => $totalValue,
                    'justification_text' => $itemData['justification_text'] ?? null,
                    'photo_path' => $itemData['photo_path'] ?? null,
                    'price_per_unit' => $pricePerUnit,
                ]);

                $this->syncResponsibleEmployees($item, $itemData['responsible_employees'] ?? [], $createdBy, null, (float) ($itemData['my_quantity_accountable'] ?? 0));
            }

            return $report->load(['items.responsibleEmployees.cashier.branch', 'items.responsibleEmployees.branchManager', 'items.item']);
        });
    }

    /**
     * Add multiple items to an existing report (e.g. after storing photos under report id).
     *
     * @param  array<int, array{item_id: string, purchase_order_item_id?: string|null, problem_type: string, cause_of_damage?: string|null, quantity: float, reason: string, unit?: string|null, justification_text?: string|null, photo_path?: string|null, price_per_unit?: float|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>}>  $items
     */
    public function addItemsToReport(WasteDamageReport $report, array $items, ?string $actorBranchManagerId, ?string $actorCashierId = null): WasteDamageReport
    {
        $branchId = $report->branch_id;

        return DB::transaction(function () use ($report, $branchId, $items, $actorBranchManagerId, $actorCashierId) {
            foreach ($items as $itemData) {
                $pricePerUnit = $itemData['price_per_unit'] ?? $this->resolvePricePerUnit($itemData['item_id'], $branchId);
                $unit = $itemData['unit'] ?? $this->resolveUnit($itemData['item_id']);
                $quantity = (float) $itemData['quantity'];
                $totalValue = -1 * $quantity * (float) $pricePerUnit;
                $itemData = $this->normalizeResponsibleForIWasResponsible($itemData, $quantity);

                $this->validateItemData($itemData, $quantity, $totalValue);

                $purchaseOrderItemId = $itemData['purchase_order_item_id'] ?? $this->resolvePurchaseOrderItemId($itemData['item_id'], $branchId);

                $item = $this->itemRepository->create([
                    'waste_damage_report_id' => $report->id,
                    'branch_id' => $branchId,
                    'item_id' => $itemData['item_id'],
                    'purchase_order_item_id' => $purchaseOrderItemId,
                    'problem_type' => $itemData['problem_type'],
                    'cause_of_damage' => $itemData['cause_of_damage'] ?? null,
                    'quantity' => $quantity,
                    'reason' => $itemData['reason'],
                    'unit' => $unit,
                    'total_value' => $totalValue,
                    'justification_text' => $itemData['justification_text'] ?? null,
                    'photo_path' => $itemData['photo_path'] ?? null,
                    'price_per_unit' => $pricePerUnit,
                ]);

                $this->syncResponsibleEmployees($item, $itemData['responsible_employees'] ?? [], $actorBranchManagerId, $actorCashierId, (float) ($itemData['my_quantity_accountable'] ?? 0));
            }

            return $report->load(['items.responsibleEmployees.cashier.branch', 'items.responsibleEmployees.branchManager', 'items.item']);
        });
    }

    /**
     * Add a product line to the report.
     *
     * @param  array{item_id: string, purchase_order_item_id?: string|null, problem_type: string, cause_of_damage?: string|null, quantity: float, reason: string, unit?: string|null, justification_text?: string|null, photo_path?: string|null, price_per_unit?: float|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>}  $data
     */
    public function addItem(string $reportId, string $branchId, array $data, ?string $actorBranchManagerId, ?string $actorCashierId = null, ?string $assignedToId = null): WasteDamageReportItem
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId, [], $assignedToId);
        if (! $report || ! $report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => [self::REPORT_NOT_EDITABLE_MESSAGE]]);
        }

        $pricePerUnit = $data['price_per_unit'] ?? $this->resolvePricePerUnit($data['item_id'], $branchId);
        $unit = $data['unit'] ?? $this->resolveUnit($data['item_id']);
        $quantity = (float) $data['quantity'];
        $totalValue = -1 * $quantity * (float) $pricePerUnit;
        $data = $this->normalizeResponsibleForIWasResponsible($data, $quantity);

        $this->validateItemData($data, $quantity, $totalValue);

        $purchaseOrderItemId = $data['purchase_order_item_id'] ?? $this->resolvePurchaseOrderItemId($data['item_id'], $branchId);

        return DB::transaction(function () use ($report, $branchId, $data, $pricePerUnit, $unit, $quantity, $totalValue, $actorBranchManagerId, $actorCashierId, $purchaseOrderItemId) {
            $item = $this->itemRepository->create([
                'waste_damage_report_id' => $report->id,
                'branch_id' => $branchId,
                'item_id' => $data['item_id'],
                'purchase_order_item_id' => $purchaseOrderItemId,
                'problem_type' => $data['problem_type'],
                'cause_of_damage' => $data['cause_of_damage'] ?? null,
                'quantity' => $quantity,
                'reason' => $data['reason'],
                'unit' => $unit,
                'total_value' => $totalValue,
                'justification_text' => $data['justification_text'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
                'price_per_unit' => $pricePerUnit,
            ]);

            $this->syncResponsibleEmployees($item, $data['responsible_employees'] ?? [], $actorBranchManagerId, $actorCashierId, (float) ($data['my_quantity_accountable'] ?? 0));

            return $item->load('responsibleEmployees.cashier.branch', 'responsibleEmployees.branchManager');
        });
    }

    /**
     * When cause is i_was_responsible and no responsible party was sent, default my_quantity_accountable to full quantity.
     *
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    private function normalizeResponsibleForIWasResponsible(array $itemData, float $quantity): array
    {
        $cause = $itemData['cause_of_damage'] ?? null;
        if ($cause !== CauseOfDamage::I_WAS_RESPONSIBLE->value) {
            return $itemData;
        }
        $myQty = (float) ($itemData['my_quantity_accountable'] ?? 0);
        $employees = $itemData['responsible_employees'] ?? [];
        if ($myQty <= 0 && empty($employees)) {
            $itemData['my_quantity_accountable'] = $quantity;
        }

        return $itemData;
    }

    /**
     * Update a report item.
     *
     * @param  array{problem_type?: string, cause_of_damage?: string|null, quantity?: float, reason?: string, justification_text?: string|null, photo_path?: string|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>}  $data
     */
    public function updateItem(string $reportId, string $itemId, string $branchId, array $data, ?string $actorBranchManagerId, ?string $actorCashierId = null, ?string $assignedToId = null): WasteDamageReportItem
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId, [], $assignedToId);
        if (! $report || ! $report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => [self::REPORT_NOT_EDITABLE_MESSAGE]]);
        }

        $item = $this->itemRepository->findByIdAndReport($itemId, $reportId);
        if (! $item) {
            throw ValidationException::withMessages(['item' => ['Report item not found.']]);
        }

        $item->load('responsibleEmployees');

        if ($actorBranchManagerId !== null) {
            $selfRow = $item->responsibleEmployees->firstWhere('branch_manager_id', $actorBranchManagerId);
        } else {
            $selfRow = $actorCashierId !== null
                ? $item->responsibleEmployees->first(fn ($e) => $e->cashier_id === $actorCashierId && $e->branch_manager_id === null)
                : null;
        }
        $existingMyQty = (float) ($selfRow?->quantity_accountable ?? 0);
        $existingCashiers = $item->responsibleEmployees
            ->whereNotNull('cashier_id')
            ->when($selfRow !== null, fn ($c) => $c->where('id', '!=', $selfRow->id))
            ->map(fn ($e) => [
                'cashier_id' => $e->cashier_id,
                'quantity_accountable' => (float) $e->quantity_accountable,
            ])->values()->toArray();

        $pricePerUnit = (float) ($item->price_per_unit ?? 0);
        $quantity = isset($data['quantity']) ? (float) $data['quantity'] : (float) $item->quantity;
        $totalValue = -1 * $quantity * $pricePerUnit;

        $payload = array_merge([
            'problem_type' => $item->problem_type->value,
            'cause_of_damage' => $item->cause_of_damage?->value,
            'quantity' => $quantity,
            'reason' => $item->reason->value,
            'my_quantity_accountable' => $existingMyQty,
            'responsible_employees' => $existingCashiers,
        ], $data);

        $this->validateItemData($payload, $quantity, $totalValue);

        $updateData = [
            'problem_type' => $payload['problem_type'],
            'cause_of_damage' => $payload['cause_of_damage'] ?? null,
            'quantity' => $quantity,
            'reason' => $payload['reason'],
            'total_value' => $totalValue,
            'justification_text' => $payload['justification_text'] ?? null,
            'photo_path' => $payload['photo_path'] ?? $item->photo_path,
        ];
        if ($item->purchase_order_item_id === null) {
            $resolved = $this->resolvePurchaseOrderItemId($item->item_id, $item->branch_id);
            if ($resolved !== null) {
                $updateData['purchase_order_item_id'] = $resolved;
            }
        }

        return DB::transaction(function () use ($item, $payload, $updateData, $actorBranchManagerId, $actorCashierId) {
            $this->itemRepository->update($item, $updateData);

            $this->syncResponsibleEmployees($item, $payload['responsible_employees'] ?? [], $actorBranchManagerId, $actorCashierId, (float) ($payload['my_quantity_accountable'] ?? 0));

            return $item->fresh(['responsibleEmployees.cashier.branch', 'responsibleEmployees.branchManager']);
        });
    }

    /**
     * Remove a report item.
     */
    public function deleteItem(string $reportId, string $itemId, string $branchId, ?string $assignedToId = null): void
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId, [], $assignedToId);
        if (! $report || ! $report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => [self::REPORT_NOT_EDITABLE_MESSAGE]]);
        }

        $item = $this->itemRepository->findByIdAndReport($itemId, $reportId);
        if (! $item) {
            throw ValidationException::withMessages(['item' => ['Report item not found.']]);
        }

        $item->responsibleEmployees()->delete();
        $this->itemRepository->delete($item);
    }

    /**
     * Submit the report (validate all items then set status to pending — pending your confirmation).
     */
    public function submitReport(string $reportId, string $branchId, ?string $assignedToId = null): WasteDamageReport
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId, ['items'], $assignedToId);
        if (! $report) {
            throw ValidationException::withMessages(['report' => ['Report not found.']]);
        }

        if (! $report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => ['Report is already submitted.']]);
        }

        $items = $report->items;
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['report' => ['Report must have at least one item.']]);
        }

        foreach ($items as $reportItem) {
            if ($reportItem->requiresPhoto() && empty($reportItem->photo_path)) {
                throw ValidationException::withMessages([
                    'items' => ['Explanatory photo is required for damage items with value greater than 20 SAR.'],
                ]);
            }
        }

        $oldStatus = $report->status->value;

        $this->reportRepository->update($report, [
            'status' => WasteDamageReportStatus::PENDING_YOUR_CONFIRMATION,
            'submitted_at' => now(),
        ]);

        WasteDamageReportTimeline::log(
            $report->fresh(),
            WasteDamageReportTimelineEventType::SUBMITTED,
            WasteDamageReportTimelineEventType::SUBMITTED->label(),
            null,
            $oldStatus,
            WasteDamageReportStatus::PENDING_YOUR_CONFIRMATION->value
        );

        return $report->fresh();
    }

    private function resolvePricePerUnit(string $itemId, string $branchId): float
    {
        $branchItem = BranchItem::where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->first();

        return $branchItem ? (float) $branchItem->price : 0;
    }

    private function resolveUnit(string $itemId): string
    {
        $item = Item::find($itemId);

        return $item?->unit ?? 'unit';
    }

    /**
     * Resolve purchase_order_item_id from closed orders when not provided.
     * Returns the latest closed PO item for the given item in the branch, or null if none.
     */
    private function resolvePurchaseOrderItemId(string $itemId, string $branchId): ?string
    {
        return PurchaseOrderItem::query()
            ->where('purchase_order_items.item_id', $itemId)
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.branch_id', $branchId)
            ->where('purchase_orders.status', OrderStatus::CLOSED)
            ->orderByDesc('purchase_orders.closed_at')
            ->limit(1)
            ->value('purchase_order_items.id');
    }

    /**
     * @param  array{problem_type: string, cause_of_damage?: string|null, quantity: float, total_value: float, photo_path?: string|null, responsible_employees?: array}  $data
     */
    private function validateItemData(array $data, float $quantity, float $totalValue): void
    {
        $problemType = $data['problem_type'] instanceof ProblemType
            ? $data['problem_type']
            : ProblemType::tryFrom($data['problem_type']);

        if (! $problemType) {
            throw ValidationException::withMessages(['problem_type' => ['Invalid problem type.']]);
        }

        if ($problemType->isDamage()) {
            $cause = $data['cause_of_damage'] ?? null;
            $causeEnum = is_string($cause) ? CauseOfDamage::tryFrom($cause) : $cause;
            if ($causeEnum && $causeEnum->requiresResponsibleEmployees()) {
                $myQty = (float) ($data['my_quantity_accountable'] ?? 0);
                $employees = $data['responsible_employees'] ?? [];
                if ($myQty <= 0 && empty($employees)) {
                    throw ValidationException::withMessages([
                        'responsible_employees' => ['At least one responsible party is required (my_quantity_accountable and/or responsible_employees).'],
                    ]);
                }
                $sum = $myQty + array_sum(array_column($employees, 'quantity_accountable'));
                if (abs($sum - $quantity) > 0.001) {
                    throw ValidationException::withMessages([
                        'responsible_employees' => ['Sum of my_quantity_accountable and responsible_employees quantities must equal the item quantity.'],
                    ]);
                }
            }

            if (abs($totalValue) > self::PHOTO_REQUIRED_THRESHOLD_SAR && empty($data['photo_path'] ?? null)) {
                throw ValidationException::withMessages([
                    'photo' => ['Explanatory photo is required for damage value greater than 20 SAR.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{cashier_id: string, quantity_accountable: float}>  $employees
     */
    private function syncResponsibleEmployees(WasteDamageReportItem $item, array $employees, ?string $actorBranchManagerId = null, ?string $actorCashierId = null, float $myQuantityAccountable = 0): void
    {
        $item->responsibleEmployees()->delete();

        if (($actorBranchManagerId || $actorCashierId) && $myQuantityAccountable > 0) {
            WasteDamageReportItemEmployee::create([
                'waste_damage_report_item_id' => $item->id,
                'branch_manager_id' => $actorBranchManagerId,
                'cashier_id' => $actorBranchManagerId ? null : $actorCashierId,
                'quantity_accountable' => $myQuantityAccountable,
            ]);
        }

        foreach ($employees as $row) {
            if ($actorCashierId !== null && $row['cashier_id'] === $actorCashierId && $myQuantityAccountable > 0) {
                continue;
            }
            WasteDamageReportItemEmployee::create([
                'waste_damage_report_item_id' => $item->id,
                'branch_manager_id' => null,
                'cashier_id' => $row['cashier_id'],
                'quantity_accountable' => (float) $row['quantity_accountable'],
            ]);
        }
    }
}
