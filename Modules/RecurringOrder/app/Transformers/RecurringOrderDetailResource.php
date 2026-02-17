<?php

namespace Modules\RecurringOrder\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\RecurringOrder\Enums\OrderSourceType;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Enums\RepeatFrequency;
use Modules\RecurringOrder\Services\RecurringOrderService;

/**
 * Full detail for 3.1.2.6.1.2, 3.1.2.6.2.1, 3.1.2.6.3.1
 */
class RecurringOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->sourceable;
        $sourceName = $source ? ($source->name ?? $source->company_name ?? '') : '';
        $sourceImage = $source && isset($source->image_url) ? $source->image_url : ($source->image ?? null);
        if ($sourceImage && !str_starts_with((string) $sourceImage, 'http')) {
            $sourceImage = asset('storage/' . $sourceImage);
        }
        $typeValue = $this->order_source_type?->value ?? $this->order_source_type;
        $typeLabel = ($typeValue === OrderSourceType::DIRECT_SUPPLIER->value || $typeValue === 'direct_supplier')
            ? 'Direct Supplier'
            : 'Purchasing Officer';

        $schedulingTime = $this->scheduling_time_am ?? $this->scheduling_time_pm;
        $schedulingTimeStr = $schedulingTime ? \Carbon\Carbon::parse($schedulingTime)->format('g:i A') : null;

        $statusSection = $this->buildStatusSection($schedulingTimeStr);
        $inspectionSummary = [
            'order_name' => $this->order_name,
            'direct_supplier_or_purchasing_officer_name' => $sourceName,
            'repeat_frequency' => $this->buildRepeatFrequencyDisplay(),
            'next_order_scheduling' => $this->next_run_at?->format('Y-m-d'),
            'scheduling_time' => $schedulingTimeStr,
        ];
        $detailsSection = [
            'inspection_summary' => [
                'order_name' => $this->order_name,
                'direct_supplier_or_purchasing_officer_name' => $sourceName,
                'start_date' => $this->start_date?->format('Y-m-d'),
                'scheduling_time' => $schedulingTimeStr,
                'end_date' => $this->end_date?->format('Y-m-d'),
            ],
            'message' => $this->message,
            'notification_channels' => $this->notification_channels ?? [],
            'items_summary' => RecurringOrderItemResource::collection($this->whenLoaded('items')),
            'scheduling_settings' => $this->buildSchedulingSettings(),
        ];
        $includeAvailability = $request->get('include_item_availability') || $this->resource->getAttribute('include_item_availability');
        $itemCountAndAvailability = $this->when(
            $includeAvailability,
            $this->buildItemCountAndAvailability()
        );

        $availableActions = $this->getAvailableActions();

        return [
            'id' => $this->id,
            'status_section' => $statusSection,
            'inspection_summary' => $inspectionSummary,
            'item_count_and_availability' => $itemCountAndAvailability,
            'details' => $detailsSection,
            'history' => $this->resource->getAttribute('history_data') ?? [],
            'available_actions' => $availableActions,
        ];
    }

    private function buildStatusSection(?string $schedulingTimeStr): array
    {
        $statusValue = $this->status?->value;
        if ($statusValue === RecurringOrderStatus::GENERATED->value) {
            return [
                'status' => 'Generated',
                'status_value' => 'generated',
                'message' => 'Order has been created and is awaiting confirmation from the supplier/officer.',
            ];
        }
        if ($statusValue === RecurringOrderStatus::IN_PROGRESS->value) {
            return [
                'status' => 'In Progress',
                'status_value' => 'in_progress',
                'message' => 'Order has been confirmed and is currently being processed.',
            ];
        }
        if ($statusValue === RecurringOrderStatus::PENDING->value) {
            $service = app(RecurringOrderService::class);
            $message = $service->getNextOrderMessage($this->resource) ?? 'Your next order is scheduled.';
            return [
                'status' => 'Pending',
                'status_value' => 'pending',
                'message' => $message,
            ];
        }
        if ($statusValue === RecurringOrderStatus::PAUSED->value) {
            return [
                'status' => 'Paused',
                'status_value' => 'paused',
                'message' => 'This recurring order is paused.',
            ];
        }
        return [
            'status' => $this->status?->label(),
            'status_value' => $statusValue,
            'message' => '',
        ];
    }

    private function buildRepeatFrequencyDisplay(): string
    {
        $freq = $this->repeat_frequency?->value ?? '';
        $config = $this->repeat_config ?? [];
        if ($freq === RepeatFrequency::WEEKLY->value) {
            $days = $config['repeat_days'] ?? [];
            $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $list = array_map(fn ($d) => $names[$d] ?? (string) $d, $days);
            return 'Weekly: ' . implode(', ', $list);
        }
        if ($freq === RepeatFrequency::MONTHLY->value) {
            $type = $config['repeat_type'] ?? 'by_date';
            if ($type === 'by_date') {
                $dates = $config['dates'] ?? [];
                return 'Monthly (by date): ' . implode(', ', $dates);
            }
            $occ = $config['occurrence'] ?? 1;
            $dow = $config['day_of_week'] ?? 0;
            $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $ord = ['', 'first', 'second', 'third', 'fourth', 'fifth'];
            return 'Monthly: ' . ($ord[$occ] ?? $occ) . ' ' . ($names[$dow] ?? '');
        }
        if ($freq === RepeatFrequency::BASED_ON_INVENTORY->value) {
            $ratio = $config['level_ratio'] ?? $config['custom_threshold'] ?? 28;
            return 'Based on Inventory: ' . (is_numeric($ratio) ? "{$ratio}%" : $ratio);
        }
        return $this->repeat_frequency?->label() ?? '';
    }

    private function buildSchedulingSettings(): array
    {
        $config = $this->repeat_config ?? [];
        $freq = $this->repeat_frequency?->value ?? '';

        $repeatFrequency = [];
        if ($freq === RepeatFrequency::WEEKLY->value) {
            $days = $config['repeat_days'] ?? [];
            $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $repeatFrequency['weekly'] = ['repeat_days' => array_values(array_map(fn ($d) => $names[$d] ?? $d, $days))];
        } elseif ($freq === RepeatFrequency::MONTHLY->value) {
            $type = $config['repeat_type'] ?? 'by_date';
            if ($type === 'by_date') {
                $repeatFrequency['monthly'] = ['by_date' => $config['dates'] ?? []];
            } else {
                $occ = $config['occurrence'] ?? 1;
                $dow = $config['day_of_week'] ?? 0;
                $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $ord = ['', '1st', '2nd', '3rd', '4th', '5th'];
                $repeatFrequency['monthly'] = ['by_pattern' => ($ord[$occ] ?? $occ) . ' ' . ($names[$dow] ?? '')];
            }
        } elseif ($freq === RepeatFrequency::BASED_ON_INVENTORY->value) {
            $ratio = $config['level_ratio'] ?? $config['custom_threshold'] ?? null;
            $repeatFrequency['based_on_inventory'] = ['level_ratio' => $ratio];
        }

        return [
            'repeat_frequency' => $repeatFrequency,
            'notifications' => $this->notification_options ?? [],
            'smart_settings' => $this->smart_settings ?? [],
        ];
    }

    private function buildItemCountAndAvailability(): array
    {
        $branchId = $this->branch_id;
        $items = $this->items ?? collect();
        $out = [];
        foreach ($items as $roItem) {
            $itemId = $roItem->item_id ?? $roItem->item?->id;
            $quantityPurchased = (float) $roItem->quantity;
            $quantityInStock = 0;
            if ($itemId && $branchId) {
                $inv = \Modules\Purchase\Models\BranchInventory::where('branch_id', $branchId)
                    ->where('item_id', $itemId)
                    ->first();
                if ($inv) {
                    $quantityInStock = (float) ($inv->actual_available ?? (($inv->available_quantity ?? 0) - ($inv->reserved_quantity ?? 0)));
                }
            }
            $out[] = [
                'item_name' => $roItem->item_name ?? $roItem->item?->name,
                'item_logo' => $roItem->item_logo_url ?? $roItem->item?->logo_url ?? null,
                'quantity_purchased' => $quantityPurchased,
                'quantity_in_stock' => $quantityInStock,
            ];
        }
        return $out;
    }

    private function getAvailableActions(): array
    {
        $status = $this->status?->value;
        $actions = [];
        if ($status === RecurringOrderStatus::GENERATED->value || $status === RecurringOrderStatus::IN_PROGRESS->value) {
            $actions[] = 'pause_recurring_order';
            $actions[] = 'update_recurring_order';
            $actions[] = 'delete_recurring_order';
        } elseif ($status === RecurringOrderStatus::PENDING->value) {
            $actions[] = 'pause_recurring_order';
            $actions[] = 'update_recurring_order';
            $actions[] = 'delete_recurring_order';
        } elseif ($status === RecurringOrderStatus::PAUSED->value) {
            $actions[] = 'resume_recurring_order';
            $actions[] = 'update_recurring_order';
            $actions[] = 'delete_recurring_order';
        }
        return $actions;
    }
}
