<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReportStatus;

class BmWasteDamageListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof WasteDamageReportStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => $this->id,
            'report_type' => $this->resolveReportType(),
            'status' => $this->mapStatus($status),
            'submission_date' => $this->submitted_at?->toIso8601String()
                ?? $this->created_at?->toIso8601String(),
            'items_count' => (int) ($this->items_count
                ?? ($this->relationLoaded('items') ? $this->items->count() : 0)),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
        ];
    }

    private function resolveReportType(): string
    {
        if (!$this->relationLoaded('items') || $this->items->isEmpty()) {
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

    private function mapStatus(string $value): string
    {
        return match ($value) {
            'pending_your_confirmation', 'pending' => 'pending',
            'approved', 'rejected', 'completed' => 'completed',
            default => $value,
        };
    }
}
