<?php

namespace Modules\FixedAssets\Services;

use Modules\FixedAssets\Enums\HandoverSignatureRole;
use Modules\FixedAssets\Enums\RecipientInspectionResult;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Models\HandoverSignature;

class HandoverSummaryService
{
    public function __construct(
        private readonly HandoverService $handover,
        private readonly HandoverRecipientResolver $recipientResolver,
    ) {}

    public function payload(Handover $handover): array
    {
        $items = $handover->items()->get();
        $total = $items->count();

        $accepted = $items->where('recipient_inspection', RecipientInspectionResult::EXCELLENT)->count();
        $note = $items->where('recipient_inspection', RecipientInspectionResult::NEED_ATTENTION)->count();
        $rejected = $items->where('recipient_inspection', RecipientInspectionResult::PROBLEM)->count();
        $photoTaken = $items->whereNotNull('recipient_photo_path')->count();
        $value = (float) $items->sum(fn ($i) => (float) $i->value_snapshot);
        $acceptedPct = $total > 0 ? (int) round(($accepted / $total) * 100) : 0;

        $discrepancies = [];
        foreach ($items->where('recipient_inspection', RecipientInspectionResult::PROBLEM) as $item) {
            $discrepancies[] = [
                'assetId' => (string) $item->asset_id,
                'assetName' => (string) $item->asset_name_snapshot,
                'assetCode' => (string) $item->asset_code_snapshot,
                'assetImageUrl' => $item->asset_image_snapshot ? asset('storage/'.$item->asset_image_snapshot) : '',
                'zoneName' => (string) ($item->zone_name_snapshot ?? ''),
            ];
        }

        $senderSignature = $this->handover->signatureFor($handover, HandoverSignatureRole::SENDER);
        $receiverSignature = $this->handover->signatureFor($handover, HandoverSignatureRole::RECEIVER);

        $senderName = $senderSignature?->signed_by_name_snapshot
            ?? (string) ($handover->sender?->name ?? '');
        $receiver = $handover->recipient()->first();
        $receiverName = $receiverSignature?->signed_by_name_snapshot
            ?? $this->recipientResolver->displayName($receiver);

        return [
            'sessionId' => (string) $handover->id,
            'status' => $handover->status?->value,
            'date' => optional($handover->started_at)->toDateString() ?? '',
            'duration' => $this->handover->durationLabel($handover),
            'assetsCount' => $total,
            'value' => $value,
            'acceptedCount' => $accepted,
            'acceptedPercentage' => $acceptedPct,
            'noteCount' => $note,
            'rejectedCount' => $rejected,
            'photoTakenCount' => $photoTaken,
            'recordedDiscrepancies' => $discrepancies,
            'sender' => [
                'name' => $senderName,
                'isSigned' => (bool) $senderSignature,
            ],
            'senderSignedAt' => $this->formatSignedAt($senderSignature),
            'receiver' => [
                'name' => $receiverName,
                'isSigned' => (bool) $receiverSignature,
            ],
            'receiverSignedAt' => $this->formatSignedAt($receiverSignature),
        ];
    }

    public function signatureStatePayload(Handover $handover): array
    {
        $handover->loadMissing(['items', 'zoneApprovals', 'signatures']);

        $senderSignature = $this->handover->signatureFor($handover, HandoverSignatureRole::SENDER);
        $receiverSignature = $this->handover->signatureFor($handover, HandoverSignatureRole::RECEIVER);

        $senderName = $senderSignature?->signed_by_name_snapshot
            ?? (string) ($handover->sender?->name ?? '');
        $receiver = $handover->recipient()->first();
        $receiverName = $receiverSignature?->signed_by_name_snapshot
            ?? $this->recipientResolver->displayName($receiver);

        $items = $handover->items;
        $totalItems = $items->count();
        $inspectedItems = $items->whereNotNull('recipient_inspection')->count();

        $zoneIds = $items->pluck('zone_id')->filter()->unique();
        $totalZones = $zoneIds->count();
        $approvedZones = $handover->zoneApprovals->whereNotNull('approved_at')->count();

        $itemsRatio = $totalItems > 0 ? $inspectedItems / $totalItems : 0.0;
        $zonesRatio = $totalZones > 0 ? min(1.0, $approvedZones / $totalZones) : 0.0;
        $sigCount = ((int) (bool) $senderSignature) + ((int) (bool) $receiverSignature);
        $sigRatio = $sigCount / 2;

        $assetsReviewPercent = (int) round($itemsRatio * 100);
        $zoneValidationPercent = (int) round($zonesRatio * 100);
        $signatureStepPercent = (int) round($sigRatio * 100);

        $completedPercent = (int) round(($itemsRatio * 25) + ($zonesRatio * 25) + ($sigRatio * 50));
        $remainingPercent = max(0, 100 - $completedPercent);

        return [
            'progressItems' => [
                ['label' => 'Assets Review', 'percent' => $assetsReviewPercent],
                ['label' => 'Zone Validation', 'percent' => $zoneValidationPercent],
                ['label' => 'Signature Step', 'percent' => $signatureStepPercent],
            ],
            'timeSpent' => $this->handover->timeSpent($handover),
            'remainingPercent' => $remainingPercent,
            'sender' => [
                'name' => $senderName,
                'isSigned' => (bool) $senderSignature,
            ],
            'receiver' => [
                'name' => $receiverName,
                'isSigned' => (bool) $receiverSignature,
            ],
        ];
    }

    private function formatSignedAt(?HandoverSignature $signature): ?string
    {
        return $signature?->signed_at?->format('Y-m-d H:i:s');
    }
}
