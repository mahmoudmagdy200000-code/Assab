<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\HandoverSignatureRole;
use Modules\FixedAssets\Models\MajorDiscrepancyRequest;
use Modules\FixedAssets\Transformers\TimelineItemResource;

class BrandOwnerFixedAssetsHandoverReportDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var MajorDiscrepancyRequest $r */
        $r = $this->resource;
        $handover = $r->handover;
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

        $assets = $rejectedRequests->map(function (MajorDiscrepancyRequest $m) {
            $hi = $m->handoverItem;

            return [
                'image_url' => $hi?->asset_image_snapshot
                    ? asset('storage/'.$hi->asset_image_snapshot)
                    : '',
                'name' => (string) ($hi?->asset_name_snapshot ?? ''),
                'subtitle' => (string) ($hi?->asset_type_name_snapshot ?? ''),
                'affected_value' => $hi?->value_snapshot !== null
                    ? (string) $hi->value_snapshot
                    : '-',
                'evidence_caption' => (string) ($hi?->recipient_note ?? ''),
                'evidence_image_url' => $hi?->recipient_photo_path
                    ? asset('storage/'.$hi->recipient_photo_path)
                    : '',
            ];
        })->values()->all();

        $totalAffected = $rejectedRequests->sum(fn (MajorDiscrepancyRequest $m) => (float) ($m->handoverItem?->value_snapshot ?? 0));
        $totalHandoverValue = (float) $items->sum(fn ($it) => (float) ($it->value_snapshot ?? 0));
        $impactRatio = $totalHandoverValue > 0
            ? round(($totalAffected / $totalHandoverValue) * 100, 2).'%'
            : '-';

        $start = $handover?->started_at;
        $end = $handover?->completed_at;
        $duration = ($start && $end) ? gmdate('H:i:s', max(0, $end->diffInSeconds($start))) : '-';

        return [
            'id' => (string) $r->id,
            'status' => $r->status?->value ?? 'pending',
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
            'assets' => $assets,
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
                    'note' => '',
                    'image_url' => $receiver?->signature_image_path
                        ? asset('storage/'.$receiver->signature_image_path)
                        : '',
                ],
            ],
            'financial_analysis' => [
                'total_affected_value' => $totalAffected > 0 ? (string) $totalAffected : '-',
                'impact_ratio' => $impactRatio,
                'employee_record' => (string) ($r->employee_responsible ?? ''),
            ],
            'employee_responsible' => (string) ($r->employee_responsible ?? ''),
            'warning_note' => (string) ($r->warning_note ?? ''),
            'salary_deduction_amount' => $r->salary_deduction_amount !== null
                ? (string) $r->salary_deduction_amount
                : '',
            'salary_deduction_reason' => (string) ($r->salary_deduction_reason ?? ''),
            'approved_on' => $r->bo_decided_at?->toIso8601String() ?? '',
            'cancellation' => $r->cancellation,
            'timelines' => TimelineItemResource::collection($r->timelines ?? collect())->resolve(),
        ];
    }
}
