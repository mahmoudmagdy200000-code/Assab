<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\PendingReceipt;
use Modules\FixedAssets\Models\ReceiveItem;
use Modules\FixedAssets\Models\ReceiveSession;

class ReceiveAssetsService
{
    public function __construct(
        private readonly TimelineService $timelineService,
    ) {}

    public function pendingList(string $branchId): Collection
    {
        return PendingReceipt::query()
            ->where('recipient_branch_id', $branchId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();
    }

    public function findPending(string $requestId, string $branchId): PendingReceipt
    {
        return PendingReceipt::query()
            ->where('id', $requestId)
            ->where('recipient_branch_id', $branchId)
            ->firstOrFail();
    }

    public function confirmSingle(string $requestId, string $type, array $itemPayload, BranchManager $manager): ReceiveSession
    {
        return DB::transaction(function () use ($requestId, $type, $itemPayload, $manager) {
            $pending = $this->findPending($requestId, $manager->branch_id);

            $session = ReceiveSession::create([
                'recipient_branch_id' => $manager->branch_id,
                'received_by_id' => $manager->id,
                'type' => $type,
                'received_at' => now(),
            ]);

            /** @var UploadedFile|null $image */
            $image = $itemPayload['image'] ?? null;
            $imagePath = $image instanceof UploadedFile
                ? $image->store('fixed-assets/receipts', 'public')
                : null;

            $fixedAsset = FixedAsset::create([
                'name' => $pending->asset_name,
                'code' => $pending->asset_code.'-'.Str::upper(Str::random(4)),
                'image' => $imagePath ?? $pending->asset_image,
                'branch_id' => $manager->branch_id,
                'zone_id' => $itemPayload['assignedZoneId'],
                'asset_type_id' => $itemPayload['assetTypeId'],
                'assigned_to_type' => BranchManager::class,
                'assigned_to_id' => $manager->id,
                'status' => $this->resolveStatusFromCounts($itemPayload),
                'value' => 0,
                'acquired_at' => now(),
                'custody_started_at' => now(),
                'last_updated_at' => now(),
            ]);

            ReceiveItem::create([
                'receive_session_id' => $session->id,
                'pending_receipt_id' => $pending->id,
                'fixed_asset_id' => $fixedAsset->id,
                'assigned_zone_id' => $itemPayload['assignedZoneId'],
                'asset_type_id' => $itemPayload['assetTypeId'],
                'asset_count' => (int) ($itemPayload['assetCount'] ?? 0),
                'excellent_count' => (int) ($itemPayload['excellentCount'] ?? 0),
                'need_attention_count' => (int) ($itemPayload['needAttentionCount'] ?? 0),
                'problem_count' => (int) ($itemPayload['problemCount'] ?? 0),
                'image_path' => $imagePath,
            ]);

            $pending->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            $this->timelineService->log(
                $fixedAsset,
                TimelineEventType::RECEIVED,
                'Asset received at branch',
                $manager,
            );

            return $session->fresh(['items']);
        });
    }

    private function resolveStatusFromCounts(array $payload): string
    {
        $problem = (int) ($payload['problemCount'] ?? 0);
        $needAttention = (int) ($payload['needAttentionCount'] ?? 0);

        if ($problem > 0) {
            return AssetStatus::PROBLEM->value;
        }

        if ($needAttention > 0) {
            return AssetStatus::NEED_ATTENTION->value;
        }

        return AssetStatus::EXCELLENT->value;
    }
}
