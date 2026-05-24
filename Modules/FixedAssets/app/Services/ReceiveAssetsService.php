<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\PendingReceipt;
use Modules\FixedAssets\Models\ReceiveItem;
use Modules\FixedAssets\Models\ReceiveSession;
use Modules\FixedAssets\Models\TransferDisposalItem;

class ReceiveAssetsService
{
    public function __construct(
        private readonly TimelineService $timelineService,
    ) {}

    public function pendingList(string $branchId): Collection
    {
        $pending = PendingReceipt::query()
            ->where('recipient_branch_id', $branchId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PendingReceipt $p) => [
                'id' => (string) $p->id,
                'source_type' => 'pending',
                'asset_name' => (string) $p->asset_name,
                'asset_code' => (string) $p->asset_code,
                'asset_image' => $p->asset_image,
            ]);

        $transfers = TransferDisposalItem::query()
            ->with(['request', 'asset:id,name,code,image,branch_id'])
            ->whereHas('request', fn ($q) => $q->where('recipient_branch_id', $branchId)
                ->where('status', '!=', RequestStatus::REJECTED->value))
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', RequestStatus::REJECTED->value);
            })
            ->orderBy('created_at')
            ->get()
            ->filter(fn (TransferDisposalItem $i) => $i->asset && (string) $i->asset->branch_id !== $branchId)
            ->map(fn (TransferDisposalItem $i) => [
                'id' => (string) $i->id,
                'source_type' => 'transfer',
                'asset_name' => (string) ($i->asset?->name ?? ''),
                'asset_code' => (string) ($i->asset?->code ?? ''),
                'asset_image' => $i->asset?->image,
            ]);

        return $pending->concat($transfers)->values();
    }

    public function findIncoming(string $requestId, string $branchId): array
    {
        $pending = PendingReceipt::query()
            ->where('id', $requestId)
            ->where('recipient_branch_id', $branchId)
            ->first();

        if ($pending) {
            return ['type' => 'pending', 'model' => $pending];
        }

        $item = TransferDisposalItem::query()
            ->with(['request', 'asset:id,name,code,image,branch_id,zone_id,asset_type_id'])
            ->where('id', $requestId)
            ->whereHas('request', fn ($q) => $q->where('recipient_branch_id', $branchId))
            ->first();

        if ($item) {
            return ['type' => 'transfer', 'model' => $item];
        }

        throw new ModelNotFoundException("Incoming asset not found: {$requestId}");
    }

    public function confirmSingle(string $requestId, string $type, array $itemPayload, BranchManager $manager): array
    {
        return DB::transaction(function () use ($requestId, $type, $itemPayload, $manager) {
            $incoming = $this->findIncoming($requestId, $manager->branch_id);

            return $incoming['type'] === 'transfer'
                ? $this->confirmTransfer($incoming['model'], $type, $itemPayload, $manager)
                : $this->confirmPending($incoming['model'], $type, $itemPayload, $manager);
        });
    }

    private function confirmPending(PendingReceipt $pending, string $type, array $itemPayload, BranchManager $manager): array
    {
        $session = ReceiveSession::create([
            'recipient_branch_id' => $manager->branch_id,
            'received_by_id' => $manager->id,
            'type' => $type,
            'received_at' => now(),
        ]);

        $imagePath = $this->storeImage($itemPayload['image'] ?? null);

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

        return [
            'session_id' => (string) $session->id,
            'received_count' => 1,
        ];
    }

    private function confirmTransfer(TransferDisposalItem $item, string $type, array $itemPayload, BranchManager $manager): array
    {
        if (! $item->asset) {
            throw new \RuntimeException('Transfer item has no linked asset.');
        }

        $reqStatus = $item->request?->status?->value ?? $item->request?->status ?? null;
        $itemStatus = $item->status?->value ?? null;

        if ($reqStatus === RequestStatus::REJECTED->value || $itemStatus === RequestStatus::REJECTED->value) {
            throw new \RuntimeException('Transfer rejected — cannot receive.');
        }

        $session = ReceiveSession::create([
            'recipient_branch_id' => $manager->branch_id,
            'received_by_id' => $manager->id,
            'type' => $type,
            'received_at' => now(),
        ]);

        $imagePath = $this->storeImage($itemPayload['image'] ?? null);

        $asset = $item->asset;
        $asset->update([
            'branch_id' => $manager->branch_id,
            'zone_id' => $itemPayload['assignedZoneId'],
            'asset_type_id' => $itemPayload['assetTypeId'],
            'assigned_to_type' => BranchManager::class,
            'assigned_to_id' => $manager->id,
            'status' => $this->resolveStatusFromCounts($itemPayload),
            'custody_started_at' => now(),
            'last_updated_at' => now(),
            'image' => $imagePath ?? $asset->image,
        ]);

        $item->update([
            'status' => RequestStatus::APPROVED->value,
            'dest_decided_by_id' => (string) $manager->id,
            'dest_decided_at' => now(),
        ]);

        $this->timelineService->log(
            $asset,
            TimelineEventType::RECEIVED,
            'Asset received at branch via transfer',
            $manager,
        );

        return [
            'session_id' => (string) $session->id,
            'received_count' => 1,
        ];
    }

    private function storeImage(?UploadedFile $image): ?string
    {
        return $image instanceof UploadedFile
            ? $image->store('fixed-assets/receipts', 'public')
            : null;
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
