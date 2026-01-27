<?php

namespace Modules\Cashier\Transformers;

use Illuminate\Http\Request;

class AvailableForShiftResource extends CashierResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'is_available' => true,
            'disabled' => false,
            'reason_disabled' => null,
        ]);
    }
}
