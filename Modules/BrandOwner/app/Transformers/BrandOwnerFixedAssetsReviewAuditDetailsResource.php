<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\ReviewAuditRequest;
use Modules\FixedAssets\Transformers\TimelineItemResource;

class BrandOwnerFixedAssetsReviewAuditDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ReviewAuditRequest $r */
        $r = $this->resource;

        $conditions = collect((array) ($r->conditions ?? []))->map(fn ($c) => [
            'condition' => (string) ($c['condition'] ?? 'excellent'),
            'count' => (int) ($c['count'] ?? 0),
        ])->values()->all();

        return [
            'id' => (string) $r->id,
            'status' => $r->status?->value ?? 'pending',
            'branch_name' => (string) ($r->branch?->name ?? ''),
            'approved_on' => $r->bo_decided_at?->toIso8601String(),
            'cancellation' => $r->cancellation,
            'summary' => [
                'manager_name' => (string) ($r->manager_name_snapshot ?? ''),
                'zone_name' => (string) ($r->zone_name_snapshot ?? ''),
                'value' => $r->value !== null ? (string) $r->value : '-',
                'total_assets' => (string) ($r->total_assets ?? '0'),
                'audit_date' => $r->audit_date?->toDateString() ?? '',
            ],
            'asset' => [
                'image_url' => (string) ($r->image_url ?? ''),
                'name' => (string) ($r->asset_name_snapshot ?? ''),
                'code' => (string) ($r->asset_code_snapshot ?? ''),
                'type' => (string) ($r->asset_type_snapshot ?? ''),
                'zone' => (string) ($r->zone_name_snapshot ?? ''),
                'conditions' => $conditions,
                'value' => $r->value !== null ? (string) $r->value : '-',
                'additional_notes' => (string) ($r->additional_notes ?? ''),
            ],
            'timelines' => TimelineItemResource::collection($r->timelines ?? collect())->resolve(),
        ];
    }
}
