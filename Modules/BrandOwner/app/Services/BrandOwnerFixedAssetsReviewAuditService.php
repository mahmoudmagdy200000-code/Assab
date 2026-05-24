<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\ReviewAuditRequest;
use Modules\FixedAssets\Models\Timeline;
use RuntimeException;

class BrandOwnerFixedAssetsReviewAuditService
{
    public function list(): Collection
    {
        return ReviewAuditRequest::query()
            ->with(['branch:id,name'])
            ->whereIn('status', [
                RequestStatus::PENDING_FINAL_APPROVAL->value,
                RequestStatus::APPROVED->value,
                RequestStatus::REJECTED->value,
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $id): ReviewAuditRequest
    {
        $req = ReviewAuditRequest::query()
            ->with([
                'branch:id,name',
                'asset.zone',
                'asset.assetType',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->find($id);

        if (! $req) {
            throw new ModelNotFoundException();
        }

        return $req;
    }

    public function approve(string $id, BrandOwner $actor): ReviewAuditRequest
    {
        return DB::transaction(function () use ($id, $actor) {
            $req = ReviewAuditRequest::query()->lockForUpdate()->findOrFail($id);

            if ($req->status?->value !== RequestStatus::PENDING_FINAL_APPROVAL->value) {
                throw new RuntimeException('Brand owner can only act when status is pending_final_approval.');
            }

            $req->update([
                'status' => RequestStatus::APPROVED->value,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($req, TimelineEventType::APPROVED, 'Approved by brand owner', $actor);

            return $req->fresh(['branch']);
        });
    }

    public function reject(string $id, BrandOwner $actor, string $reason): ReviewAuditRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $req = ReviewAuditRequest::query()->lockForUpdate()->findOrFail($id);

            if ($req->status?->value !== RequestStatus::PENDING_FINAL_APPROVAL->value) {
                throw new RuntimeException('Brand owner can only act when status is pending_final_approval.');
            }

            $req->update([
                'status' => RequestStatus::REJECTED->value,
                'rejection_reason' => $reason,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($req, TimelineEventType::REJECTED, 'Rejected by brand owner', $actor);

            return $req->fresh(['branch']);
        });
    }

    private function logTimeline(ReviewAuditRequest $req, TimelineEventType $type, string $name, BrandOwner $actor): void
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
