<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Saved price-comparison list row for the branch-manager screen.
 *
 * @mixin \Modules\Purchase\Models\SavedPriceComparison
 */
class PriceComparisonListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $snapshot = is_array($this->snapshot) ? $this->snapshot : [];

        return [
            'id' => $this->id,
            'itemId' => $this->item_id,
            'itemName' => $this->item_name,
            'itemCode' => $snapshot['item_code'] ?? null,
            'savedDate' => $this->created_at?->toDateString(),
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
        ];
    }
}
