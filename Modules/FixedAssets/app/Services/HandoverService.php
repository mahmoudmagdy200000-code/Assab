<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\HandoverNotificationChannel;
use Modules\FixedAssets\Enums\HandoverSignatureRole;
use Modules\FixedAssets\Enums\HandoverStatus;
use Modules\FixedAssets\Enums\RecipientInspectionResult;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Models\HandoverItem;
use Modules\FixedAssets\Models\HandoverSignature;
use Modules\FixedAssets\Models\HandoverZoneApproval;
use RuntimeException;

class HandoverService
{
    public function __construct(
        private readonly HandoverQrCodeService $qr,
        private readonly HandoverRecipientResolver $recipientResolver,
        private readonly HandoverBroadcastService $broadcaster,
        private readonly TimelineService $timeline,
    ) {}

    public function activeHandoverForBranch(string $branchId): ?Handover
    {
        return Handover::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                HandoverStatus::PENDING_APPROVAL->value,
                HandoverStatus::PENDING->value,
            ])
            ->latest('started_at')
            ->first();
    }

    public function start(
        BranchManager $sender,
        string $recipientEmployeeId,
        string $note,
        array $includedAssetIds,
        array $sendInvitations,
    ): Handover {
        $branchId = (string) $sender->branch_id;

        $recipient = $this->recipientResolver->resolve($recipientEmployeeId, $branchId);
        if (! $recipient) {
            throw new RuntimeException('Recipient employee not found in this branch.');
        }

        $assets = FixedAsset::query()
            ->with(['zone', 'assetType'])
            ->whereIn('id', array_values(array_unique($includedAssetIds)))
            ->where('branch_id', $branchId)
            ->get();

        if ($assets->count() === 0) {
            throw new RuntimeException('No valid assets found for this branch.');
        }

        $sendInvitations = array_values(array_intersect(
            HandoverNotificationChannel::values(),
            array_unique($sendInvitations),
        ));

        return DB::transaction(function () use ($sender, $recipient, $branchId, $note, $assets, $sendInvitations) {
            $handover = Handover::create([
                'session_code' => $this->generateSessionCode(),
                'branch_id' => $branchId,
                'sender_id' => (string) $sender->id,
                'recipient_type' => $recipient->getMorphClass(),
                'recipient_id' => (string) $recipient->getKey(),
                'status' => HandoverStatus::PENDING_APPROVAL->value,
                'note' => $note,
                'sent_invitations' => $sendInvitations,
                'started_at' => now(),
            ]);

            foreach ($assets as $asset) {
                HandoverItem::create([
                    'handover_id' => (string) $handover->id,
                    'asset_id' => (string) $asset->id,
                    'zone_id' => $asset->zone_id,
                    'zone_name_snapshot' => $asset->zone?->name,
                    'asset_name_snapshot' => $asset->name,
                    'asset_code_snapshot' => $asset->code,
                    'asset_image_snapshot' => $asset->image,
                    'asset_type_name_snapshot' => $asset->assetType?->name,
                    'value_snapshot' => $asset->value,
                    'acquired_at_snapshot' => $asset->acquired_at,
                    'current_qty' => 1,
                ]);
            }

            $qrPath = $this->qr->generateAndStore((string) $handover->id);
            $handover->update(['qr_code_image_path' => $qrPath]);

            $this->timeline->log(
                $handover,
                TimelineEventType::HANDOVER_STARTED,
                'Handover started',
                $sender,
            );

            $this->broadcaster->statusChanged($handover->fresh());

            return $handover->fresh(['items']);
        });
    }

    public function findOrFail(string $handoverId): Handover
    {
        return Handover::query()
            ->with(['items.asset', 'zoneApprovals', 'signatures', 'sender'])
            ->findOrFail($handoverId);
    }

    public function joinDetails(Handover $handover): Handover
    {
        return $handover->load(['items', 'sender.branch', 'branch']);
    }

    public function recordJoin(Handover $handover, BranchManager $actor): void
    {
        $this->timeline->log(
            $handover,
            TimelineEventType::HANDOVER_JOINED,
            'Handover joined',
            $actor,
        );
    }

    public function approveZone(
        Handover $handover,
        string $zoneId,
        array $items,
        array $files,
        BranchManager $actor,
    ): Handover {
        $this->guardCanInspect($handover);

        DB::transaction(function () use ($handover, $zoneId, $items, $files, $actor) {
            foreach ($items as $key => $payload) {
                $assetId = (string) ($payload['assetId'] ?? '');
                if ($assetId === '') {
                    continue;
                }

                $item = HandoverItem::query()
                    ->where('handover_id', $handover->id)
                    ->where('asset_id', $assetId)
                    ->where('zone_id', $zoneId)
                    ->first();

                if (! $item) {
                    continue;
                }

                $inspection = (string) ($payload['recipientInspection'] ?? '');
                $note = $payload['recipientNote'] ?? null;
                $newQty = isset($payload['newQty']) && $payload['newQty'] !== '' && $payload['newQty'] !== null
                    ? (int) $payload['newQty']
                    : null;

                /** @var UploadedFile|null $photo */
                $photo = $files[$key]['photo'] ?? null;
                $photoPath = $item->recipient_photo_path;
                if ($photo instanceof UploadedFile) {
                    $photoPath = $photo->store('fixed-assets/handover-photos', 'public');
                }

                $item->update([
                    'recipient_inspection' => $inspection,
                    'recipient_note' => $note !== null ? (string) $note : null,
                    'recipient_photo_path' => $photoPath,
                    'new_qty' => $newQty,
                    'inspected_at' => now(),
                ]);
            }

            HandoverZoneApproval::updateOrCreate(
                ['handover_id' => $handover->id, 'zone_id' => $zoneId],
                ['approved_at' => now()],
            );

            $this->timeline->log(
                $handover,
                TimelineEventType::HANDOVER_ZONE_APPROVED,
                'Zone approved',
                $actor,
            );
        });

        return $handover->fresh(['items', 'zoneApprovals']);
    }

    public function approveAll(Handover $handover, BranchManager $actor): Handover
    {
        $this->guardCanInspect($handover);

        $zoneIds = HandoverItem::query()
            ->where('handover_id', $handover->id)
            ->whereNotNull('zone_id')
            ->distinct()
            ->pluck('zone_id');

        DB::transaction(function () use ($handover, $zoneIds, $actor) {
            $now = now();

            HandoverItem::query()
                ->where('handover_id', $handover->id)
                ->whereNull('recipient_inspection')
                ->update([
                    'recipient_inspection' => RecipientInspectionResult::EXCELLENT->value,
                    'inspected_at' => $now,
                ]);

            foreach ($zoneIds as $zoneId) {
                HandoverZoneApproval::updateOrCreate(
                    ['handover_id' => $handover->id, 'zone_id' => $zoneId],
                    ['approved_at' => $now],
                );
            }

            $this->timeline->log(
                $handover,
                TimelineEventType::HANDOVER_ZONE_APPROVED,
                'All zones approved',
                $actor,
            );
        });

        return $handover->fresh(['items', 'zoneApprovals']);
    }

    public function signReceiver(Handover $handover, Model $actor): HandoverSignature
    {
        if ($handover->status !== HandoverStatus::PENDING_APPROVAL) {
            throw new RuntimeException('Recipient has already signed or handover is completed.');
        }

        if ((string) $handover->recipient_id !== (string) $actor->getKey()) {
            throw new RuntimeException('Only the assigned recipient may sign as receiver.');
        }

        return DB::transaction(function () use ($handover, $actor) {
            $signature = HandoverSignature::create([
                'handover_id' => (string) $handover->id,
                'role' => HandoverSignatureRole::RECEIVER->value,
                'signed_by_type' => $actor->getMorphClass(),
                'signed_by_id' => (string) $actor->getKey(),
                'signed_by_name_snapshot' => (string) ($actor->name ?? ''),
                'signed_at' => now(),
            ]);

            $handover->update(['status' => HandoverStatus::PENDING->value]);

            $this->timeline->log(
                $handover,
                TimelineEventType::HANDOVER_RECEIVER_SIGNED,
                'Receiver signed',
                $actor instanceof BranchManager ? $actor : null,
            );

            $fresh = $handover->fresh();
            $this->broadcaster->receiverSigned($fresh, $signature);
            $this->broadcaster->statusChanged($fresh);

            return $signature;
        });
    }

    public function signSender(Handover $handover, BranchManager $actor): HandoverSignature
    {
        if ($handover->status !== HandoverStatus::PENDING) {
            throw new RuntimeException('Sender can only sign after the recipient has signed.');
        }

        if ((string) $actor->id !== (string) $handover->sender_id) {
            throw new RuntimeException('Only the original sender may sign as sender.');
        }

        return DB::transaction(function () use ($handover, $actor) {
            $signature = HandoverSignature::create([
                'handover_id' => (string) $handover->id,
                'role' => HandoverSignatureRole::SENDER->value,
                'signed_by_type' => $actor->getMorphClass(),
                'signed_by_id' => (string) $actor->getKey(),
                'signed_by_name_snapshot' => (string) ($actor->name ?? ''),
                'signed_at' => now(),
            ]);

            $this->timeline->log(
                $handover,
                TimelineEventType::HANDOVER_SENDER_SIGNED,
                'Sender signed',
                $actor,
            );

            $this->broadcaster->senderSigned($handover->fresh(), $signature);

            return $signature;
        });
    }

    public function complete(Handover $handover, Model $actor): Handover
    {
        if ($handover->status === HandoverStatus::COMPLETED) {
            return $handover;
        }

        if ($handover->status !== HandoverStatus::PENDING) {
            throw new RuntimeException('Handover cannot be completed before both signatures are present.');
        }

        $signatures = $handover->signatures()->get()->keyBy(fn ($s) => $s->role->value);
        if (! isset($signatures[HandoverSignatureRole::SENDER->value], $signatures[HandoverSignatureRole::RECEIVER->value])) {
            throw new RuntimeException('Both sender and recipient must sign before completion.');
        }

        if ((string) $handover->recipient_id !== (string) $actor->getKey()) {
            throw new RuntimeException('Only the recipient may complete the handover.');
        }

        return DB::transaction(function () use ($handover, $actor) {
            $handover->update([
                'status' => HandoverStatus::COMPLETED->value,
                'completed_at' => now(),
            ]);

            $this->timeline->log(
                $handover,
                TimelineEventType::HANDOVER_COMPLETED,
                'Handover completed',
                $actor instanceof BranchManager ? $actor : null,
            );

            $fresh = $handover->fresh();
            $this->broadcaster->statusChanged($fresh);

            return $fresh;
        });
    }

    public function signatureFor(Handover $handover, HandoverSignatureRole $role): ?HandoverSignature
    {
        return $handover->signatures()->where('role', $role->value)->first();
    }

    public function items(Handover $handover): Collection
    {
        return $handover->items()->orderBy('zone_id')->orderBy('asset_name_snapshot')->get();
    }

    public function progressItems(Handover $handover): array
    {
        $items = $this->items($handover);
        $total = $items->count();
        $inspected = $items->whereNotNull('recipient_inspection')->count();
        $totalPercent = $total > 0 ? (int) round(($inspected / $total) * 100) : 0;

        $progress = [];
        foreach ($items as $idx => $item) {
            $progress[] = [
                'label' => (string) $item->asset_name_snapshot,
                'percent' => $item->recipient_inspection !== null ? 100 : 0,
            ];
        }
        $progress[] = ['label' => 'Total', 'percent' => $totalPercent];

        return $progress;
    }

    public function timeSpent(Handover $handover): string
    {
        $start = $handover->started_at ?? $handover->created_at;
        $end = $handover->completed_at ?? now();
        if (! $start) {
            return '0:00:00';
        }

        $seconds = max(0, $end->diffInSeconds($start));
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    public function durationLabel(Handover $handover): string
    {
        $start = $handover->started_at ?? $handover->created_at;
        $end = $handover->completed_at ?? now();
        if (! $start) {
            return '0m';
        }

        $seconds = max(0, $end->diffInSeconds($start));
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return $h.'h '.$m.'m';
        }

        return $m.'m';
    }

    public function ageLabel(?Carbon $acquiredAt): string
    {
        if (! $acquiredAt) {
            return '0 Months';
        }

        $months = (int) $acquiredAt->diffInMonths(now());

        return $months.' Month'.($months === 1 ? '' : 's');
    }

    private function guardCanInspect(Handover $handover): void
    {
        if ($handover->status === HandoverStatus::COMPLETED) {
            throw new RuntimeException('Handover is already completed.');
        }
    }

    private function generateSessionCode(): string
    {
        $date = now()->format('ymd');
        $rand = strtoupper(Str::random(4));

        return "HSN-{$date}-{$rand}";
    }
}
