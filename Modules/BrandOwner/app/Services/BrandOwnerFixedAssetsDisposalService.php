<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\Timeline;
use Modules\FixedAssets\Models\TransferDisposalItem;
use RuntimeException;

class BrandOwnerFixedAssetsDisposalService
{
    public function list(): Collection
    {
        return TransferDisposalItem::query()
            ->with([
                'asset:id,name,image',
                'request:id,kind,branch_id,created_at',
                'request.branch:id,name',
            ])
            ->whereHas('request', function ($q) {
                $q->whereIn('kind', [
                    TransferDisposalKind::DISPOSAL->value,
                    TransferDisposalKind::EXTERNAL_TRANSFER->value,
                ]);
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $itemId): TransferDisposalItem
    {
        $item = TransferDisposalItem::query()
            ->with([
                'asset.zone',
                'asset.assetType',
                'request.branch:id,name',
                'request.requestedBy:id,name',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
                'visualEvidence',
                'documentationPhoto',
            ])
            ->find($itemId);

        if (! $item) {
            throw new ModelNotFoundException();
        }

        return $item;
    }

    public function approve(string $itemId, BrandOwner $actor): TransferDisposalItem
    {
        return DB::transaction(function () use ($itemId, $actor) {
            $item = TransferDisposalItem::query()->lockForUpdate()->findOrFail($itemId);

            $this->guardTerminal($item);

            $item->update([
                'status' => RequestStatus::APPROVED->value,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($item, TimelineEventType::APPROVED, 'Approved by brand owner', $actor);

            return $item->fresh(['asset', 'request']);
        });
    }

    public function reject(string $itemId, BrandOwner $actor, string $reason): TransferDisposalItem
    {
        return DB::transaction(function () use ($itemId, $actor, $reason) {
            $item = TransferDisposalItem::query()->lockForUpdate()->findOrFail($itemId);

            $this->guardTerminal($item);

            $item->update([
                'status' => RequestStatus::REJECTED->value,
                'rejection_reason' => $reason,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($item, TimelineEventType::REJECTED, 'Rejected by brand owner', $actor);

            return $item->fresh(['asset', 'request']);
        });
    }

    private function guardTerminal(TransferDisposalItem $item): void
    {
        if (in_array($item->status?->value, [RequestStatus::APPROVED->value, RequestStatus::REJECTED->value], true)) {
            throw new RuntimeException('Request already decided.');
        }
    }

    private function logTimeline(TransferDisposalItem $item, TimelineEventType $type, string $name, BrandOwner $actor): void
    {
        Timeline::create([
            'timelineable_type' => $item->getMorphClass(),
            'timelineable_id' => (string) $item->id,
            'event_type' => $type->value,
            'name' => $name,
            'actor_id' => (string) $actor->id,
            'occurred_at' => now(),
        ]);
    }
}
