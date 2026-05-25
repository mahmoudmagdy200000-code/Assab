<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\MajorDiscrepancyRequest;

class BrandOwnerFixedAssetsHandoverReportListResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var MajorDiscrepancyRequest $r */
        $r = $this->resource;

        $group = $r->relationLoaded('groupedRequests')
            ? $r->getRelation('groupedRequests')
            : collect([$r]);

        $items = $group->map(fn (MajorDiscrepancyRequest $m) => [
            'id' => (string) $m->id,
            'asset_name' => (string) ($m->handoverItem?->asset_name_snapshot ?? ''),
            'status' => $m->status?->value ?? 'pending',
        ])->values()->all();

        return [
            'id' => (string) $r->id,
            'handover_id' => (string) ($r->handover_id ?? ''),
            'branch_name' => (string) ($r->branch?->name ?? ''),
            'asset_name' => (string) ($r->handoverItem?->asset_name_snapshot ?? ''),
            'status' => $r->status?->value ?? 'pending',
            'created_at' => $r->created_at?->toIso8601String() ?? '',
            'items_count' => count($items),
            'items' => $items,
        ];
    }
}
