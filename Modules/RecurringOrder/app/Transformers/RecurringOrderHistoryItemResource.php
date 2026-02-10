<?php

namespace Modules\RecurringOrder\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Enums\OrderStatus;

class RecurringOrderHistoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->sourceable;
        $name = $source ? ($source->name ?? $source->company_name ?? '') : '';
        $image = null;
        if ($source) {
            $image = $source->image_url ?? $source->image ?? null;
            if ($image && !str_starts_with((string) $image, 'http')) {
                $image = asset('storage/' . $image);
            }
        }
        $orderStatus = $this->status === OrderStatus::CLOSED ? 'Completed' : 'Canceled';
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'supplier_or_purchasing_officer_name' => $name,
            'image' => $image,
            'order_status' => $orderStatus,
        ];
    }
}
