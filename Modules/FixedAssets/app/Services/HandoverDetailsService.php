<?php

namespace Modules\FixedAssets\Services;

use Modules\FixedAssets\Enums\HandoverStatus;
use Modules\FixedAssets\Models\Handover;

class HandoverDetailsService
{
    public function __construct(
        private readonly HandoverService $handover,
        private readonly HandoverSummaryService $summary,
        private readonly HandoverRecipientResolver $recipientResolver,
        private readonly HandoverQrCodeService $qr,
    ) {}

    public function payload(Handover $handover): array
    {
        $handover->loadMissing(['items', 'sender', 'timelines', 'signatures']);

        $recipient = $handover->recipient()->first();
        $recipientName = $this->recipientResolver->displayName($recipient);
        $recipientImage = $this->recipientResolver->imagePath($recipient);

        $zonesAgg = $handover->items
            ->groupBy('zone_id')
            ->map(fn ($group) => [
                'zoneName' => (string) ($group->first()->zone_name_snapshot ?? ''),
                'assetsCountHandedOver' => $group->count(),
            ])
            ->values()
            ->all();

        $initiated = [
            'qrCodeImageUrl' => $this->qr->url($handover->qr_code_image_path),
            'sessionCode' => (string) $handover->session_code,
            'receiver' => [
                'receiverName' => $recipientName,
                'receiverImageUrl' => $recipientImage ? asset('storage/'.$recipientImage) : '',
                'receiverNumber' => $this->recipientResolver->phone($recipient),
            ],
            'zones' => $zonesAgg,
            'note' => (string) $handover->note,
            'notifications' => (array) ($handover->sent_invitations ?? []),
        ];

        $completed = null;
        if (in_array($handover->status, [HandoverStatus::PENDING, HandoverStatus::COMPLETED], true)) {
            $summary = $this->summary->payload($handover);
            $completed = [
                'date' => $summary['date'],
                'duration' => $summary['duration'],
                'assetsCount' => $summary['assetsCount'],
                'value' => $summary['value'],
                'acceptedCount' => $summary['acceptedCount'],
                'acceptedPercentage' => $summary['acceptedPercentage'],
                'noteCount' => $summary['noteCount'],
                'rejectedCount' => $summary['rejectedCount'],
                'photoTakenCount' => $summary['photoTakenCount'],
                'recordedDiscrepancies' => $summary['recordedDiscrepancies'],
                'sender' => $summary['sender'],
                'senderSignedAt' => $summary['senderSignedAt'],
                'receiver' => $summary['receiver'],
                'receiverSignedAt' => $summary['receiverSignedAt'],
            ];
        }

        $viewerId = (string) (auth()->user()?->getKey() ?? '');
        $viewerType = auth()->user()?->getMorphClass();
        $type = (string) $handover->sender_id === $viewerId
            ? 'sender'
            : (((string) $handover->recipient_id === $viewerId && $handover->recipient_type === $viewerType) ? 'receiver' : 'sender');

        $deductionItems = $handover->items
            ->filter(fn ($it) => (bool) ($it->is_deducted ?? false))
            ->map(function ($it) {
                $raw = is_array($it->deduction) ? $it->deduction : [];

                return [
                    'id' => (string) $it->id,
                    'assetId' => (string) $it->asset_id,
                    'assetName' => (string) ($it->asset_name_snapshot ?? ''),
                    'isDeducted' => true,
                    'deduction' => [
                        'employee_name' => (string) ($raw['employee_name'] ?? ''),
                        'amount' => (float) ($raw['amount'] ?? 0),
                        'reason' => (string) ($raw['reason'] ?? ''),
                        'note' => (string) ($raw['note'] ?? ''),
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'handoverId' => (string) $handover->id,
            'type' => $type,
            'status' => $handover->status?->value,
            'initiatedDetails' => $initiated,
            'recipientName' => $recipientName,
            'completedDetails' => $completed,
            'itemsDeductionStatus' => $deductionItems,
            'timeLine' => [
                'timelines' => $handover->timelines
                    ->sortBy('occurred_at')
                    ->values()
                    ->map(fn ($t) => [
                        'id' => (string) $t->id,
                        'event_type' => $t->event_type?->value ?? '',
                        'name' => (string) $t->name,
                        'image' => $t->actor_image_path ? asset('storage/'.$t->actor_image_path) : '',
                        'occurred_at' => $t->occurred_at?->toIso8601String() ?? '',
                    ])
                    ->all(),
            ],
        ];
    }

    public function joinPayload(Handover $handover): array
    {
        $handover->loadMissing(['items', 'sender', 'branch']);

        $totalZones = $handover->items->pluck('zone_id')->filter()->unique()->count();
        $totalAssets = $handover->items->count();
        $totalValue = (float) $handover->items->sum(fn ($i) => (float) $i->value_snapshot);

        $zones = [];
        foreach ($handover->items->groupBy('zone_id') as $zoneId => $group) {
            if (! $zoneId) {
                continue;
            }
            $assetsPayload = [];
            foreach ($group as $item) {
                $assetsPayload[] = [
                    'assetId' => (string) $item->asset_id,
                    'assetName' => (string) $item->asset_name_snapshot,
                    'assetImageURL' => $item->asset_image_snapshot ? asset('storage/'.$item->asset_image_snapshot) : '',
                    'totalQuantityInZone' => (int) $item->current_qty,
                    'totalExcellentConditionQuantityInZone' => 0,
                    'totalNeedAttentionConditionQuantityInZone' => 0,
                    'totalProblemConditionQuantityInZone' => 0,
                ];
            }
            $zones[] = [
                'zoneID' => (string) $zoneId,
                'zoneName' => (string) ($group->first()->zone_name_snapshot ?? ''),
                'assetsInZone' => $assetsPayload,
            ];
        }

        $sender = $handover->sender;
        $branchName = (string) ($handover->branch?->name ?? '');

        return [
            'sessionId' => (string) $handover->id,
            'sessionCode' => (string) $handover->session_code,
            'sessionImageUrl' => $this->qr->url($handover->qr_code_image_path),
            'handoverByName' => (string) ($sender?->name ?? ''),
            'handoverByImageUrl' => $sender?->image ? asset('storage/'.$sender->image) : '',
            'branchName' => $branchName,
            'sessionDate' => $handover->started_at?->toIso8601String() ?? '',
            'totalZones' => $totalZones,
            'totalAssets' => $totalAssets,
            'totalValue' => $totalValue,
            'note' => (string) $handover->note,
            'includedZones' => $zones,
        ];
    }
}
