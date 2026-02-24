<?php

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\CauseOfDamage;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Models\WasteDamageReportItem;
use Modules\Inventory\Models\WasteDamageReportItemEmployee;
use Modules\Inventory\Repositories\WasteDamageReportItemRepository;
use Modules\Inventory\Repositories\WasteDamageReportRepository;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;

class WasteDamageReportService
{
    private const PHOTO_REQUIRED_THRESHOLD_SAR = 20;

    private const REPORT_NOT_EDITABLE_MESSAGE = 'Report not found or not editable.';

    public function __construct(
        private readonly WasteDamageReportRepository $reportRepository,
        private readonly WasteDamageReportItemRepository $itemRepository
    ) {}

    /**
     * List reports for branch (paginated).
     */
    public function listReportsByBranch(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reportRepository->getPaginated(['branch_id' => $branchId], $perPage);
    }

    /**
     * Counts by status for filter tabs (In Progress, Draft, Completed).
     *
     * @return array{in_progress: int, draft: int, completed: int}
     */
    public function getFilterCountsByBranch(string $branchId): array
    {
        return $this->reportRepository->getFilterCountsByBranch($branchId);
    }

    /**
     * Find report by id and branch (for show).
     *
     * @param array<int, string> $relations
     */
    public function findReportForBranch(string $reportId, string $branchId, array $relations = []): ?WasteDamageReport
    {
        return $this->reportRepository->findByBranch($reportId, $branchId, $relations);
    }

    /**
     * Create a draft waste & damage report.
     *
     * @param 'personal'|'staff' $assignedToType
     */
    public function createReport(string $branchId, string $createdBy, string $assignedToType = 'personal', ?string $assignedToId = null): WasteDamageReport
    {
        return $this->reportRepository->create([
            'branch_id' => $branchId,
            'created_by' => $createdBy,
            'assigned_to_type' => $assignedToType,
            'assigned_to_id' => $assignedToType === 'staff' ? $assignedToId : null,
            'status' => WasteDamageReportStatus::DRAFT,
        ]);
    }

