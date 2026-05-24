<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Enums\TransferDirection;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class TransferDisposalService
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly TimelineService $timelineService,
    ) {}

    public function create(array $payload, BranchManager $manager): TransferDisposalRequest
    {
        return DB::transaction(function () use ($payload, $manager) {
            $kind = $payload['type'];

            $request = TransferDisposalRequest::create([
                'kind' => $kind,
                'branch_id' => $manager->branch_id,
                'requested_by_id' => $manager->id,
                'recipient_branch_id' => $kind === TransferDisposalKind::TRANSFER_TO_BRANCH->value
                    ? ($payload['branchId'] ?? null)
                    : null,
                'auto_approve' => (bool) ($payload['autoApprove'] ?? false),
                'disposal_date' => $kind === TransferDisposalKind::DISPOSAL->value
                    ? ($payload['disposalDate'] ?? null)
                    : null,
                'disposal_time' => $kind === TransferDisposalKind::DISPOSAL->value
                    ? ($payload['disposalTime'] ?? null)
                    : null,
                'disposal_method' => $kind === TransferDisposalKind::DISPOSAL->value
                    ? ($payload['disposalMethod'] ?? null)
                    : null,
                'direction' => $kind === TransferDisposalKind::TRANSFER_TO_BRANCH->value
                    ? TransferDirection::TO_BRANCH->value
                    : null,
                'status' => ($payload['autoApprove'] ?? false)
                    ? RequestStatus::APPROVED->value
                    : RequestStatus::PENDING->value,
                'approved_at' => ($payload['autoApprove'] ?? false) ? now() : null,
            ]);

            foreach ((array) ($payload['assets'] ?? []) as $index => $asset) {
                $assetId = $asset['assetId'] ?? null;
                if (! $assetId) {
                    continue;
                }

                FixedAsset::query()
                    ->where('id', $assetId)
                    ->where('branch_id', $manager->branch_id)
                    ->firstOrFail();

                $itemStatus = ($payload['autoApprove'] ?? false)
                    ? RequestStatus::APPROVED->value
                    : RequestStatus::PENDING->value;

                $item = TransferDisposalItem::create([
                    'request_id' => $request->id,
                    'asset_id' => $assetId,
                    'transfer_reason' => $asset['transferReason'] ?? null,
                    'disposal_reason' => $asset['disposalReason'] ?? null,
                    'condition_description' => $asset['conditionDescription'] ?? null,
                    'status' => $itemStatus,
                ]);

                if (isset($asset['documentationPhotos']) && $asset['documentationPhotos'] instanceof UploadedFile) {
                    $this->attachmentService->store(
                        $asset['documentationPhotos'],
                        'documentation_photo',
                        $item,
                        $manager,
                    );
                }

                if (isset($asset['visualEvidence']) && $asset['visualEvidence'] instanceof UploadedFile) {
                    $this->attachmentService->store(
                        $asset['visualEvidence'],
                        'visual_evidence',
                        $item,
                        $manager,
                    );
                }
            }

            $eventType = match ($kind) {
                TransferDisposalKind::DISPOSAL->value => TimelineEventType::DISPOSED,
                default => TimelineEventType::TRANSFERRED,
            };

            $this->timelineService->log(
                $request,
                TimelineEventType::SUBMITTED,
                'Request submitted',
                $manager,
            );

            return $request->fresh(['items', 'recipientBranch', 'requestedBy']);
        });
    }

    public function paginateModifications(string $branchId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        // delegated elsewhere - see ModificationRequestService
        return new LengthAwarePaginator([], 0, $perPage, $page);
    }

    public function paginateTransfers(string $branchId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return TransferDisposalRequest::query()
            ->with(['items.asset:id,name,status', 'requestedBy:id,name'])
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhere('recipient_branch_id', $branchId);
            })
            ->whereIn('kind', [
                TransferDisposalKind::TRANSFER_TO_BRANCH->value,
                TransferDisposalKind::EXTERNAL_TRANSFER->value,
            ])
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function paginateDisposals(string $branchId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return TransferDisposalRequest::query()
            ->with(['items.asset:id,name'])
            ->where('branch_id', $branchId)
            ->where('kind', TransferDisposalKind::DISPOSAL->value)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function approveTransfer(string $requestId, string $branchId, BranchManager $manager): TransferDisposalRequest
    {
        return DB::transaction(function () use ($requestId, $branchId, $manager) {
            $request = TransferDisposalRequest::query()
                ->where('id', $requestId)
                ->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                        ->orWhere('recipient_branch_id', $branchId);
                })
                ->whereIn('kind', [
                    TransferDisposalKind::TRANSFER_TO_BRANCH->value,
                    TransferDisposalKind::EXTERNAL_TRANSFER->value,
                ])
                ->firstOrFail();

            $request->update([
                'status' => RequestStatus::APPROVED->value,
                'approved_at' => now(),
            ]);

            $this->timelineService->log(
                $request,
                TimelineEventType::APPROVED,
                'Transfer approved',
                $manager,
            );

            return $request->fresh();
        });
    }

    public function approveTransferItemDest(string $itemId, BranchManager $manager): TransferDisposalItem
    {
        return DB::transaction(function () use ($itemId, $manager) {
            $item = TransferDisposalItem::query()
                ->with('request')
                ->lockForUpdate()
                ->findOrFail($itemId);

            $req = $item->request;
            if (! $req || (string) $req->recipient_branch_id !== (string) $manager->branch_id) {
                throw new \RuntimeException('Only the destination branch manager may decide this item.');
            }

            if ($item->status?->value !== RequestStatus::PENDING->value) {
                throw new \RuntimeException('Item is no longer pending at destination.');
            }

            $item->update([
                'status' => RequestStatus::PENDING_FINAL_APPROVAL->value,
                'dest_decided_by_id' => (string) $manager->id,
                'dest_decided_at' => now(),
            ]);

            $this->timelineService->log(
                $item,
                TimelineEventType::APPROVED,
                'Destination branch manager approved item',
                $manager,
            );

            return $item->fresh(['request', 'asset']);
        });
    }

    public function rejectTransferItemDest(string $itemId, BranchManager $manager, string $reason): TransferDisposalItem
    {
        return DB::transaction(function () use ($itemId, $manager, $reason) {
            $item = TransferDisposalItem::query()
                ->with('request')
                ->lockForUpdate()
                ->findOrFail($itemId);

            $req = $item->request;
            if (! $req || (string) $req->recipient_branch_id !== (string) $manager->branch_id) {
                throw new \RuntimeException('Only the destination branch manager may decide this item.');
            }

            if ($item->status?->value !== RequestStatus::PENDING->value) {
                throw new \RuntimeException('Item is no longer pending at destination.');
            }

            $item->update([
                'status' => RequestStatus::REJECTED->value,
                'rejection_reason' => $reason,
                'dest_decided_by_id' => (string) $manager->id,
                'dest_decided_at' => now(),
            ]);

            $this->timelineService->log(
                $item,
                TimelineEventType::REJECTED,
                'Destination branch manager rejected item',
                $manager,
            );

            return $item->fresh(['request', 'asset']);
        });
    }

    public function transferDetails(string $requestId, string $branchId): ?TransferDisposalRequest
    {
        return TransferDisposalRequest::query()
            ->with([
                'branch:id,name',
                'recipientBranch:id,name',
                'requestedBy:id,name',
                'items.asset.zone',
                'items.asset.assetType',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->where('id', $requestId)
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhere('recipient_branch_id', $branchId);
            })
            ->whereIn('kind', [
                TransferDisposalKind::TRANSFER_TO_BRANCH->value,
                TransferDisposalKind::EXTERNAL_TRANSFER->value,
            ])
            ->first();
    }

    public function disposalDetails(string $requestId, string $branchId): ?TransferDisposalRequest
    {
        return TransferDisposalRequest::query()
            ->with([
                'items.asset.zone',
                'items.asset.assetType',
                'requestedBy:id,name',
                'timelines' => fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->where('id', $requestId)
            ->where('branch_id', $branchId)
            ->where('kind', TransferDisposalKind::DISPOSAL->value)
            ->first();
    }
}
