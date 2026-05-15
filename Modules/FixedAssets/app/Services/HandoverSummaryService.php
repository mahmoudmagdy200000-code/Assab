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
        $senderSignature = $this->handover->signatureFor($handover, HandoverSignatureRole::SENDER);
        $receiverSignature = $this->handover->signatureFor($handover, HandoverSignatureRole::RECEIVER);

        $senderName = $senderSignature?->signed_by_name_snapshot
            ?? (string) ($handover->sender?->name ?? '');
        $receiver = $handover->recipient()->first();
        $receiverName = $receiverSignature?->signed_by_name_snapshot
            ?? $this->recipientResolver->displayName($receiver);

        return [
            'progressItems' => $this->handover->progressItems($handover),
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
