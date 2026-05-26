<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
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

        $viewerBranchId = $this->resolveViewerBranchId();
        $type = $this->resolveType($req, $viewerBranchId);

        return [
            'id' => (string) $i->id,
            'asset_name' => (string) ($asset?->name ?? ''),
            'status' => $i->status?->value ?? '',
            'condition' => $asset?->status?->value ?? AssetStatus::EXCELLENT->value,
            'date_and_time' => $i->created_at?->toIso8601String() ?? '',
            'type' => $type,
            'from_branch_id' => (string) ($req?->branch_id ?? ''),
            'from_branch_name' => (string) ($req?->branch?->name ?? ''),
            'to_branch_id' => (string) ($req?->recipient_branch_id ?? ''),
            'to_branch_name' => (string) ($req?->recipientBranch?->name ?? ''),
        ];
    }

    private function resolveViewerBranchId(): string
    {
        $user = auth()->user();
        if (! $user) {
            return '';
        }

        $branchId = $user->branch_id ?? null;
        if ($branchId === null && method_exists($user, 'getAttribute')) {
            $branchId = $user->getAttribute('branch_id');
        }

        return (string) ($branchId ?? '');
    }

    private function resolveType(?object $req, string $viewerBranchId): string
    {
        if (! $req) {
            return 'to_branch';
        }

        if ($req->kind?->value === TransferDisposalKind::EXTERNAL_TRANSFER->value) {
            return 'from_finance';
        }

        $recipientId = (string) ($req->recipient_branch_id ?? '');
        $senderId = (string) ($req->branch_id ?? '');

        $type = match (true) {
            $viewerBranchId !== '' && $viewerBranchId === $recipientId => 'from_branch',
            $viewerBranchId !== '' && $viewerBranchId === $senderId => 'to_branch',
            default => 'to_branch',
        };

        if ($viewerBranchId === '' || ($viewerBranchId !== $recipientId && $viewerBranchId !== $senderId)) {
            Log::warning('TransferRequestList: viewer branch matches neither sender nor recipient', [
                'viewer_branch_id' => $viewerBranchId,
                'sender_branch_id' => $senderId,
                'recipient_branch_id' => $recipientId,
                'request_id' => (string) ($req->id ?? ''),
            ]);
        }

        return $type;
    }
}
