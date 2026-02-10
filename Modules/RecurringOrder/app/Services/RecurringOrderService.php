<?php

namespace Modules\RecurringOrder\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\Item;
use Modules\RecurringOrder\Enums\OrderSourceType;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Enums\RepeatFrequency;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\RecurringOrder\Models\RecurringOrderItem;
use Modules\RecurringOrder\Repositories\RecurringOrderRepository;

class RecurringOrderService
{
    public function __construct(
        private readonly RecurringOrderRepository $repository
    ) {}

    /**
     * List recurring orders – In Progress (Generated or In Progress).
     */
    public function getInProgressList(string $branchId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getInProgressList($branchId, $filters, $perPage);
    }

    /**
     * List recurring orders – Next Scheduling (Pending).
     */
    public function getNextSchedulingList(string $branchId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getNextSchedulingList($branchId, $filters, $perPage);
    }

    /**
     * List recurring orders – Paused.
     */
    public function getPausedList(string $branchId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getPausedList($branchId, $filters, $perPage);
    }

    /**
     * Create a new recurring order and activate it.
     */
    public function create(BranchManager $branchManager, array $data): RecurringOrder
    {
        return DB::transaction(function () use ($branchManager, $data) {
            $sourceableType = $data['order_source_type'] === OrderSourceType::DIRECT_SUPPLIER->value
                ? \Modules\Supplier\Models\Supplier::class
                : BranchManager::class;
            $sourceableId = $data['order_source_type'] === OrderSourceType::DIRECT_SUPPLIER->value
                ? $data['supplier_id']
                : $data['purchasing_officer_id'];

            $schedulingTime = $this->resolveSchedulingTime($data);

            $order = $this->repository->create([
                'branch_id' => $branchManager->branch_id,
                'created_by' => $branchManager->id,
                'order_name' => $data['order_name'],
                'order_source_type' => $data['order_source_type'],
                'status' => RecurringOrderStatus::PENDING,
                'sourceable_type' => $sourceableType,
                'sourceable_id' => $sourceableId,
                'repeat_frequency' => $data['repeat_frequency'],
                'repeat_config' => $data['repeat_config'] ?? null,
                'scheduling_time_am' => $data['scheduling_time_am'] ?? null,
                'scheduling_time_pm' => $data['scheduling_time_pm'] ?? null,
                'notification_options' => $data['notification_options'] ?? [],
                'smart_settings' => $data['smart_settings'] ?? [],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'end_type' => $data['end_type'] ?? 'repeat',
                'next_run_at' => $this->computeNextRunAt($data),
            ]);

            $this->syncItems($order, $data['items'] ?? []);
            return $order->fresh(['items.item', 'sourceable']);
        });
    }

    /**
     * Get recurring order details for Branch Manager (by branch).
     */
    public function getDetails(string $id, string $branchId): ?RecurringOrder
    {
        return $this->repository->findByBranch($id, $branchId, [
            'items.item',
            'sourceable',
            'branch',
            'createdBy',
            'purchaseOrders' => fn ($q) => $q->orderBy('created_at', 'desc')->limit(100),
        ]);
    }

