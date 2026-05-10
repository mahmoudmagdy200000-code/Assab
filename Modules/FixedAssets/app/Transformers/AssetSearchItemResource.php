<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\FixedAsset;

class AssetSearchItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var FixedAsset $asset */
        $asset = $this->resource;

        $assignedToName = '';
        if ($asset->relationLoaded('assignedTo') && $asset->assignedTo) {
            $assignedToName = $asset->assignedTo->name ?? '';
        }

        $age = '';
        if ($asset->acquired_at) {
            $age = $asset->acquired_at->diffForHumans(now(), [
                'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 1,
            ]);
        }

        $custody = '';
        if ($asset->custody_started_at) {
            $custody = $asset->custody_started_at->diffForHumans(now(), [
                'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 1,
            ]);
        }

        return [
            'id' => (string) $asset->id,
            'name' => (string) $asset->name,
            'code' => (string) $asset->code,
            'image' => $asset->image ? asset('storage/'.$asset->image) : '',
            'location' => [
                'id' => (string) ($asset->zone?->id ?? ''),
                'name' => (string) ($asset->zone?->name ?? ''),
            ],
            'status' => $asset->status?->value ?? '',
            'assigned_to' => $assignedToName,
            'age' => $age,
            'value' => (string) (float) $asset->value,
            'custody' => $custody,
        ];
    }
}
