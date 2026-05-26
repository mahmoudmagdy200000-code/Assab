<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Inventory\Enums\DailyInventoryTimelineEventType;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Enums\WasteDamageReportTimelineEventType;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\InventorySessionTimeline;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Models\WasteDamageReportTimeline;

class BrandOwnerInventoryService
{
    public function listDailyInventoryRequests(int $perPage): LengthAwarePaginator
    {
        return InventorySession::query()
            ->with(['branch:id,name'])
            ->whereIn('status', $this->dailyVisibleStatuses())
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function listWasteDamageRequests(int $perPage): LengthAwarePaginator
    {
        return WasteDamageReport::query()
            ->with(['branch:id,name', 'items:id,waste_damage_report_id,problem_type'])
            ->withCount('items')
            ->whereIn('status', $this->wasteVisibleStatuses())
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getDailyInventoryDetails(string $requestId): InventorySession
    {
        return InventorySession::with([
            'branch',
            'createdBy',
            'items.item',
            'discrepancies',
            'timelines' => fn ($q) => $q->orderBy('occurred_at', 'asc'),
        ])->findOrFail($requestId);
    }

    public function getWasteDamageDetails(string $requestId): WasteDamageReport
    {
        return WasteDamageReport::with([
            'branch',
            'createdBy',
            'assignedTo',
            'items.item',
            'timelines',
        ])->findOrFail($requestId);
    }

    public function approveDailyInventory(BrandOwner $actor, string $requestId): InventorySession
    {
        $session = InventorySession::findOrFail($requestId);
        $this->guardDailyActionable($session);

        $oldStatus = $session->status->value;
        $session->update([
            'status' => InventorySessionStatus::COMPLETED,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);

        InventorySessionTimeline::log(
            $session,
            DailyInventoryTimelineEventType::APPROVED,
            'Approved by brand owner',
            null,
            $oldStatus,
            InventorySessionStatus::COMPLETED->value,
        );

        return $session->fresh();
    }

    public function rejectDailyInventory(BrandOwner $actor, string $requestId, ?string $reason): InventorySession
    {
        $session = InventorySession::findOrFail($requestId);
        $this->guardDailyActionable($session);

        $oldStatus = $session->status->value;
        $session->update([
            'status' => InventorySessionStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $actor->id,
            'rejection_comment' => $reason,
        ]);

        InventorySessionTimeline::log(
            $session,
            DailyInventoryTimelineEventType::REJECTED,
            'Rejected by brand owner',
            $reason,
            $oldStatus,
            InventorySessionStatus::REJECTED->value,
        );

        return $session->fresh();
    }

    public function approveWasteDamage(BrandOwner $actor, string $requestId): WasteDamageReport
    {
        $report = WasteDamageReport::findOrFail($requestId);
        $this->guardWasteActionable($report);

        $oldStatus = $report->status->value;
        $report->update([
            'status' => WasteDamageReportStatus::COMPLETED,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);

        WasteDamageReportTimeline::log(
            $report,
            WasteDamageReportTimelineEventType::APPROVED,
            'Approved by brand owner',
            null,
            $oldStatus,
            WasteDamageReportStatus::COMPLETED->value,
        );

        return $report->fresh();
    }

    public function rejectWasteDamage(BrandOwner $actor, string $requestId, ?string $reason): WasteDamageReport
    {
        $report = WasteDamageReport::findOrFail($requestId);
        $this->guardWasteActionable($report);

        $oldStatus = $report->status->value;
        $report->update([
            'status' => WasteDamageReportStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $actor->id,
            'rejection_comment' => $reason,
        ]);

        WasteDamageReportTimeline::log(
            $report,
            WasteDamageReportTimelineEventType::REJECTED,
            'Rejected by brand owner',
            $reason,
            $oldStatus,
            WasteDamageReportStatus::REJECTED->value,
        );

        return $report->fresh();
    }

    /**
     * @return array<int, string>
     */
    private function dailyVisibleStatuses(): array
    {
        return [
            InventorySessionStatus::PENDING->value,
            InventorySessionStatus::PENDING_YOUR_ACTION->value,
            InventorySessionStatus::PENDING_YOUR_CONFIRMATION->value,
            InventorySessionStatus::APPROVED->value,
            InventorySessionStatus::REJECTED->value,
            InventorySessionStatus::COMPLETED->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function wasteVisibleStatuses(): array
    {
        return [
            WasteDamageReportStatus::PENDING->value,
            WasteDamageReportStatus::APPROVED->value,
            WasteDamageReportStatus::REJECTED->value,
            WasteDamageReportStatus::COMPLETED->value,
        ];
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
        $actionable = $report->status === WasteDamageReportStatus::PENDING;

        if (! $actionable) {
            throw ValidationException::withMessages([
                'status' => 'Request is not pending and cannot be acted on.',
            ]);
        }
    }
}
