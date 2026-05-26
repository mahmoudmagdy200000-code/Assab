<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\ProblemType;

/**
 * List view only: id, report_type, display_title, status, status_label,
 * submission_date, items_count, is_editable (as shown in list UI).
 */
class WasteDamageReportListResource extends JsonResource
{
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
            'report_type' => $this->resolveReportType(),
            'display_title' => $this->resolveDisplayTitle(),
            'status' => $this->status->value,
            'status_label' => $this->status->listLabel(),
            'submission_date' => $dateForSubmission->format('F j, Y'),
            'items_count' => (int) (isset($this->items_count) ? $this->items_count : ($this->relationLoaded('items') ? $this->items->count() : 0)),
            'is_editable' => $this->status->isEditable() && $this->submitted_at === null,
            'details' => (new WasteDamageReportResource($this->resource))->toArray($request),
        ];
    }
}
