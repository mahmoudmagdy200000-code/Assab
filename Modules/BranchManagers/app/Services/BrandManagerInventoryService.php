<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Enums\DailyInventoryTimelineEventType;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Enums\WasteDamageReportTimelineEventType;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\InventorySessionTimeline;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Models\WasteDamageReportTimeline;

class BrandManagerInventoryService
{
    public function listDailyInventoryRequests(
        BranchManager $manager,
        int $perPage,
        ?string $status,
        ?string $branchId,
    ): LengthAwarePaginator {
        $query = InventorySession::query()
            ->with(['branch:id,name'])
            ->where('branch_id', $branchId ?? $manager->branch_id)
            ->whereIn('status', $this->mapDailyStatuses($status))
            ->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    public function listWasteDamageRequests(
        BranchManager $manager,
        int $perPage,
        ?string $status,
        ?string $branchId,
    ): LengthAwarePaginator {
        $query = WasteDamageReport::query()
            ->with(['branch:id,name', 'items:id,waste_damage_report_id,problem_type'])
            ->withCount('items')
            ->where('branch_id', $branchId ?? $manager->branch_id)
            ->whereIn('status', $this->mapWasteStatuses($status))
            ->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    public function getDailyInventoryDetails(BranchManager $manager, string $requestId): InventorySession
    {
        $session = InventorySession::with([
            'branch',
            'createdBy',
            'items.item',
            'discrepancies',
            'timelines' => fn ($q) => $q->orderBy('occurred_at', 'asc'),
        ])->where('branch_id', $manager->branch_id)->findOrFail($requestId);

        return $session;
    }

    public function getWasteDamageDetails(BranchManager $manager, string $requestId): WasteDamageReport
    {
        $report = WasteDamageReport::with([
            'branch',
            'createdBy',
            'assignedTo',
            'items.item',
            'timelines',
        ])->where('branch_id', $manager->branch_id)->findOrFail($requestId);

        return $report;
    }

    public function approveDailyInventory(BranchManager $manager, string $requestId): InventorySession
    {
        $session = InventorySession::where('branch_id', $manager->branch_id)->findOrFail($requestId);

        $this->guardDailyActionable($session);

        $oldStatus = $session->status->value;
        $session->update([
            'status' => InventorySessionStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => $manager->id,
        ]);

        InventorySessionTimeline::log(
            $session,
            DailyInventoryTimelineEventType::APPROVED,
            'Approved by branch manager',
            null,
            $oldStatus,
            InventorySessionStatus::APPROVED->value,
        );

        return $session->fresh();
    }

    public function rejectDailyInventory(BranchManager $manager, string $requestId, ?string $reason): InventorySession
    {
        $session = InventorySession::where('branch_id', $manager->branch_id)->findOrFail($requestId);

        $this->guardDailyActionable($session);

        $oldStatus = $session->status->value;
        $session->update([
            'status' => InventorySessionStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $manager->id,
            'rejection_comment' => $reason,
        ]);

        InventorySessionTimeline::log(
            $session,
            DailyInventoryTimelineEventType::REJECTED,
            'Rejected by branch manager',
            $reason,
            $oldStatus,
            InventorySessionStatus::REJECTED->value,
        );

        return $session->fresh();
    }

    public function approveWasteDamage(BranchManager $manager, string $requestId): WasteDamageReport
    {
        $report = WasteDamageReport::where('branch_id', $manager->branch_id)->findOrFail($requestId);

        $this->guardWasteActionable($report);

        $oldStatus = $report->status->value;
        $report->update([
            'status' => WasteDamageReportStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => $manager->id,
        ]);

        WasteDamageReportTimeline::log(
            $report,
            WasteDamageReportTimelineEventType::APPROVED,
            'Approved by branch manager',
            null,
            $oldStatus,
            WasteDamageReportStatus::APPROVED->value,
        );

        return $report->fresh();
    }

    public function rejectWasteDamage(BranchManager $manager, string $requestId, ?string $reason): WasteDamageReport
    {
        $report = WasteDamageReport::where('branch_id', $manager->branch_id)->findOrFail($requestId);

        $this->guardWasteActionable($report);

        $oldStatus = $report->status->value;
        $report->update([
            'status' => WasteDamageReportStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $manager->id,
            'rejection_comment' => $reason,
        ]);

        WasteDamageReportTimeline::log(
            $report,
            WasteDamageReportTimelineEventType::REJECTED,
            'Rejected by branch manager',
            $reason,
            $oldStatus,
            WasteDamageReportStatus::REJECTED->value,
        );

        return $report->fresh();
    }

    /**
     * Map external filter (pending|completed) to internal statuses.
     *
     * @return array<int, string>
     */
    private function mapDailyStatuses(?string $status): array
    {
        return match ($status) {
            'pending' => [
                InventorySessionStatus::PENDING->value,
                InventorySessionStatus::PENDING_YOUR_ACTION->value,
                InventorySessionStatus::PENDING_YOUR_CONFIRMATION->value,
            ],
            'completed' => [
                InventorySessionStatus::APPROVED->value,
                InventorySessionStatus::REJECTED->value,
                InventorySessionStatus::COMPLETED->value,
            ],
            default => [
                InventorySessionStatus::PENDING->value,
                InventorySessionStatus::PENDING_YOUR_ACTION->value,
                InventorySessionStatus::PENDING_YOUR_CONFIRMATION->value,
                InventorySessionStatus::APPROVED->value,
                InventorySessionStatus::REJECTED->value,
                InventorySessionStatus::COMPLETED->value,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    private function mapWasteStatuses(?string $status): array
    {
        return match ($status) {
            'pending' => [
                WasteDamageReportStatus::PENDING->value,
                WasteDamageReportStatus::PENDING_YOUR_CONFIRMATION->value,
            ],
            'completed' => [
                WasteDamageReportStatus::APPROVED->value,
                WasteDamageReportStatus::REJECTED->value,
                WasteDamageReportStatus::COMPLETED->value,
            ],
            default => [
                WasteDamageReportStatus::PENDING->value,
                WasteDamageReportStatus::PENDING_YOUR_CONFIRMATION->value,
                WasteDamageReportStatus::APPROVED->value,
                WasteDamageReportStatus::REJECTED->value,
                WasteDamageReportStatus::COMPLETED->value,
            ],
        };
    }

    private function guardDailyActionable(InventorySession $session): void
    {
        $actionable = in_array($session->status, [
            InventorySessionStatus::PENDING,
            InventorySessionStatus::PENDING_YOUR_ACTION,
            InventorySessionStatus::PENDING_YOUR_CONFIRMATION,
        ], true);

        if (! $actionable) {
            throw ValidationException::withMessages([
                'status' => 'Request is not pending and cannot be acted on.',
            ]);
        }
    }

    private function guardWasteActionable(WasteDamageReport $report): void
    {
        $actionable = in_array($report->status, [
            WasteDamageReportStatus::PENDING,
            WasteDamageReportStatus::PENDING_YOUR_CONFIRMATION,
        ], true);

        if (! $actionable) {
            throw ValidationException::withMessages([
                'status' => 'Request is not pending and cannot be acted on.',
            ]);
        }
    }
}
