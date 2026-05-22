<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Purchase\Models\SavedPriceComparison
 */
class SavedComparisonResource extends JsonResource
{
    /**
     * Whether to embed the full stored comparison snapshot.
     * Kept off for list responses to avoid heavy payloads.
     */
    protected bool $includeSnapshot = false;

    /**
     * Embed the full comparison snapshot in the output.
     */
    public function withSnapshot(): self
    {
        $this->includeSnapshot = true;

        return $this;
    }

    public function toArray($request): array
    {
        $data = [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->includeSnapshot) {
            // The snapshot is the raw comparePrices() array; reuse the same
            // transformer the live compare-prices endpoint uses.
            $data['comparison'] = new PriceComparisonResource($this->snapshot ?? []);
        }

        return $data;
    }
}
