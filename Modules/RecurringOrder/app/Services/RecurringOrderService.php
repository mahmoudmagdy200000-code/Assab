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
     * Accepts body with either direct_supplier or purchase_officer (normalized inside).
     */
    public function create(BranchManager $branchManager, array $data): RecurringOrder
    {
        return DB::transaction(function () use ($branchManager, $data) {
            $normalized = $this->normalizeCreatePayload($data);

            $order = $this->repository->create([
                'branch_id' => $branchManager->branch_id,
                'created_by' => $branchManager->id,
                'order_name' => $normalized['order_name'],
                'order_source_type' => $normalized['order_source_type'],
                'status' => RecurringOrderStatus::PENDING,
                'sourceable_type' => $normalized['sourceable_type'],
                'sourceable_id' => $normalized['sourceable_id'],
                'message' => $normalized['message'] ?? null,
                'notification_channels' => $normalized['notification_channels'] ?? null,
                'repeat_frequency' => $data['repeat_frequency'],
                'repeat_config' => $data['repeat_config'] ?? null,
                'scheduling_time_am' => $this->normalizeTime($data['scheduling_time_am'] ?? null),
                'scheduling_time_pm' => $this->normalizeTime($data['scheduling_time_pm'] ?? null),
                'notification_options' => $data['notification_options'] ?? [],
                'smart_settings' => $data['smart_settings'] ?? [],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'end_type' => $data['end_type'] ?? 'repeat',
                'next_run_at' => $this->computeNextRunAt(array_merge($data, [
                    'scheduling_time_am' => $this->normalizeTime($data['scheduling_time_am'] ?? null),
                    'scheduling_time_pm' => $this->normalizeTime($data['scheduling_time_pm'] ?? null),
                ])),
            ]);

            $this->syncItems($order, $normalized['items']);
            return $order->fresh(['items.item', 'sourceable']);
        });
    }

    /**
     * Normalize notification channels for DB: map in-app variants to 'app', keep only canonical values.
     * Canonical: email, whatsapp, sms, app.
     */
    private function normalizeNotificationChannels(?array $channels): ?array
    {
        if ($channels === null || $channels === []) {
            return null;
        }
        $inAppVariants = ['in_app', 'in-app', 'in_app_notification', 'in-app-notification', 'inApp', 'push'];
        $canonical = [];
        foreach ($channels as $ch) {
            $ch = is_string($ch) ? trim($ch) : '';
            if ($ch === '') {
                continue;
            }
            if (in_array($ch, $inAppVariants, true)) {
                $canonical['app'] = true;
            } else {
                $canonical[$ch] = true;
            }
        }
        $list = array_keys($canonical);
        return $list !== [] ? array_values($list) : null;
    }

    /**
     * Normalize request body: direct_supplier | purchase_officer -> order_source_type, sourceable, items, order_name, message, notification_channels.
     */
    private function normalizeCreatePayload(array $data): array
    {
        if (!empty($data['direct_supplier'])) {
            $ds = $data['direct_supplier'];
            $supplier = \Modules\Supplier\Models\Supplier::find($ds['supplier_id']);
            $orderName = $data['order_name'] ?? ($supplier
                ? 'Recurring - ' . ($supplier->name ?? $supplier->company_name ?? 'Supplier') . ' - ' . ($data['start_date'] ?? '')
                : 'Recurring order');
            return [
                'order_name' => $orderName,
                'order_source_type' => OrderSourceType::DIRECT_SUPPLIER->value,
                'sourceable_type' => \Modules\Supplier\Models\Supplier::class,
                'sourceable_id' => $ds['supplier_id'],
                'message' => $ds['message'] ?? null,
                'notification_channels' => $this->normalizeNotificationChannels($ds['notification_channels'] ?? null),
                'items' => $ds['items'] ?? [],
            ];
        }

        if (!empty($data['purchase_officer'])) {
            $po = $data['purchase_officer'];
            $officer = BranchManager::find($po['purchasing_officer_id'] ?? null);
            $orderName = $data['order_name'] ?? ($officer
                ? 'Recurring - ' . $officer->name . ' - ' . ($data['start_date'] ?? '')
                : 'Recurring order');
            return [
                'order_name' => $orderName,
                'order_source_type' => OrderSourceType::VIA_PURCHASING_OFFICER->value,
                'sourceable_type' => BranchManager::class,
                'sourceable_id' => $po['purchasing_officer_id'],
                'message' => null,
                'notification_channels' => null,
                'items' => $po['items'] ?? [],
            ];
        }

        throw new \InvalidArgumentException('Either direct_supplier or purchase_officer must be provided.');
    }

    /**
     * Normalize time: accept H:i or full ISO datetime, return H:i for DB.
     */
    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_object($value)) {
            return null;
        }
        $str = (string) $value;
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $str)) {
            return substr($str, 0, 5);
        }
        try {
            return Carbon::parse($str)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
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
            $dsChannels = $data['direct_supplier']['notification_channels'] ?? null;
            $update = array_filter([
                'order_name' => $data['order_name'] ?? null,
                'message' => $data['message'] ?? $data['direct_supplier']['message'] ?? null,
                'notification_channels' => $dsChannels !== null ? $this->normalizeNotificationChannels($dsChannels) : null,
                'repeat_frequency' => $data['repeat_frequency'] ?? null,
                'repeat_config' => $data['repeat_config'] ?? null,
                'scheduling_time_am' => $this->normalizeTime($data['scheduling_time_am'] ?? null),
                'scheduling_time_pm' => $this->normalizeTime($data['scheduling_time_pm'] ?? null),
                'notification_options' => $data['notification_options'] ?? null,
                'smart_settings' => $data['smart_settings'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'end_type' => $data['end_type'] ?? null,
            ], fn ($v) => $v !== null);

            $itemsToSync = $data['items']
                ?? $data['direct_supplier']['items']
                ?? $data['purchase_officer']['items']
                ?? null;
            if (!empty($itemsToSync)) {
                $this->syncItems($recurringOrder, $itemsToSync);
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
            'scheduling_time_am' => $this->formatTimeForCompute($model->scheduling_time_am),
            'scheduling_time_pm' => $this->formatTimeForCompute($model->scheduling_time_pm),
            'start_date' => $this->formatDateForCompute($model->start_date),
            'end_date' => $this->formatDateForCompute($model->end_date),
        ];
        $after = $model->next_run_at ?? ($model->start_date ? Carbon::parse($model->start_date)->startOfDay() : null);
        return $this->computeNextRunAt($data, $after);
    }

    /**
     * Normalize time value (string or DateTime) to H:i string for computeNextRunAt.
     */
    private function formatTimeForCompute(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return substr($value, 0, 5);
        }
        return $value->format('H:i');
    }

    /**
     * Normalize date value (string or DateTime) to Y-m-d string for computeNextRunAt.
     */
    private function formatDateForCompute(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        return $value->format('Y-m-d');
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
                'preferred_delivery_date' => isset($row['preferred_delivery_date']) ? $row['preferred_delivery_date'] : null,
                'latest_delivery_date' => isset($row['latest_delivery_date']) ? $row['latest_delivery_date'] : null,
                'special_instructions' => $row['special_instructions'] ?? null,
            ]);
        }
    }
}