    /**
     * Get history: completed/canceled purchase orders from this recurring order.
     */
    public function getHistory(RecurringOrder $recurringOrder): array
    {
        $orders = $recurringOrder->purchaseOrders()
            ->with(['sourceable', 'items'])
            ->whereIn('status', [
                \Modules\Purchase\Enums\OrderStatus::CLOSED,
                \Modules\Purchase\Enums\OrderStatus::CANCELED,
                \Modules\Purchase\Enums\OrderStatus::CANCELLED_BY_BRANCH,
                \Modules\Purchase\Enums\OrderStatus::CANCELLED_BY_SUPPLIER,
                \Modules\Purchase\Enums\OrderStatus::REJECTED,
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $totalCompleted = $orders->filter(fn ($o) => $o->status === \Modules\Purchase\Enums\OrderStatus::CLOSED)->count();
        $totalCanceled = $orders->count() - $totalCompleted;

        return [
            'total_completed_orders' => $totalCompleted,
            'total_canceled_orders' => $totalCanceled,
            'list' => $orders,
        ];
    }

    /**
     * Pause a recurring order.
     */
    public function pause(RecurringOrder $recurringOrder): RecurringOrder
    {
        $this->repository->update($recurringOrder, [
            'status' => RecurringOrderStatus::PAUSED,
            'next_run_at' => null,
            'paused_at' => now(),
        ]);
        return $recurringOrder->fresh(['items.item', 'sourceable']);
    }

    /**
     * Resume a paused recurring order.
     */
    public function resume(RecurringOrder $recurringOrder): RecurringOrder
    {
        $nextRun = $this->computeNextRunAtFromModel($recurringOrder);
        $this->repository->update($recurringOrder, [
            'status' => RecurringOrderStatus::PENDING,
            'next_run_at' => $nextRun,
            'paused_at' => null,
        ]);
        return $recurringOrder->fresh(['items.item', 'sourceable']);
    }

    /**
     * Update recurring order.
     */
    public function update(RecurringOrder $recurringOrder, array $data): RecurringOrder
    {
        return DB::transaction(function () use ($recurringOrder, $data) {
            $update = array_filter([
                'order_name' => $data['order_name'] ?? null,
                'repeat_config' => $data['repeat_config'] ?? null,
                'scheduling_time_am' => $data['scheduling_time_am'] ?? null,
                'scheduling_time_pm' => $data['scheduling_time_pm'] ?? null,
                'notification_options' => $data['notification_options'] ?? null,
                'smart_settings' => $data['smart_settings'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'end_type' => $data['end_type'] ?? null,
            ], fn ($v) => $v !== null);

            if (!empty($data['items'])) {
                $this->syncItems($recurringOrder, $data['items']);
            }

            if (!empty($update)) {
                $this->repository->update($recurringOrder, $update);
            }

            if ($recurringOrder->status === RecurringOrderStatus::PENDING && !$recurringOrder->paused_at) {
                $recurringOrder->next_run_at = $this->computeNextRunAtFromModel($recurringOrder->fresh());
                $recurringOrder->save();
            }

            return $recurringOrder->fresh(['items.item', 'sourceable']);
        });
    }

    /**
     * Delete recurring order permanently.
     */
    public function delete(RecurringOrder $recurringOrder): bool
    {
        $recurringOrder->items()->delete();
        return $recurringOrder->forceDelete();
    }

    /**
     * Compute next run datetime from recurring order config.
     */
    public function computeNextRunAtFromModel(RecurringOrder $model): ?Carbon
    {
        $data = [
            'repeat_frequency' => $model->repeat_frequency->value,
            'repeat_config' => $model->repeat_config,
            'scheduling_time_am' => $model->scheduling_time_am?->format('H:i'),
            'scheduling_time_pm' => $model->scheduling_time_pm?->format('H:i'),
            'start_date' => $model->start_date?->format('Y-m-d'),
            'end_date' => $model->end_date?->format('Y-m-d'),
        ];
        return $this->computeNextRunAt($data, $model->next_run_at ?? $model->start_date?->startOfDay());
    }

    /**
     * Build "Next Order" status message (e.g. for Pending list).
     */
    public function getNextOrderMessage(RecurringOrder $recurringOrder): ?string
    {
        if ($recurringOrder->status !== RecurringOrderStatus::PENDING) {
            return null;
        }
        if ($recurringOrder->next_run_at === null) {
            return null;
        }
        $config = $recurringOrder->repeat_config ?? [];
        $time = $recurringOrder->scheduling_time_am ?? $recurringOrder->scheduling_time_pm;
        $timeStr = $time ? Carbon::parse($time)->format('g:i A') : '10:00 AM';

        if ($recurringOrder->repeat_frequency === RepeatFrequency::WEEKLY) {
            $days = $config['repeat_days'] ?? [];
            $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $next = $recurringOrder->next_run_at;
            $dayName = $dayNames[(int) $next->format('w')];
            return "Your next order will be automatically generated on {$dayName}, {$next->format('F jS')} at {$timeStr}.";
        }

        if ($recurringOrder->repeat_frequency === RepeatFrequency::MONTHLY) {
            $repeatType = $config['repeat_type'] ?? 'by_date';
            if ($repeatType === 'by_date') {
                $next = $recurringOrder->next_run_at;
                return "Your next order is scheduled for {$next->format('l, F jS, Y')} at {$timeStr}.";
            }
            $occurrence = $config['occurrence'] ?? 1;
            $dayOfWeek = $config['day_of_week'] ?? 0;
            $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $ordinals = ['', 'first', 'second', 'third', 'fourth', 'fifth'];
            $next = $recurringOrder->next_run_at;
            $monthYear = $next->format('F Y');
            $dayName = $dayNames[$dayOfWeek];
            return "Your next order will be generated on the {$ordinals[$occurrence]} {$dayName} of {$monthYear} ({$next->format('l, F jS')})";
        }

        if ($recurringOrder->repeat_frequency === RepeatFrequency::BASED_ON_INVENTORY) {
            $ratio = $config['level_ratio'] ?? $config['custom_threshold'] ?? 28;
            if ($ratio === 'custom' && isset($config['custom_threshold'])) {
                $ratio = $config['custom_threshold'];
            }
            return "Your next order will be triggered when stock levels drop below {$ratio}% of the total available quantity";
        }

        return "Your next order will be automatically generated on {$recurringOrder->next_run_at->format('F j, Y')} at {$timeStr}.";
    }

    private function resolveSchedulingTime(array $data): void
    {
        // Used only for validation; storage is per-field.
    }

    private function computeNextRunAt(array $data, $after = null): ?Carbon
    {
        $after = $after ? Carbon::parse($after) : now()->startOfDay();
        $startDate = isset($data['start_date']) ? Carbon::parse($data['start_date']) : now();
        if ($after->lt($startDate)) {
            $after = $startDate->copy();
        }
        $endDate = !empty($data['end_date']) ? Carbon::parse($data['end_date']) : null;
        $config = $data['repeat_config'] ?? [];
        $frequency = $data['repeat_frequency'] ?? 'weekly';

        $timeStr = $data['scheduling_time_pm'] ?? $data['scheduling_time_am'] ?? '10:00';

        if ($frequency === RepeatFrequency::BASED_ON_INVENTORY->value) {
            return null;
        }

        if ($frequency === RepeatFrequency::WEEKLY->value) {
            $days = $config['repeat_days'] ?? [1];
            $cursor = $after->copy()->startOfDay();
            $cursor->setTimeFromTimeString($timeStr);
            for ($i = 0; $i <= 7 * 2; $i++) {
                if ($cursor->gt($after) && in_array((int) $cursor->format('w'), $days)) {
                    if ($endDate && $cursor->gt($endDate)) {
                        return null;
                    }
                    return $cursor;
                }
                $cursor->addDay();
            }
            return null;
        }

        if ($frequency === RepeatFrequency::MONTHLY->value) {
            $repeatType = $config['repeat_type'] ?? 'by_date';
            if ($repeatType === 'by_date') {
                $dates = $config['dates'] ?? [1];
                $cursor = $after->copy()->startOfDay();
                $cursor->setTimeFromTimeString($timeStr);
                for ($m = 0; $m <= 2; $m++) {
                    $month = $cursor->copy()->startOfMonth()->addMonths($m);
                    foreach ($dates as $d) {
                        $c = $month->copy()->day(min($d, $month->daysInMonth))->setTimeFromTimeString($timeStr);
                        if ($c->gt($after)) {
                            if ($endDate && $c->gt($endDate)) {
                                return null;
                            }
                            return $c;
                        }
                    }
                }
            } else {
                $occurrence = (int) ($config['occurrence'] ?? 1);
                $dayOfWeek = (int) ($config['day_of_week'] ?? 1);
                $cursor = $after->copy()->startOfMonth()->setTimeFromTimeString($timeStr);
                for ($m = 0; $m <= 2; $m++) {
                    $month = $after->copy()->startOfMonth()->addMonths($m);
                    $nth = 0;
                    for ($d = 1; $d <= $month->daysInMonth; $d++) {
                        $c = $month->copy()->day($d);
                        if ((int) $c->format('w') === $dayOfWeek) {
                            $nth++;
                            if ($nth === $occurrence) {
                                $c->setTimeFromTimeString($timeStr);
                                if ($c->gt($after)) {
                                    if ($endDate && $c->gt($endDate)) {
                                        return null;
                                    }
                                    return $c;
                                }
                            }
                        }
                    }
                }
            }
            return null;
        }

        return null;
    }

    private function syncItems(RecurringOrder $order, array $items): void
    {
        $order->items()->delete();
        $itemIds = collect($items)->pluck('item_id')->unique()->filter();
        $itemsData = Item::whereIn('id', $itemIds)->get()->keyBy('id');

        foreach ($items as $row) {
            $item = $itemsData->get($row['item_id'] ?? null);
            if (!$item) {
                continue;
            }
            $logo = $item->logo;
            $logoStr = is_array($logo) ? ($logo[0] ?? null) : $logo;
            RecurringOrderItem::create([
                'recurring_order_id' => $order->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'item_logo' => $logoStr,
                'quantity' => $row['quantity'],
                'quality' => $row['quality'] ?? 'standard',
                'unit_price' => $row['unit_price'] ?? 0,
            ]);
        }
    }
}
