<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\ModificationRequest;

class ModificationDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ModificationRequest $r */
        $r = $this->resource;

        $attachment = $r->attachment;

        return [
            'status' => $r->status?->value ?? '',
            'asset_details' => $r->asset
                ? (new AssetSearchItemResource($r->asset))->toArray($request)
                : null,
            'modification_details' => [
                'new_status' => $r->new_status?->value ?? '',
                'reason' => (string) $r->reason,
                'attachment' => $attachment
                    ? (new AttachmentResource($attachment))->toArray($request)
                    : null,
            ],
            'required_actions' => [
                'done_actions' => $r->doneActions->map(fn ($a) => $a->action?->value ?? '')->values()->all(),
                'next_action' => $r->next_action?->value ?? '',
                'approval_request_owner_note' => (string) $r->approval_request_owner_note,
            ],
            'timelines' => TimelineItemResource::collection($r->timelines)->resolve($request),
        ];
    }
}
