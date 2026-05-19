<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\InventorySessionStatus;

class BmDailyInventoryDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $branch = $this->relationLoaded('branch') ? $this->branch : null;
        $reporter = $this->relationLoaded('createdBy') ? $this->createdBy : null;
        $items = $this->relationLoaded('items') ? $this->items : collect();
        $discrepancies = $this->relationLoaded('discrepancies') ? $this->discrepancies : collect();

        $totalItems = $items->count();
        $discCount = $discrepancies->count();
        $matching = max(0, $totalItems - $discCount);
        $totalDiscrepancyValue = (float) $discrepancies->sum('difference_value_sar');
        $discTypes = $discrepancies->pluck('discrepancy_type')->filter()->unique()->values();

        $status = $this->status instanceof InventorySessionStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => $this->id,
            'branch_name' => $branch?->name,
            'branch_open_hours' => $this->formatBranchHours($branch),
            'inventory_date' => ($this->inventory_date ?? $this->submitted_at ?? $this->created_at)?->toIso8601String(),
            'branch_image_url' => $this->resolveImage($branch?->image),
            'reporter_name' => $reporter?->name,
            'reporter_image_url' => $this->resolveImage($reporter?->image ?? null),
            'status' => $this->mapStatus($status),
            'summary' => [
                'products_inventoried' => $totalItems > 0
                    ? sprintf('%d/%d (100%%)', $totalItems, $totalItems)
                    : '0/0 (0%)',
                'products_with_discrepancies' => (string) $discCount,
                'matching_products' => (string) $matching,
                'total_discrepancy_value' => $this->formatSignedNumber($totalDiscrepancyValue),
                'discrepancy_type' => $discTypes->count() > 0
                    ? sprintf('%d products', $discCount)
                    : '0 products',
            ],
            'items' => $items->map(function ($item) use ($discrepancies) {
                $disc = $discrepancies->firstWhere('inventory_item_id', $item->id);
                $productName = $item->relationLoaded('item') && $item->item
                    ? $item->item->name
                    : $item->item_name;

                return [
                    'product_name' => $productName,
                    'expected_balance' => $disc
                        ? (string) (float) $disc->theoretically_expected
                        : (string) (float) ($item->sales_quantity ?? 0),
                    'actual_balance' => $disc
                        ? (string) (float) $disc->actual
                        : (string) (float) ($item->quantity_inventory ?? 0),
                    'difference' => $disc
                        ? $this->formatSignedNumber((float) $disc->difference_quantity)
                        : '0',
                    'justification_note' => $item->notes,
                    'explanatory_photo_url' => null,
                    'explanatory_photo_name' => null,
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

    private function formatSignedNumber(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }
        $sign = $value > 0 ? '+' : '';
        return $sign . rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function mapStatus(string $value): string
    {
        return match ($value) {
            'pending_your_confirmation', 'pending_your_action', 'pending' => 'pending',
            'rejected' => 'rejected',
            'approved', 'completed' => 'completed',
            default => $value,
        };
    }
}
