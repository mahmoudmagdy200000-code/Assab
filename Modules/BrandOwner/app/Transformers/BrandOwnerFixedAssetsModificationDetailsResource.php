<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\ModificationRequest;
use Modules\FixedAssets\Transformers\TimelineItemResource;

class BrandOwnerFixedAssetsModificationDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ModificationRequest $m */
        $m = $this->resource;
        $asset = $m->asset;

        $valueStr = $asset?->value !== null ? (string) $asset->value : '-';

        return [
            'id' => (string) $m->id,
            'status' => $m->status?->value ?? 'pending',
            'branch_name' => (string) ($m->branch?->name ?? ''),
            'approved_on' => $m->approved_at?->toIso8601String(),
            'cancellation' => $m->cancellation,
            'financial_impact_analysis' => [
                'financial_impact' => '-',
                'operational_impact' => '-',
                'critical_assets' => '-',
                'brand_impact_level' => '-',
            ],
            'asset' => [
                'image_url' => $asset?->image ? asset('storage/'.$asset->image) : '',
                'name' => (string) ($asset?->name ?? ''),
                'code' => (string) ($asset?->code ?? ''),
                'branch_name' => (string) ($m->branch?->name ?? ''),
                'manager_name' => (string) ($m->requestedBy?->name ?? ''),
                'current_status' => $asset?->status?->value ?? '-',
                'new_status' => $m->new_status?->value ?? '-',
                'reason' => (string) ($m->reason ?? '-'),
                'value' => $valueStr,
                'critical_asset' => false,
                'brand_impact' => '-',
                'documentation_photo_url' => $m->attachment?->path
                    ? asset('storage/'.$m->attachment->path)
                    : '',
                'additional_notes' => (string) ($m->approval_request_owner_note ?? '-'),
            ],
            'timelines' => TimelineItemResource::collection($m->timelines ?? collect())->resolve(),
        ];
    }
}
