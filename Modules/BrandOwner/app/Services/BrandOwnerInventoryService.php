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
        // Show all statuses normally, except hide PENDING staff submissions that are not
        // yet confirmed by the Branch Manager (they appear only after manager_confirmed_at is set).
        return InventorySession::query()
            ->with(['branch:id,name'])
            ->where(function ($q) {
                $q->where('status', '!=', InventorySessionStatus::PENDING->value)
                    ->orWhere('assigned_to_type', '!=', 'staff')
                    ->orWhereNotNull('manager_confirmed_at');
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function listWasteDamageRequests(int $perPage): LengthAwarePaginator
    {
        // Same rule as daily: hide PENDING staff submissions until the Branch Manager confirms.
        return WasteDamageReport::query()
            ->with(['branch:id,name', 'items:id,waste_damage_report_id,problem_type'])
            ->withCount('items')
            ->where(function ($q) {
                $q->where('status', '!=', WasteDamageReportStatus::PENDING->value)
                    ->orWhere('assigned_to_type', '!=', 'staff')
                    ->orWhereNotNull('manager_confirmed_at');
            })
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

    private function guardDailyActionable(InventorySession $session): void
    {
        $actionable = $session->status === InventorySessionStatus::PENDING
            && ($session->assigned_to_type !== 'staff' || $session->manager_confirmed_at !== null);

        if (! $actionable) {
            throw ValidationException::withMessages([
                'status' => 'Request is not pending and cannot be acted on.',
            ]);
        }
    }

    private function guardWasteActionable(WasteDamageReport $report): void
    {
        $actionable = $report->status === WasteDamageReportStatus::PENDING
            && ($report->assigned_to_type !== 'staff' || $report->manager_confirmed_at !== null);

        if (! $actionable) {
            throw ValidationException::withMessages([
                'status' => 'Request is not pending and cannot be acted on.',
            ]);
        }
    }
}
