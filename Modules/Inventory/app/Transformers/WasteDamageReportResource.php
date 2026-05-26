<?php

namespace Modules\Inventory\Transformers;

use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\ProblemType;

class WasteDamageReportResource extends JsonResource
{
    /**
     * Report type for list UI: waste | damage | waste_and_damage.
     */
    private function resolveReportType(): string
    {
        if (! $this->relationLoaded('items') || $this->items->isEmpty()) {
            return 'waste_and_damage';
        }
        $types = $this->items->pluck('problem_type')->unique()->filter()->values();
        if ($types->isEmpty()) {
            return 'waste_and_damage';
        }
        $hasWaste = $types->contains(fn ($t) => $t === ProblemType::WASTE);
        $hasDamage = $types->contains(fn ($t) => $t === ProblemType::DAMAGE);
        if ($hasWaste && $hasDamage) {
            return 'waste_and_damage';
        }

        return $hasWaste ? 'waste' : 'damage';
    }

    /**
     * Display title for list card (matches UI).
     */
    private function resolveDisplayTitle(): string
    {
        return match ($this->resolveReportType()) {
            'waste' => 'Waste Registration',
            'damage' => 'Damage Registration',
            default => 'Waste & Damage Registration',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dateForSubmission = $this->submitted_at ?? $this->created_at;

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'location' => $this->branch->location ?? null,
            ]),
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name ?? null,
                'role' => 'Branch Manager',
            ]),
            'assigned_to_type' => $this->assigned_to_type ?? 'personal',
            'assigned_to_id' => $this->assigned_to_id,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name ?? null,
            ] : null),
            'status' => $this->status->value,
            'status_label' => $this->status->listLabel(),
            'isStaffInventored' => ($this->assigned_to_type ?? 'personal') === 'staff' && $this->submitted_at !== null,
            'isBranchManagerConfirm' => ($this->assigned_to_type ?? 'personal') === 'staff'
                && $this->submitted_at !== null
                && $this->status === \Modules\Inventory\Enums\WasteDamageReportStatus::COMPLETED,
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'submission_date' => $dateForSubmission->format('F j, Y'),
            'report_type' => $this->resolveReportType(),
            'display_title' => $this->resolveDisplayTitle(),
            'items_count' => (int) (isset($this->items_count) ? $this->items_count : ($this->relationLoaded('items') ? $this->items->count() : 0)),
            'is_editable' => $this->status->isEditable() && $this->submitted_at === null,
            'items' => WasteDamageReportItemResource::collection($this->whenLoaded('items')),
            'timelines' => $this->relationLoaded('timelines')
                ? UnifiedTimelineResource::collection($this->timelines)->resolve()
                : [],
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'report_submitted' => $this->when($this->submitted_at !== null, fn () => [
                'status' => $this->status->detailStatusLabel(),
                'message' => $this->buildSubmissionMessage(),
            ]),
            'assignment_information' => $this->when($this->relationLoaded('createdBy'), fn () => $this->buildAssignmentInformation()),
        ];
    }

    private function buildSubmissionMessage(): string
    {
        $name = $this->relationLoaded('createdBy') && $this->createdBy
            ? ($this->createdBy->name ?? 'Branch Manager')
            : 'Branch Manager';

        return "Your report has been successfully submitted by {$name} to management for review.";
    }

    /**
     * Assignment information block (detail view).
     *
     * @return array<string, string>
     */
    private function buildAssignmentInformation(): array
    {
        $creatorName = $this->createdBy->name ?? 'Unknown';
        $creatorRole = $this->created_by_type === 'cashier' ? 'Cashier' : 'Branch Manager';
        $isStaff = ($this->assigned_to_type ?? 'personal') === 'staff' && $this->assignedTo;
        $assignedByName = $isStaff ? ($this->assignedTo->name ?? $creatorName) : $creatorName;

        return [
            'assigned_by_name' => $assignedByName,
            'assigned_by_role' => $creatorRole,
            'responsibility' => 'Accurate Waste & Damage Registration',
            'timing' => 'Immediate Upon Discovery',
            'review' => 'Will Be Sent To Manager For Approval',
            'reminder' => 'You Are Registering In The Manager\'s Name',
        ];
    }
}
