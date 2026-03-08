<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified timeline resource for Expense, Purchase, Custody, Supplier.
 * Returns a consistent shape: id, event_type, name, image (null when no image), occurred_at.
 * Preserves all actions/statuses per module as event_type.
 */
class UnifiedTimelineResource extends JsonResource
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function toArray(Request $request): array
    {
        if ($this->resource instanceof \Modules\Purchase\Models\OrderTimeline) {
            return $this->formatPurchaseOrderTimeline();
        }

        if ($this->resource instanceof \Modules\Expense\Models\ExpenseTimeline) {
            return $this->formatExpenseTimeline();
        }

        if ($this->resource instanceof \Modules\Custody\Models\CustodyRequestTimeline) {
            return $this->formatCustodyTimeline();
        }

        if ($this->resource instanceof \Modules\Inventory\Models\InventorySessionTimeline) {
            return $this->formatInventorySessionTimeline();
        }

        if ($this->resource instanceof \Modules\Inventory\Models\MonthlyInventoryTimeline) {
            return $this->formatMonthlyInventoryTimeline();
        }

        if ($this->resource instanceof \Modules\Inventory\Models\WasteDamageReportTimeline) {
            return $this->formatWasteDamageReportTimeline();
        }

        return [
            'id' => $this->id,
            'event_type' => 'unknown',
            'name' => '',
            'image' => null,
            'occurred_at' => null,
        ];
    }

    private function formatPurchaseOrderTimeline(): array
    {
        $timeline = $this->resource;

        return [
            'id' => $timeline->id,
            'event_type' => $timeline->event_type?->value ?? 'unknown',
            'name' => $timeline->actor_name ?? '',
            'image' => $timeline->actor_image_url ?? null,
            'occurred_at' => $timeline->occurred_at?->format(self::DATETIME_FORMAT),
        ];
    }

    private function formatExpenseTimeline(): array
    {
        $timeline = $this->resource;
        $eventType = 'expense_' . $timeline->action;

        return [
            'id' => $timeline->id,
            'event_type' => $eventType,
            'name' => $this->expensePerformerName($timeline),
            'image' => $this->expensePerformerImage($timeline),
            'occurred_at' => $timeline->created_at?->format(self::DATETIME_FORMAT),
        ];
    }

    private function formatCustodyTimeline(): array
    {
        $timeline = $this->resource;
        $stageSnake = str_replace(' ', '_', strtolower($timeline->stage));
        $eventType = 'custody_' . $stageSnake;

        return [
            'id' => $timeline->id,
            'event_type' => $eventType,
            'name' => $timeline->actor_name ?? '',
            'image' => $timeline->actor_profile_image ? $this->actorImageUrl($timeline->actor_profile_image) : null,
            'occurred_at' => $timeline->action_date?->format(self::DATETIME_FORMAT),
        ];
    }

    /**
     * Unified inventory timeline event types (all inventory types: daily session, monthly).
     * API returns only: inventory_created, inventory_reassigned, inventory_viewed, inventory_rejected, inventory_approved.
     */
    /** Waste & Damage report event type → unified inventory-style event_type. */
    private const WASTE_DAMAGE_EVENT_MAP = [
        'created' => 'inventory_created',
        'submitted' => 'inventory_approved',
    ];

    private const INVENTORY_EVENT_MAP = [
        // Daily (session)
        'submitted' => 'inventory_created',
        'viewed_by_account_manager' => 'inventory_viewed',
        'rejected' => 'inventory_rejected',
        'approved' => 'inventory_approved',
        'resubmitted' => 'inventory_reassigned',
        'discrepancy_report_shared' => 'inventory_viewed',
        'discrepancy_reviewed' => 'inventory_viewed',
        // Monthly
        'created' => 'inventory_created',
        'started' => 'inventory_created',
        'saved' => 'inventory_reassigned',
        'reviewed' => 'inventory_viewed',
        'returned_to_draft' => 'inventory_reassigned',
        'feedback_added' => 'inventory_viewed',
        'product_count_updated' => 'inventory_reassigned',
    ];

    private function formatInventorySessionTimeline(): array
    {
        $timeline = $this->resource;
        $eventType = $this->mapInventoryEventType($timeline->event_type?->value);

        return [
            'id' => $timeline->id,
            'event_type' => $eventType,
            'name' => $timeline->actor_name ?? '',
            'image' => $timeline->actor_image ? $this->actorImageUrl($timeline->actor_image) : null,
            'occurred_at' => $timeline->occurred_at?->format(self::DATETIME_FORMAT),
        ];
    }

    private function formatMonthlyInventoryTimeline(): array
    {
        $timeline = $this->resource;
        $eventType = $this->mapInventoryEventType($timeline->event_type?->value);

        return [
            'id' => $timeline->id,
            'event_type' => $eventType,
            'name' => $timeline->actor_name ?? '',
            'image' => $timeline->actor_image_url ?? null,
            'occurred_at' => $timeline->occurred_at?->format(self::DATETIME_FORMAT),
        ];
    }

    private function formatWasteDamageReportTimeline(): array
    {
        $timeline = $this->resource;
        $eventType = self::WASTE_DAMAGE_EVENT_MAP[$timeline->event_type?->value ?? ''] ?? 'inventory_created';

        return [
            'id' => $timeline->id,
            'event_type' => $eventType,
            'name' => $timeline->actor_name ?? '',
            'image' => $timeline->actor_image ? $this->actorImageUrl($timeline->actor_image) : null,
            'occurred_at' => $timeline->occurred_at?->format(self::DATETIME_FORMAT),
        ];
    }

    private function mapInventoryEventType(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'inventory_created';
        }

        return self::INVENTORY_EVENT_MAP[$value] ?? 'inventory_created';
    }

    private function actorImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        return str_starts_with($path, 'http') ? $path : asset('storage/' . $path);
    }

    private function expensePerformerName(\Modules\Expense\Models\ExpenseTimeline $timeline): string
    {
        if ($timeline->performed_by_type === 'branch_manager') {
            $manager = \Modules\BranchManagers\Models\BranchManager::find($timeline->performed_by);
            return $manager?->name ?? '';
        }
        if ($timeline->performed_by_type === 'brand_owner') {
            return 'Brand Owner';
        }
        return 'System';
    }

    private function expensePerformerImage(\Modules\Expense\Models\ExpenseTimeline $timeline): ?string
    {
        if ($timeline->performed_by_type === 'branch_manager') {
            $manager = \Modules\BranchManagers\Models\BranchManager::find($timeline->performed_by);
            $image = $manager?->image ?? null;
            return $image ? $this->actorImageUrl($image) : null;
        }
        return null;
    }
}
