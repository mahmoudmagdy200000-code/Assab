<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\HandoverItem;
use Modules\FixedAssets\Models\MajorDiscrepancyRequest;
use Modules\FixedAssets\Models\Timeline;
use RuntimeException;

class BrandOwnerFixedAssetsHandoverReportService
{
    public function list(): Collection
    {
        $rows = MajorDiscrepancyRequest::query()
            ->with([
                'handoverItem:id,handover_id,asset_name_snapshot,asset_image_snapshot',
                'branch:id,name',
            ])
            ->orderByDesc('created_at')
            ->get();

        $grouped = [];
        foreach ($rows->groupBy('handover_id') as $group) {
            /** @var MajorDiscrepancyRequest $first */
            $first = $group->first();
            $first->setRelation('groupedRequests', $group->values());
            $grouped[] = $first;
        }

        return new Collection($grouped);
    }

    public function find(string $id): MajorDiscrepancyRequest
    {
        $req = MajorDiscrepancyRequest::query()
            ->with([
                'handover.sender:id,name',
                'handover.branch:id,name',
                'handover.recipient',
                'handover.signatures',
                'handoverItem',
                'asset',
                'branch:id,name',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->find($id);

        if (! $req) {
            throw new ModelNotFoundException;
        }

        $siblings = MajorDiscrepancyRequest::query()
            ->with([
                'handoverItem:id,handover_id,asset_name_snapshot,asset_image_snapshot,value_snapshot,recipient_note,recipient_photo_path',
                'asset:id,name,image',
            ])
            ->where('handover_id', $req->handover_id)
            ->orderBy('created_at')
            ->get();

        $req->setRelation('groupedRequests', $siblings);

        return $req;
    }

    public function approve(string $id, BrandOwner $actor, string $warningNote): MajorDiscrepancyRequest
    {
        return DB::transaction(function () use ($id, $actor, $warningNote) {
            $req = MajorDiscrepancyRequest::query()->lockForUpdate()->findOrFail($id);

            $this->guardTerminal($req);

            $req->update([
                'status' => RequestStatus::APPROVED->value,
                'warning_note' => $warningNote,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($req, TimelineEventType::APPROVED, 'Approved by brand owner', $actor);

            return $req->fresh(['handover', 'handoverItem', 'asset', 'branch']);
        });
    }

    public function salaryDeduction(string $id, BrandOwner $actor, string $amount, string $reason): MajorDiscrepancyRequest
    {
        return DB::transaction(function () use ($id, $actor, $amount, $reason) {
            $req = MajorDiscrepancyRequest::query()->lockForUpdate()->findOrFail($id);

            $this->guardTerminal($req);

            $numeric = (float) $amount;
            $note = trim((string) $req->warning_note);

            $req->update([
                'status' => RequestStatus::SALARY_DEDUCTION->value,
                'salary_deduction_amount' => $numeric,
                'salary_deduction_reason' => $reason,
                'salary_deduction_note' => $note ?: null,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            HandoverItem::query()
                ->where('id', $req->handover_item_id)
                ->update([
                    'is_deducted' => true,
                    'deduction' => json_encode([
                        'employee_name' => (string) $req->employee_responsible,
                        'amount' => $numeric,
                        'reason' => $reason,
                        'note' => $note,
                    ]),
                ]);

            $this->logTimeline($req, TimelineEventType::REVIEWED, 'Salary deduction applied', $actor);

            return $req->fresh(['handover', 'handoverItem', 'asset', 'branch']);
        });
    }

    public function reject(string $id, BrandOwner $actor, string $reason): MajorDiscrepancyRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $req = MajorDiscrepancyRequest::query()->lockForUpdate()->findOrFail($id);

            $this->guardTerminal($req);

            $req->update([
                'status' => RequestStatus::REJECTED->value,
                'rejection_reason' => $reason,
                'bo_decided_by_id' => $actor->id,
                'bo_decided_at' => now(),
            ]);

            $this->logTimeline($req, TimelineEventType::REJECTED, 'Rejected by brand owner', $actor);

            return $req->fresh(['handover', 'handoverItem', 'asset', 'branch']);
        });
    }

    private function guardTerminal(MajorDiscrepancyRequest $req): void
    {
        if (in_array($req->status?->value, [
            RequestStatus::APPROVED->value,
            RequestStatus::REJECTED->value,
            RequestStatus::SALARY_DEDUCTION->value,
        ], true)) {
            throw new RuntimeException('Request already decided.');
        }
    }

    private function logTimeline(MajorDiscrepancyRequest $req, TimelineEventType $type, string $name, BrandOwner $actor): void
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
