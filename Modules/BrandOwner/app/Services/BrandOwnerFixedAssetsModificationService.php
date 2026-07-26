<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\ModificationRequest;
use Modules\FixedAssets\Models\Timeline;
use RuntimeException;

class BrandOwnerFixedAssetsModificationService
{
    public function list(): Collection
    {
        return ModificationRequest::query()
            ->with(['asset:id,name,image', 'branch:id,name'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $id): ModificationRequest
    {
        $req = ModificationRequest::query()
            ->with([
                'asset.zone',
                'asset.assetType',
                'branch:id,name',
                'requestedBy:id,name',
                'attachment',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->find($id);

        if (! $req) {
            throw new ModelNotFoundException;
        }

        return $req;
    }

    public function approve(string $id, BrandOwner $actor): ModificationRequest
    {
        return DB::transaction(function () use ($id, $actor) {
            $req = ModificationRequest::query()->lockForUpdate()->findOrFail($id);

            $this->guardTerminal($req);

            $req->update([
                'status' => RequestStatus::APPROVED->value,
                'approved_at' => now(),
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($req, TimelineEventType::APPROVED, 'Approved by brand owner', $actor);

            return $req->fresh(['asset', 'branch', 'timelines']);
        });
    }

    public function reject(string $id, BrandOwner $actor, string $reason): ModificationRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $req = ModificationRequest::query()->lockForUpdate()->findOrFail($id);

            $this->guardTerminal($req);

            $req->update([
                'status' => RequestStatus::REJECTED->value,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($req, TimelineEventType::REJECTED, 'Rejected by brand owner', $actor);

            return $req->fresh(['asset', 'branch', 'timelines']);
        });
    }

    private function guardTerminal(ModificationRequest $req): void
    {
        if (in_array($req->status?->value, [RequestStatus::APPROVED->value, RequestStatus::REJECTED->value], true)) {
            throw new RuntimeException('Request already decided.');
        }
    }

    private function logTimeline(ModificationRequest $req, TimelineEventType $type, string $name, BrandOwner $actor): void
    {
        Timeline::create([
            'timelineable_type' => $req->getMorphClass(),
            'timelineable_id' => (string) $req->id,
            'event_type' => $type->value,
            'name' => $name,
            'actor_id' => (string) $actor->id,
            'occurred_at' => now(),
        ]);
    }
}
