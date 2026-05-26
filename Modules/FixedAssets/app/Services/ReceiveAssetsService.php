<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use Modules\FixedAssets\Models\TransferDisposalRequest;

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
                'type' => 'from_finance',
                'from_branch_id' => '',
                'from_branch_name' => '',
                'to_branch_id' => $branchId,
                'asset_name' => (string) $p->asset_name,
                'asset_code' => (string) $p->asset_code,
                'asset_image' => $p->asset_image,
            ]);

        $transfers = TransferDisposalItem::query()
            ->with(['request.branch:id,name', 'asset:id,name,code,image,branch_id'])
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
                'type' => 'from_branch',
                'from_branch_id' => (string) ($i->request?->branch_id ?? ''),
                'from_branch_name' => (string) ($i->request?->branch?->name ?? ''),
                'to_branch_id' => $branchId,
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

        $fallback = $this->resolveIncomingFallback($requestId, $branchId);
        if ($fallback) {
            return $fallback;
        }

        throw new ModelNotFoundException("Incoming asset not found: {$requestId}");
    }

    /**
     * Tolerate clients that send request_id, asset_id, or pending receipt's source id
     * instead of the TransferDisposalItem.id / PendingReceipt.id expected by the list endpoint.
     *
     * @return array{type: string, model: mixed}|null
     */
    private function resolveIncomingFallback(string $id, string $branchId): ?array
    {
        $byRequest = TransferDisposalItem::query()
            ->with(['request', 'asset:id,name,code,image,branch_id,zone_id,asset_type_id'])
            ->where('request_id', $id)
            ->whereHas('request', fn ($q) => $q->where('recipient_branch_id', $branchId))
            ->orderBy('created_at')
            ->get();

        if ($byRequest->count() === 1) {
            Log::info('ReceiveAssets fallback: matched by request_id', [
                'request_id' => $id,
                'branch_id' => $branchId,
                'item_id' => (string) $byRequest->first()->id,
            ]);
            return ['type' => 'transfer', 'model' => $byRequest->first()];
        }

        if ($byRequest->count() > 1) {
            Log::warning('ReceiveAssets fallback: ambiguous request_id with multiple items', [
                'request_id' => $id,
                'branch_id' => $branchId,
                'item_count' => $byRequest->count(),
            ]);
        }

        $byAsset = TransferDisposalItem::query()
            ->with(['request', 'asset:id,name,code,image,branch_id,zone_id,asset_type_id'])
            ->where('asset_id', $id)
            ->whereHas('request', fn ($q) => $q->where('recipient_branch_id', $branchId)
                ->where('status', '!=', RequestStatus::REJECTED->value))
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhere('status', '!=', RequestStatus::REJECTED->value);
            })
            ->orderByDesc('created_at')
            ->first();

        if ($byAsset) {
            Log::info('ReceiveAssets fallback: matched by asset_id', [
                'asset_id' => $id,
                'branch_id' => $branchId,
                'item_id' => (string) $byAsset->id,
            ]);
            return ['type' => 'transfer', 'model' => $byAsset];
        }

        $existsElsewhere = PendingReceipt::query()->where('id', $id)->exists()
            || TransferDisposalItem::query()->where('id', $id)->exists()
            || TransferDisposalRequest::query()->where('id', $id)->exists();

        if ($existsElsewhere) {
            Log::warning('ReceiveAssets 404: id exists but not for this branch', [
                'id' => $id,
                'viewer_branch_id' => $branchId,
            ]);
        }

        return null;
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
            'status' => RequestStatus::PENDING_FINAL_APPROVAL->value,
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
