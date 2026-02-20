<?php

namespace Modules\RecurringOrder\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\RecurringOrder\Enums\OrderSourceType;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;

/**
 * List item for 3.1.2.6.1, 3.1.2.6.2, 3.1.2.6.3
 */
class RecurringOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->sourceable;
        $typeValue = $this->order_source_type?->value ?? $this->order_source_type;
        $typeLabel = ($typeValue === OrderSourceType::DIRECT_SUPPLIER->value || $typeValue === 'direct_supplier')
            ? 'Via Direct Supplier'
            : 'Via Purchasing Officer';

        $statusLabel = match ($this->status?->value) {
            RecurringOrderStatus::GENERATED->value => 'Generated',
            RecurringOrderStatus::IN_PROGRESS->value => 'In Progress',
            RecurringOrderStatus::PENDING->value => 'Pending',
            RecurringOrderStatus::PAUSED->value => 'Paused',
            default => (string) $this->status?->label(),
        };

        return [
            'id' => $this->id,
            'order_name' => $this->order_name,
            'type' => $typeLabel,
            'type_value' => $typeValue,
            'status' => $statusLabel,
            'status_value' => $this->status?->value,
            'next_schedule_date' => $this->next_run_at?->format('Y-m-d'),
        ];
    }
}
