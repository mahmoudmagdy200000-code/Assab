<?php

namespace Modules\FixedAssets\Services;

use Modules\FixedAssets\Enums\RecipientInspectionResult;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Models\HandoverItem;

class HandoverPreviewService
{
    public function __construct(
        private readonly HandoverService $handover,
    ) {}

    public function payload(Handover $handover, ?string $viewerId = null): array
    {
        $items = $handover->items()->get();
        $total = $items->count();

        $accepted = $items->where('recipient_inspection', RecipientInspectionResult::EXCELLENT);
        $noted = $items->where('recipient_inspection', RecipientInspectionResult::NEED_ATTENTION);
        $rejected = $items->where('recipient_inspection', RecipientInspectionResult::PROBLEM);

        $progress = $this->handover->progressItems($handover);
        $inspectedCount = $accepted->count() + $noted->count() + $rejected->count();
        $remainingPercent = $total > 0 ? (int) round((($total - $inspectedCount) / $total) * 100) : 100;

        $iAmSigned = false;
        if ($viewerId !== null) {
            $iAmSigned = $handover->signatures()
                ->where('signed_by_id', $viewerId)
                ->exists();
        }

        return [
            'sessionId' => (string) $handover->id,
            'iAmSigned' => $iAmSigned,
            'progressItems' => $progress,
            'timeSpent' => $this->handover->timeSpent($handover),
            'remainingPercent' => $remainingPercent,
            'totalAssets' => $total,
            'acceptedAssets' => $accepted->count(),
            'noteAssets' => $noted->count(),
            'rejectedAssets' => $rejected->count(),
            'acceptedList' => $this->mapAssetList($accepted, RecipientInspectionResult::EXCELLENT),
            'noteList' => $this->mapAssetList($noted, RecipientInspectionResult::NEED_ATTENTION),
            'rejectedList' => $this->mapAssetList($rejected, RecipientInspectionResult::PROBLEM),
        ];
    }

    private function mapAssetList($items, RecipientInspectionResult $result): array
    {
        $out = [];
        foreach ($items as $item) {
            /** @var HandoverItem $item */
            $out[] = [
                'assetId' => (string) $item->asset_id,
                'assetName' => (string) $item->asset_name_snapshot,
                'assetCode' => (string) $item->asset_code_snapshot,
                'assetImageUrl' => $item->asset_image_snapshot ? asset('storage/'.$item->asset_image_snapshot) : '',
                'zoneName' => (string) ($item->zone_name_snapshot ?? ''),
                'typeName' => (string) ($item->asset_type_name_snapshot ?? ''),
                'value' => (float) $item->value_snapshot,
                'age' => $this->handover->ageLabel($item->acquired_at_snapshot),
                'handoverDescription' => $this->describe($result, $item),
            ];
        }

        return $out;
    }

    private function describe(RecipientInspectionResult $result, HandoverItem $item): string
    {
        $label = $result->label();
        $percent = match ($result) {
            RecipientInspectionResult::EXCELLENT => '100%',
            RecipientInspectionResult::NEED_ATTENTION => '80%',
            RecipientInspectionResult::PROBLEM => '0%',
        };

        return "{$label} | {$percent} | Weekly";
    }
}
