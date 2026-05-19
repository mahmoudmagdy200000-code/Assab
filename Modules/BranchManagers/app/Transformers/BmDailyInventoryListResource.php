<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\InventorySessionStatus;

class BmDailyInventoryListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof InventorySessionStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => $this->id,
            'status' => $this->mapStatus($status),
            'inventory_date' => ($this->inventory_date ?? $this->submitted_at ?? $this->created_at)?->toIso8601String(),
            'start_time' => ($this->start_time ?? $this->submitted_at ?? $this->created_at)?->format('h:i A'),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
        ];
    }

    private function mapStatus(string $value): string
    {
        return match ($value) {
            'pending_your_confirmation', 'pending_your_action', 'pending' => 'pending',
            'rejected' => 'rejected',
            'approved', 'completed' => 'completed',
            default => $value,
        };
    }
}
