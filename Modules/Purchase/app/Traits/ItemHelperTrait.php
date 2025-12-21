<?php

namespace Modules\Purchase\Traits;

trait ItemHelperTrait
{
    /**
     * Get item logo URL from array or string
     * 
     * @param array|string|null $logo
     * @return string|null
     */
    protected function getItemLogoUrl(array|string|null $logo): ?string
    {
        if (empty($logo)) {
            return null;
        }

        $logoPath = is_array($logo) ? ($logo[0] ?? null) : $logo;
        
        if (!$logoPath) {
            return null;
        }

        return str_starts_with($logoPath, 'http')
            ? $logoPath
            : asset('storage/' . $logoPath);
    }

    /**
     * Resolve Item ID from BranchItem ID or Item ID
     * 
     * @param string|null $requestedItemId
     * @return string|null
     */
    protected function resolveItemId(?string $requestedItemId): ?string
    {
        if (!$requestedItemId) {
            return null;
        }

        // Try to find Item through BranchItem first
        $branchItem = \Modules\Purchase\Models\BranchItem::with('item')->find($requestedItemId);
        if ($branchItem && $branchItem->item) {
            return $branchItem->item->id;
        }

        // Try direct Item lookup
        $item = \Modules\Purchase\Models\Item::find($requestedItemId);
        if ($item) {
            return $item->id;
        }

        return null;
    }

    /**
     * Calculate average from collection using chunking for large datasets
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param int $chunkSize
     * @return float
     */
    protected function calculateAverageWithChunking($query, string $column, int $chunkSize = 500): float
    {
        $total = 0;
        $count = 0;

        $query->chunk($chunkSize, function ($items) use ($column, &$total, &$count) {
            foreach ($items as $item) {
                $value = $item->{$column} ?? 0;
                if (is_numeric($value)) {
                    $total += (float) $value;
                    $count++;
                }
            }
        });

        return $count > 0 ? round($total / $count, 2) : 0;
    }
}