    /**
     * Create a draft report and add multiple items in one go (single transaction).
     *
     * @param 'personal'|'staff' $assignedToType
     * @param array<int, array{item_id: string, purchase_order_item_id?: string|null, problem_type: string, cause_of_damage?: string|null, quantity: float, reason: string, unit?: string|null, justification_text?: string|null, photo_path?: string|null, price_per_unit?: float|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>}> $items
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

                $this->validateItemData($itemData, $quantity, $totalValue);

                $item = $this->itemRepository->create([
                    'waste_damage_report_id' => $report->id,
                    'branch_id' => $branchId,
                    'item_id' => $itemData['item_id'],
                    'purchase_order_item_id' => $itemData['purchase_order_item_id'] ?? null,
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

                $this->syncResponsibleEmployees($item, $itemData['responsible_employees'] ?? [], $createdBy, (float) ($itemData['my_quantity_accountable'] ?? 0));
            }

            return $report->load(['items.responsibleEmployees.cashier.branch', 'items.responsibleEmployees.branchManager', 'items.item']);
        });
    }

    /**
     * Add multiple items to an existing report (e.g. after storing photos under report id).
     *
     * @param array<int, array{item_id: string, purchase_order_item_id?: string|null, problem_type: string, cause_of_damage?: string|null, quantity: float, reason: string, unit?: string|null, justification_text?: string|null, photo_path?: string|null, price_per_unit?: float|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>}> $items
     */
    public function addItemsToReport(WasteDamageReport $report, array $items, string $branchManagerId): WasteDamageReport
    {
        $branchId = $report->branch_id;

        return DB::transaction(function () use ($report, $branchId, $items, $branchManagerId) {
            foreach ($items as $itemData) {
                $pricePerUnit = $itemData['price_per_unit'] ?? $this->resolvePricePerUnit($itemData['item_id'], $branchId);
                $unit = $itemData['unit'] ?? $this->resolveUnit($itemData['item_id']);
                $quantity = (float) $itemData['quantity'];
                $totalValue = -1 * $quantity * (float) $pricePerUnit;

                $this->validateItemData($itemData, $quantity, $totalValue);

                $item = $this->itemRepository->create([
                    'waste_damage_report_id' => $report->id,
                    'branch_id' => $branchId,
                    'item_id' => $itemData['item_id'],
                    'purchase_order_item_id' => $itemData['purchase_order_item_id'] ?? null,
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

                $this->syncResponsibleEmployees($item, $itemData['responsible_employees'] ?? [], $branchManagerId, (float) ($itemData['my_quantity_accountable'] ?? 0));
            }

            return $report->load(['items.responsibleEmployees.cashier.branch', 'items.responsibleEmployees.branchManager', 'items.item']);
        });
    }

    /**
     * Add a product line to the report.
     *
     * @param array{item_id: string, purchase_order_item_id?: string|null, problem_type: string, cause_of_damage?: string|null, quantity: float, reason: string, unit?: string|null, justification_text?: string|null, photo_path?: string|null, price_per_unit?: float|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>} $data
     */
    public function addItem(string $reportId, string $branchId, array $data, string $branchManagerId): WasteDamageReportItem
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId);
        if (!$report || !$report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => [self::REPORT_NOT_EDITABLE_MESSAGE]]);
        }

        $pricePerUnit = $data['price_per_unit'] ?? $this->resolvePricePerUnit($data['item_id'], $branchId);
        $unit = $data['unit'] ?? $this->resolveUnit($data['item_id']);
        $quantity = (float) $data['quantity'];
        $totalValue = -1 * $quantity * (float) $pricePerUnit;

        $this->validateItemData($data, $quantity, $totalValue);

        return DB::transaction(function () use ($report, $branchId, $data, $pricePerUnit, $unit, $quantity, $totalValue, $branchManagerId) {
            $item = $this->itemRepository->create([
                'waste_damage_report_id' => $report->id,
                'branch_id' => $branchId,
                'item_id' => $data['item_id'],
                'purchase_order_item_id' => $data['purchase_order_item_id'] ?? null,
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

            $this->syncResponsibleEmployees($item, $data['responsible_employees'] ?? [], $branchManagerId, (float) ($data['my_quantity_accountable'] ?? 0));

            return $item->load('responsibleEmployees.cashier.branch', 'responsibleEmployees.branchManager');
        });
    }

    /**
     * Update a report item.
     *
     * @param array{problem_type?: string, cause_of_damage?: string|null, quantity?: float, reason?: string, justification_text?: string|null, photo_path?: string|null, my_quantity_accountable?: float, responsible_employees?: array<int, array{cashier_id: string, quantity_accountable: float}>} $data
     */
    public function updateItem(string $reportId, string $itemId, string $branchId, array $data, string $branchManagerId): WasteDamageReportItem
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId);
        if (!$report || !$report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => [self::REPORT_NOT_EDITABLE_MESSAGE]]);
        }

        $item = $this->itemRepository->findByIdAndReport($itemId, $reportId);
        if (!$item) {
            throw ValidationException::withMessages(['item' => ['Report item not found.']]);
        }

        $item->load('responsibleEmployees');

        $existingMyQty = (float) $item->responsibleEmployees->whereNotNull('branch_manager_id')->first()?->quantity_accountable ?? 0;
        $existingCashiers = $item->responsibleEmployees->whereNotNull('cashier_id')->map(fn ($e) => [
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

        return DB::transaction(function () use ($item, $payload, $quantity, $totalValue, $branchManagerId) {
            $this->itemRepository->update($item, [
                'problem_type' => $payload['problem_type'],
                'cause_of_damage' => $payload['cause_of_damage'] ?? null,
                'quantity' => $quantity,
                'reason' => $payload['reason'],
                'total_value' => $totalValue,
                'justification_text' => $payload['justification_text'] ?? null,
                'photo_path' => $payload['photo_path'] ?? $item->photo_path,
            ]);

            $this->syncResponsibleEmployees($item, $payload['responsible_employees'] ?? [], $branchManagerId, (float) ($payload['my_quantity_accountable'] ?? 0));

            return $item->fresh(['responsibleEmployees.cashier.branch', 'responsibleEmployees.branchManager']);
        });
    }

    /**
     * Remove a report item.
     */
    public function deleteItem(string $reportId, string $itemId, string $branchId): void
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId);
        if (!$report || !$report->status->isEditable()) {
            throw ValidationException::withMessages(['report' => [self::REPORT_NOT_EDITABLE_MESSAGE]]);
        }

        $item = $this->itemRepository->findByIdAndReport($itemId, $reportId);
        if (!$item) {
            throw ValidationException::withMessages(['item' => ['Report item not found.']]);
        }

        $item->responsibleEmployees()->delete();
        $this->itemRepository->delete($item);
    }

    /**
     * Submit the report (validate all items then set status to submitted).
     */
    public function submitReport(string $reportId, string $branchId): WasteDamageReport
    {
        $report = $this->reportRepository->findByBranch($reportId, $branchId, ['items']);
        if (!$report) {
            throw ValidationException::withMessages(['report' => ['Report not found.']]);
        }

        if (!$report->status->isEditable()) {
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

        $this->reportRepository->update($report, [
            'status' => WasteDamageReportStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);

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
     * @param array{problem_type: string, cause_of_damage?: string|null, quantity: float, total_value: float, photo_path?: string|null, responsible_employees?: array} $data
     */
    private function validateItemData(array $data, float $quantity, float $totalValue): void
    {
        $problemType = $data['problem_type'] instanceof ProblemType
            ? $data['problem_type']
            : ProblemType::tryFrom($data['problem_type']);

        if (!$problemType) {
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
     * @param array<int, array{cashier_id: string, quantity_accountable: float}> $employees
     */
    private function syncResponsibleEmployees(WasteDamageReportItem $item, array $employees, ?string $branchManagerId = null, float $myQuantityAccountable = 0): void
    {
        $item->responsibleEmployees()->delete();

        if ($branchManagerId && $myQuantityAccountable > 0) {
            WasteDamageReportItemEmployee::create([
                'waste_damage_report_item_id' => $item->id,
                'branch_manager_id' => $branchManagerId,
                'cashier_id' => null,
                'quantity_accountable' => $myQuantityAccountable,
            ]);
        }

        foreach ($employees as $row) {
            WasteDamageReportItemEmployee::create([
                'waste_damage_report_item_id' => $item->id,
                'branch_manager_id' => null,
                'cashier_id' => $row['cashier_id'],
                'quantity_accountable' => (float) $row['quantity_accountable'],
            ]);
        }
    }
}
