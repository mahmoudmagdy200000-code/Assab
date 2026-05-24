<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\TransferDisposalItem;

class TransferDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;
        $asset = $i->asset;
        $req = $i->request;

        $viewerBranchId = (string) (auth()->user()?->branch_id ?? '');
        $type = $this->resolveType($req, $viewerBranchId);

        return [
            'data' => [
                'request_details' => [
                    'transfer_id' => (string) $i->id,
                    'from' => (string) ($req?->branch?->name ?? ''),
                    'to' => (string) ($req?->recipientBranch?->name ?? ''),
                    'request_date' => $req?->created_at?->toDateString() ?? '',
                    'arrival_time' => $req?->approved_at?->toIso8601String() ?? '',
                    'status' => $i->status?->value ?? '',
                    'type' => $type,
                ],
                'asset' => [
                    'name' => (string) ($asset?->name ?? ''),
                    'code' => (string) ($asset?->code ?? ''),
                    'image_url' => $asset?->image ? asset('storage/'.$asset->image) : '',
                    'zone' => [
                        'id' => (string) ($asset?->zone?->id ?? ''),
                        'name' => (string) ($asset?->zone?->name ?? ''),
                    ],
                    'type' => [
                        'id' => (string) ($asset?->assetType?->id ?? ''),
                        'name' => (string) ($asset?->assetType?->name ?? ''),
                    ],
                    'status' => $asset?->status?->value ?? '',
                    'value' => (string) (float) ($asset?->value ?? 0),
                    'custody' => $asset?->custody_started_at
                        ? $asset->custody_started_at->diffForHumans(now(), [
                            'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                            'parts' => 1,
                        ])
                        : '',
                    'transfer_reason' => (string) ($i->transfer_reason ?? ''),
                ],
                'skip_confirmation' => (bool) ($req?->auto_approve ?? false),
                'timelines' => TimelineItemResource::collection($i->timelines ?? collect())->resolve($request),
            ],
        ];
    }

    private function resolveType(?object $req, string $viewerBranchId): string
    {
        if (! $req) {
            return 'to_branch';
        }

        if ($req->kind?->value === TransferDisposalKind::EXTERNAL_TRANSFER->value) {
            return 'from_finance';
        }

        if ((string) $req->recipient_branch_id === $viewerBranchId) {
            return 'from_branch';
        }

        return 'to_branch';
    }
}
