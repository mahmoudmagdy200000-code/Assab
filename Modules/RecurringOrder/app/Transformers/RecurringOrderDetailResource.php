<?php

namespace Modules\RecurringOrder\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Models\BranchItem;
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
        if ($sourceImage && ! str_starts_with((string) $sourceImage, 'http')) {
            $sourceImage = asset('storage/'.$sourceImage);
        }
        $orderType = $this->order_source_type?->value ?? $this->order_source_type ?? null;

        [$schedulingTimeStr, $schedulingTimeValue, $meridiem] = $this->buildSchedulingTimeAndMeridiem();
        $statusSection = $this->buildStatusSection($schedulingTimeStr);
        $inspectionSummary = [
            'order_name' => $this->order_name,
            'direct_supplier_or_purchasing_officer_name' => $sourceName,
            'repeat_frequency' => $this->buildRepeatFrequencyDisplay(),
            'next_order_scheduling' => $this->next_run_at?->format('Y-m-d'),
            'scheduling_time' => $schedulingTimeValue,
            'meridiem' => $meridiem,
        ];
        $detailsSection = [
            'inspection_summary' => [
                'order_name' => $this->order_name,
                'direct_supplier_or_purchasing_officer_name' => $sourceName,
                'start_date' => $this->start_date?->format('Y-m-d'),
                'scheduling_time' => $schedulingTimeValue,
                'meridiem' => $meridiem,
                'end_date' => $this->end_date?->format('Y-m-d'),
            ],
            'message' => $this->message,
            'notification_channels' => $this->notification_channels ?? [],
            'items_summary' => $this->buildItemsSummaryWithEffectiveUnitPrice($request),
            'scheduling_settings' => $this->buildSchedulingSettings(),
        ];
        $includeAvailability = $request->get('include_item_availability') || $this->resource->getAttribute('include_item_availability');
        $itemCountAndAvailability = $this->when(
            $includeAvailability,
            $this->buildItemCountAndAvailability()
        );

        $availableActions = $this->getAvailableActions();
        $frequencySettings = $this->buildFrequencySettings();

        return [
            'id' => $this->id,
            'order_type' => $orderType,
            'status_section' => $statusSection,
            'inspection_summary' => $inspectionSummary,
            'frequency_settings' => $frequencySettings,
            'item_count_and_availability' => $itemCountAndAvailability,
            'details' => $detailsSection,
            'history' => $this->resource->getAttribute('history_data') ?? [],
            'available_actions' => $availableActions,
        ];
    }

    /**
     * Build structured frequency settings for API consumers.
     * - frequency: weekly | monthly | based_on_inventory
     * - end_type: repeat | date
     * - weekly: repeat_days as numbers (Sunday=1 .. Saturday=7)
     * - monthly by_date: repeat_type=by_date, dates as day-of-month numbers
     * - monthly by_pattern: repeat_type=by_pattern, every=occurrence (1-5), day_of_week (1-7)
     */
    private function buildFrequencySettings(): array
    {
        $freq = $this->repeat_frequency?->value ?? '';
        $config = $this->repeat_config ?? [];
        $base = [
            'frequency' => $freq ?: null,
            'end_type' => $this->end_type ?? 'repeat',
        ];

        if ($freq === RepeatFrequency::WEEKLY->value) {
            $days = $config['repeat_days'] ?? [];
            // Store is 0-6 (Sunday=0). API exposes Sunday=1 .. Saturday=7.
            $base['repeat_days'] = array_values(array_map(fn ($d) => (int) $d + 1, $days));

            return $base;
        }

        if ($freq === RepeatFrequency::MONTHLY->value) {
            $type = $config['repeat_type'] ?? 'by_date';
            $base['repeat_type'] = $type;

            if ($type === 'by_date') {
                $base['dates'] = array_map('intval', $config['dates'] ?? []);

                return $base;
            }

            // by_pattern: every = occurrence (1-5), day_of_week as 1-7 (Sunday=1)
            $base['repeat_type'] = 'by_pattern';
            $base['every'] = (int) ($config['occurrence'] ?? 1);
            $dow = (int) ($config['day_of_week'] ?? 0);
            $base['day_of_week'] = $dow + 1; // 0-6 -> 1-7

            return $base;
        }

        if ($freq === RepeatFrequency::BASED_ON_INVENTORY->value) {
            $base['level_ratio'] = $config['level_ratio'] ?? null;
            $base['custom_threshold'] = isset($config['custom_threshold']) ? (int) $config['custom_threshold'] : null;

            return $base;
        }

        return $base;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [display e.g. "2:33 AM", scheduling_time "HH:mm:ss", meridiem "am"|"pm"]
     */
    private function buildSchedulingTimeAndMeridiem(): array
    {
        $time = $this->scheduling_time_am ?? $this->scheduling_time_pm;
        if (! $time) {
            return [null, null, null];
        }
        $carbon = \Carbon\Carbon::parse($time);
        $h = (int) $carbon->format('G');
        $meridiem = $h < 12 ? 'am' : 'pm';
        $schedulingTimeValue = $carbon->format('H:i:s');
        $displayStr = $carbon->format('g:i A');

        return [$displayStr, $schedulingTimeValue, $meridiem];
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

            return 'Weekly: '.implode(', ', $list);
        }
        if ($freq === RepeatFrequency::MONTHLY->value) {
            $type = $config['repeat_type'] ?? 'by_date';
            if ($type === 'by_date') {
                $dates = $config['dates'] ?? [];

                return 'Monthly (by date): '.implode(', ', $dates);
            }
            $occ = $config['occurrence'] ?? 1;
            $dow = $config['day_of_week'] ?? 0;
            $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $ord = ['', 'first', 'second', 'third', 'fourth', 'fifth'];

            return 'Monthly: '.($ord[$occ] ?? $occ).' '.($names[$dow] ?? '');
        }
        if ($freq === RepeatFrequency::BASED_ON_INVENTORY->value) {
            $ratio = $config['level_ratio'] ?? $config['custom_threshold'] ?? 28;

            return 'Based on Inventory: '.(is_numeric($ratio) ? "{$ratio}%" : $ratio);
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
                $repeatFrequency['monthly'] = ['by_pattern' => ($ord[$occ] ?? $occ).' '.($names[$dow] ?? '')];
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

    /**
     * Build items_summary with unit_price fallback: when stored unit_price is 0, use branch item price.
     */
    private function buildItemsSummaryWithEffectiveUnitPrice(Request $request): array
    {
        $items = $this->whenLoaded('items');
        if ($items === null || $items->isEmpty()) {
            return [];
        }
        $branchPrices = BranchItem::where('branch_id', $this->branch_id)
            ->whereIn('item_id', $items->pluck('item_id'))
            ->get()
            ->keyBy('item_id');

        return $items->map(function ($item) use ($request, $branchPrices) {
            $arr = (new RecurringOrderItemResource($item))->toArray($request);
            $stored = (float) $item->unit_price;
            $arr['unit_price'] = $stored > 0 ? $stored : (float) ($branchPrices->get($item->item_id)?->price ?? 0);

            return $arr;
        })->all();
    }

    private function buildItemCountAndAvailability(): array
    {
        $branchId = $this->branch_id;
        $items = $this->items ?? collect();
        $out = [];
        foreach ($items as $roItem) {
            $itemId = $roItem->item_id ?? $roItem->item?->id;
            // Use raw attribute so quantity is never lost (cast/accessor can sometimes return 0)
            $quantityOrdered = $roItem->getRawOriginal('quantity');
            if ($quantityOrdered === null || $quantityOrdered === '') {
                $quantityOrdered = $roItem->quantity;
            }
            $quantityPurchased = (float) $quantityOrdered;
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
