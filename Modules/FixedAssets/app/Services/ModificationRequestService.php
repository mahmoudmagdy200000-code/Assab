<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\ModificationDoneAction;
use Modules\FixedAssets\Models\ModificationRequest;

class ModificationRequestService
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly TimelineService $timelineService,
    ) {}

    public function paginate(string $branchId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return ModificationRequest::query()
            ->with(['asset:id,name'])
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function create(array $payload, UploadedFile $attachment, BranchManager $manager): ModificationRequest
    {
        return DB::transaction(function () use ($payload, $attachment, $manager) {
            $asset = FixedAsset::query()
                ->where('id', $payload['asset_id'])
                ->where('branch_id', $manager->branch_id)
                ->firstOrFail();

            $request = ModificationRequest::create([
                'asset_id' => $asset->id,
                'branch_id' => $manager->branch_id,
                'requested_by_id' => $manager->id,
                'status' => RequestStatus::PENDING->value,
                'new_status' => $payload['new_status'],
                'reason' => $payload['reason'],
                'next_action' => $payload['next_action'],
                'approval_request_owner_note' => $payload['approval_request_owner_note'],
            ]);

            foreach ((array) ($payload['done_actions'] ?? []) as $action) {
                ModificationDoneAction::create([
                    'modification_request_id' => $request->id,
                    'action' => $action,
                ]);
            }

            $this->attachmentService->store($attachment, 'modification_doc', $request, $manager);

            $this->timelineService->log(
                $request,
                TimelineEventType::SUBMITTED,
                'Modification request submitted',
                $manager,
            );

            return $request->fresh(['asset', 'doneActions', 'attachment', 'timelines']);
        });
    }

    public function findForBranch(string $requestId, string $branchId): ?ModificationRequest
    {
        return ModificationRequest::query()
            ->with([
                'asset.zone',
                'asset.assetType',
                'asset.assignedTo',
                'doneActions',
                'attachment',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->where('id', $requestId)
            ->where('branch_id', $branchId)
            ->first();
    }
}
