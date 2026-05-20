<?php

namespace Modules\Cashier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class CashierDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $branch = $this->branch;
        $openingFormatted = $this->formatBranchHours($branch);
        $googleMapsUrl = null;
        if ($branch && $branch->lat && $branch->lng) {
            $googleMapsUrl = 'https://www.google.com/maps?q='.(float) $branch->lat.','.(float) $branch->lng;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image_url,
            'position' => 'Cashier',
            'account_created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'created_by_branch_manager' => $this->when($this->relationLoaded('creator'), fn () => [
                'id' => $this->creator?->id,
                'name' => $this->creator?->name,
            ]),

            'branch' => $this->when($branch, [
                'id' => $branch->id,
                'name' => $branch->name,
                'location' => $branch->location ?? null,
                'lat' => $branch->lat ? (float) $branch->lat : null,
                'lng' => $branch->lng ? (float) $branch->lng : null,
                'image' => $branch->image ? asset('storage/'.$branch->image) : null,
                'opening_hours' => $openingFormatted,
                'google_maps_url' => $googleMapsUrl,
            ]),

            'status' => [
                'value' => $this->status,
            ],

            'created_by' => [
                'id' => $this->creator->id ?? null,
                'name' => $this->creator->name ?? null,
            ],

            'shifts' => [
                'total' => $this->getTotalShiftsCount(),
                'completed' => $this->getCompletedShiftsCount(),
                'current' => $this->when($this->getCurrentShift(), function () {
                    $shift = $this->getCurrentShift();

                    return [
                        'id' => $shift->id,
                        'shift_name' => $shift->shift->name,
                        'start_time' => $shift->actual_start_time?->format('H:i'),
                        'status' => $shift->status->label(),
                    ];
                }),
                'next' => $this->when($this->getNextShift(), function () {
                    $shift = $this->getNextShift();

                    return [
                        'id' => $shift->id,
                        'shift_name' => $shift->shift->name,
                        'shift_date' => $shift->shift_date->format('Y-m-d'),
                        'start_time' => $shift->shift->start_time,
                    ];
                }),
            ],

            'statistics' => [
                'total_sales' => (float) $this->getTotalSales(),
                'total_variance' => (float) $this->getTotalVariance(),
                'completed_shifts' => $this->getCompletedShiftsCount(),
            ],

            'timestamps' => [
                'activated_at' => $this->activated_at?->format('Y-m-d H:i:s'),
                'deactivated_at' => $this->deactivated_at?->format('Y-m-d H:i:s'),
                'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            ],
        ];
    }

    private function formatBranchHours($branch): ?string
    {
        if (! $branch) {
            return null;
        }
        $open = $branch->opening_hours instanceof \Illuminate\Support\Carbon
            ? $branch->opening_hours->format('g:i A')
            : null;
        $close = $branch->closing_hours instanceof \Illuminate\Support\Carbon
            ? $branch->closing_hours->format('g:i A')
            : null;
        if ($open && $close) {
            return "Mon–Fri / {$open} – {$close}";
        }

        return is_string($branch->opening_hours ?? null)
            ? $branch->opening_hours
            : (($open && $close) ? "{$open} – {$close}" : null);
    }
}
