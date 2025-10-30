<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CashierShiftCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return [
            'data' => CashierShiftResource::collection($this->collection),
        ];
    }

    public function with($request)
    {
        $shifts = $this->collection;

        return [
            'meta' => [
                'total' => $shifts->count(),
                'date_range' => [
                    'from' => now()->format('Y-m-d'),
                    'to' => now()->addMonth()->format('Y-m-d'),
                ],
                'next_shift' => $shifts->first() ? [
                    'id' => $shifts->first()->id,
                    'date' => $shifts->first()->shift_date->format('Y-m-d'),
                    'time' => $shifts->first()->shift->start_time,
                    'cashier' => $shifts->first()->cashier->name,
                ] : null,
            ],
        ];
    }
}
