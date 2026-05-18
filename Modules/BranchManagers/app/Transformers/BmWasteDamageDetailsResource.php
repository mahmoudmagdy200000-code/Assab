<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReportStatus;

class BmWasteDamageDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $branch = $this->relationLoaded('branch') ? $this->branch : null;
        $reporter = $this->relationLoaded('createdBy') ? $this->createdBy : null;
        $assignedTo = $this->relationLoaded('assignedTo') ? $this->assignedTo : null;
        $items = $this->relationLoaded('items') ? $this->items : collect();

        $totalValue = (float) $items->sum('total_value');
        $types = $items->pluck('problem_type')->filter()->unique()->values();
        $isMixed = $types->count() > 1;

        $status = $this->status instanceof WasteDamageReportStatus
            ? $this->status->value
            : (string) $this->status;

        $submission = $this->submitted_at ?? $this->created_at;

        return [
            'id' => $this->id,
            'report_type' => $this->resolveReportType($items),
            'status' => $this->mapStatus($status),
            'branch_name' => $branch?->name,
            'branch_open_hours' => $this->formatBranchHours($branch),
            'submission_date' => $submission?->toIso8601String(),
            'branch_image_url' => $this->resolveImage($branch?->image),
            'reporter_name' => $reporter?->name,
            'reporter_image_url' => $this->resolveImage($reporter?->image ?? null),
            'summary' => [
                'recorded_by' => $assignedTo?->name ?? $reporter?->name,
                'assigned_by' => $reporter?->name,
                'recording_time' => $submission?->format('h:i A'),
                'number_of_items' => sprintf('%d products', $items->count()),
                'matching_products' => (string) $items->count(),
                'total_value' => $this->formatNumber($totalValue),
                'warning' => $isMixed ? 'Mixed waste and damage' : null,
            ],
            'items' => $items->map(function ($item) {
                $productName = $item->relationLoaded('item') && $item->item
                    ? $item->item->name
                    : null;
                $problem = $item->problem_type instanceof ProblemType
                    ? $item->problem_type->label()
                    : (string) $item->problem_type;
                $reason = is_object($item->reason) && method_exists($item->reason, 'value')
                    ? $item->reason->value
                    : (string) $item->reason;

                return [
                    'product_name' => $productName,
                    'problem_type' => $problem,
                    'quantity' => (string) (float) $item->quantity,
                    'reason' => $reason,
                    'total_value' => $this->formatNumber((float) $item->total_value),
                    'justification_note' => $item->justification_text,
                    'explanatory_photo_url' => $this->resolveImage($item->photo_path),
                    'image_url' => $item->relationLoaded('item') && $item->item
                        ? ($item->item->logo_url ?? null)
                        : null,
                ];
            })->values(),
            'timelines' => $this->relationLoaded('timelines')
                ? BmInventoryTimelineResource::collection($this->timelines)->resolve()
                : [],
        ];
    }

    private function resolveReportType($items): string
    {
        if ($items->isEmpty()) {
            return 'waste_and_damage';
        }
        $types = $items->pluck('problem_type')->unique()->filter()->values();
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

    private function formatBranchHours($branch): ?string
    {
        if (!$branch || !$branch->opening_hours || !$branch->closing_hours) {
            return null;
        }
        $open = $branch->opening_hours->format('g:i A');
        $close = $branch->closing_hours->format('g:i A');
        return "Mon - Sun / {$open} - {$close}";
    }

    private function resolveImage(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        return str_starts_with($path, 'http') ? $path : asset('storage/' . $path);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
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
