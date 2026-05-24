<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\TransferDisposalItem;

class TransferRequestListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;
        $req = $i->request;
        $asset = $i->asset;

        $viewerBranchId = (string) (auth()->user()?->branch_id ?? '');
        $type = $this->resolveType($req, $viewerBranchId);

        return [
            'id' => (string) $i->id,
            'asset_name' => (string) ($asset?->name ?? ''),
            'status' => $i->status?->value ?? '',
            'condition' => $asset?->status?->value ?? AssetStatus::EXCELLENT->value,
            'date_and_time' => $i->created_at?->toIso8601String() ?? '',
            'type' => $type,
            'from_branch_name' => (string) ($req?->branch?->name ?? ''),
            'to_branch_name' => (string) ($req?->recipientBranch?->name ?? ''),
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
