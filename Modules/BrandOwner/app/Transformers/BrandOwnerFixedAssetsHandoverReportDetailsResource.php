<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\HandoverSignatureRole;
use Modules\FixedAssets\Models\HandoverItem;
use Modules\FixedAssets\Models\MajorDiscrepancyRequest;
use Modules\FixedAssets\Transformers\TimelineItemResource;

class BrandOwnerFixedAssetsHandoverReportDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var MajorDiscrepancyRequest $r */
        $r = $this->resource;
        $handover = $r->handover;
        /** @var HandoverItem|null $item */
        $item = $r->handoverItem;
        $signatures = $handover?->signatures ?? collect();

        $sender = $signatures->firstWhere('role', HandoverSignatureRole::SENDER);
        $receiver = $signatures->firstWhere('role', HandoverSignatureRole::RECEIVER);

        $items = $handover?->items ?? collect();
        $total = $items->count();
        $rejected = $items->whereNotNull('recipient_inspection')
            ->filter(fn ($it) => $it->recipient_inspection?->value !== 'excellent')
            ->count();
        $successRate = $total > 0 ? round((($total - $rejected) / $total) * 100, 2).'%' : '0%';

        $rejectedRequests = $r->relationLoaded('groupedRequests')
            ? $r->getRelation('groupedRequests')
            : collect([$r]);

        $rejectedItems = $rejectedRequests->map(function (MajorDiscrepancyRequest $m) {
            $hi = $m->handoverItem;

            return [
                'id' => (string) $m->id,
                'handover_item_id' => (string) ($m->handover_item_id ?? ''),
                'asset_id' => (string) ($m->asset_id ?? ''),
                'asset_name' => (string) ($hi?->asset_name_snapshot ?? ''),
                'asset_image_url' => $hi?->asset_image_snapshot
                    ? asset('storage/'.$hi->asset_image_snapshot)
                    : '',
                'affected_value' => $hi?->value_snapshot !== null
                    ? (string) $hi->value_snapshot
                    : '-',
                'recipient_note' => (string) ($hi?->recipient_note ?? ''),
                'evidence_image_url' => $hi?->recipient_photo_path
                    ? asset('storage/'.$hi->recipient_photo_path)
                    : '',
                'status' => $m->status?->value ?? 'pending',
                'employee_responsible' => (string) ($m->employee_responsible ?? ''),
                'warning_note' => (string) ($m->warning_note ?? ''),
                'salary_deduction_amount' => $m->salary_deduction_amount !== null
                    ? (string) $m->salary_deduction_amount
                    : '',
                'salary_deduction_reason' => (string) ($m->salary_deduction_reason ?? ''),
                'rejection_reason' => (string) ($m->rejection_reason ?? ''),
            ];
        })->values()->all();

        $start = $handover?->started_at;
        $end = $handover?->completed_at;
        $duration = ($start && $end) ? gmdate('H:i:s', max(0, $end->diffInSeconds($start))) : '-';

        return [
            'id' => (string) $r->id,
            'status' => $r->status?->value ?? 'pending',
            'approved_on' => $r->bo_decided_at?->toIso8601String() ?? '',
            'cancellation' => $r->cancellation,
            'route_details' => [
                'request_id' => (string) ($handover?->id ?? ''),
                'session_code' => (string) ($handover?->session_code ?? ''),
                'from_branch_name' => (string) ($handover?->branch?->name ?? ''),
                'to_branch_name' => (string) ($handover?->recipient?->branch?->name ?? ''),
                'success_rate' => $successRate,
                'discrepancies' => (string) $rejected,
                'handover_date' => $handover?->completed_at?->toIso8601String()
                    ?? $handover?->started_at?->toIso8601String()
                    ?? '',
                'session_duration' => $duration,
            ],
            'asset_details' => [
                'image_url' => $item?->asset_image_snapshot
                    ? asset('storage/'.$item->asset_image_snapshot)
                    : '',
                'name' => (string) ($item?->asset_name_snapshot ?? ''),
                'subtitle' => (string) ($item?->asset_type_name_snapshot ?? ''),
                'affected_value' => $item?->value_snapshot !== null
                    ? (string) $item->value_snapshot
                    : '-',
                'evidence_caption' => (string) ($item?->recipient_note ?? ''),
                'evidence_image_url' => $item?->recipient_photo_path
                    ? asset('storage/'.$item->recipient_photo_path)
                    : '',
            ],
            'signature_details' => [
                'sender' => [
                    'name' => (string) ($sender?->signed_by_name_snapshot ?? ''),
                    'role_label' => 'Sender',
                    'signed_at' => $sender?->signed_at?->toIso8601String() ?? '',
                    'note' => '',
                    'image_url' => $sender?->signature_image_path
                        ? asset('storage/'.$sender->signature_image_path)
                        : '',
                ],
                'receiver' => [
                    'name' => (string) ($receiver?->signed_by_name_snapshot ?? ''),
                    'role_label' => 'Receiver',
                    'signed_at' => $receiver?->signed_at?->toIso8601String() ?? '',
                    'note' => (string) ($item?->recipient_note ?? ''),
                    'image_url' => $receiver?->signature_image_path
                        ? asset('storage/'.$receiver->signature_image_path)
                        : '',
                ],
            ],
            'financial_analysis' => [
                'total_affected_value' => $item?->value_snapshot !== null
                    ? (string) $item->value_snapshot
                    : '-',
                'impact_ratio' => '-',
                'employee_record' => (string) ($r->employee_responsible ?? ''),
            ],
            'employee_responsible' => (string) ($r->employee_responsible ?? ''),
            'warning_note' => (string) ($r->warning_note ?? ''),
            'salary_deduction_amount' => $r->salary_deduction_amount !== null
                ? (string) $r->salary_deduction_amount
                : '',
            'salary_deduction_reason' => (string) ($r->salary_deduction_reason ?? ''),
            'items' => $rejectedItems,
            'timelines' => TimelineItemResource::collection($r->timelines ?? collect())->resolve(),
        ];
    }
}
