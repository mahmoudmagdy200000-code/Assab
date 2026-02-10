<?php

namespace Modules\RecurringOrder\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\RecurringOrder\Models\RecurringOrderItem;
use Modules\Supplier\Models\Supplier;

/**
 * بيانات تجريبية لوحدة الطلبات المتكررة (Recurring Orders)
 * للبرانش والبرانش مانجر المحددين – للتجربة والاختبار.
 *
 * التشغيل:
 *   php artisan db:seed --class="Modules\RecurringOrder\Database\Seeders\RecurringOrderTestDataSeeder"
 */
class RecurringOrderTestDataSeeder extends Seeder
{
    private string $branchId = '019bd5e8-4837-700b-81b5-c9f2080fcbff';

    private string $branchManagerId = '019bd5e8-4900-72b0-b5f3-3915e49ad0dd';

    public function run(): void
    {
        $branchExists = \Modules\Branch\Models\Branch::where('id', $this->branchId)->exists();
        $managerExists = BranchManager::where('id', $this->branchManagerId)->exists();

        if (!$branchExists || !$managerExists) {
            $this->command->warn('Branch or Branch Manager not found. Ensure IDs exist in branches and branch_managers tables.');
            return;
        }

        $supplier = Supplier::where('id', '019bd5e8-53ca-738c-8229-7df26821c8e8')->first()
            ?? Supplier::first();
        $purchasingOfficer = BranchManager::where('branch_id', $this->branchId)
            ->where('id', '!=', $this->branchManagerId)
            ->first() ?? BranchManager::where('id', '!=', $this->branchManagerId)->first();

        $items = $this->getItemsForBranch();

        if ($items->isEmpty()) {
            $this->command->warn('No items found for branch. Add items (items + branch_item) first.');
            return;
        }

        $this->command->info('Seeding Recurring Orders test data...');

        // 1) طلب متكرر – قائمة "قيد التنفيذ" (In Progress) – عبر مورد مباشر
        $this->createRecurringOrder([
            'order_name' => 'طلب أسبوعي – مورد مباشر (تجريبي)',
            'order_source_type' => 'direct_supplier',
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supplier->id,
            'status' => 'in_progress',
            'repeat_frequency' => 'weekly',
            'repeat_config' => ['repeat_days' => [1, 3, 5]],
            'scheduling_time_pm' => '10:00',
            'notification_options' => ['alert_24_hours_before', 'send_automatically_without_review'],
            'smart_settings' => ['auto_adjust_quantities_based_on_consumption'],
            'start_date' => now()->subDays(5),
            'end_type' => 'repeat',
            'next_run_at' => now()->addDays(2)->setTime(10, 0),
        ], $items);

        // 2) طلب متكرر – قائمة "الجدولة القادمة" (Pending / Next Scheduling) – أسبوعي
        $this->createRecurringOrder([
            'order_name' => 'طلب الجدولة القادمة – أسبوعي (تجريبي)',
            'order_source_type' => 'direct_supplier',
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supplier->id,
            'status' => 'pending',
            'repeat_frequency' => 'weekly',
            'repeat_config' => ['repeat_days' => [0, 2, 4]],
            'scheduling_time_am' => '09:00',
            'notification_options' => ['review_before_sending'],
            'smart_settings' => ['notify_when_prices_change'],
            'start_date' => now(),
            'end_type' => 'repeat',
            'next_run_at' => now()->addDays(1)->setTime(9, 0),
        ], $items);

        // 3) طلب متكرر – شهري (بتاريخ)
        $this->createRecurringOrder([
            'order_name' => 'طلب شهري – بتواريخ (تجريبي)',
            'order_source_type' => 'direct_supplier',
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supplier->id,
            'status' => 'pending',
            'repeat_frequency' => 'monthly',
            'repeat_config' => ['repeat_type' => 'by_date', 'dates' => [1, 15]],
            'scheduling_time_pm' => '14:00',
            'notification_options' => ['alert_24_hours_before'],
            'smart_settings' => [],
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
            'end_type' => 'date',
            'next_run_at' => now()->endOfMonth()->addDay()->setTime(14, 0),
        ], $items);

        // 4) طلب متكرر – معلق (Paused)
        $this->createRecurringOrder([
            'order_name' => 'طلب معلق – للتجربة (تجريبي)',
            'order_source_type' => 'direct_supplier',
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supplier->id,
            'status' => 'paused',
            'repeat_frequency' => 'weekly',
            'repeat_config' => ['repeat_days' => [6]],
            'scheduling_time_am' => '11:00',
            'notification_options' => ['send_automatically_without_review'],
            'smart_settings' => ['freeze_during_holidays_and_events'],
            'start_date' => now()->subDays(10),
            'end_type' => 'repeat',
            'next_run_at' => null,
            'paused_at' => now(),
        ], $items);

        // 5) طلب متكرر – عبر مسؤول المشتريات (إن وُجد)
        if ($purchasingOfficer) {
            $this->createRecurringOrder([
                'order_name' => 'طلب عبر مسؤول المشتريات (تجريبي)',
                'order_source_type' => 'via_purchasing_officer',
                'sourceable_type' => BranchManager::class,
                'sourceable_id' => $purchasingOfficer->id,
                'status' => 'pending',
                'repeat_frequency' => 'weekly',
                'repeat_config' => ['repeat_days' => [1, 4]],
                'scheduling_time_pm' => '12:00',
                'notification_options' => ['alert_24_hours_before', 'review_before_sending'],
                'smart_settings' => ['auto_adjust_quantities_based_on_consumption'],
                'start_date' => now(),
                'end_type' => 'repeat',
                'next_run_at' => now()->addDays(3)->setTime(12, 0),
            ], $items);
        }

        $this->command->info('Recurring Orders test data seeded successfully.');
    }

    private function getItemsForBranch()
    {
        $branchItems = BranchItem::where('branch_id', $this->branchId)
            ->with('item')
            ->limit(5)
            ->get();

        if ($branchItems->isNotEmpty()) {
            return $branchItems->map(function ($bi) {
                return [
                    'item_id' => $bi->item_id,
                    'item_name' => $bi->item->name ?? 'Item',
                    'item_logo' => is_array($bi->item->logo ?? null) ? ($bi->item->logo[0] ?? null) : ($bi->item->logo ?? null),
                    'quantity' => rand(5, 20),
                    'quality' => ['economy', 'standard', 'premium'][rand(0, 2)],
                    'unit_price' => (float) ($bi->price ?? 10),
                ];
            });
        }

        return Item::limit(5)->get()->map(function ($item) {
            $logo = $item->logo;
            $logoStr = is_array($logo) ? ($logo[0] ?? null) : $logo;
            return [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'item_logo' => $logoStr,
                'quantity' => rand(5, 20),
                'quality' => 'standard',
                'unit_price' => 10,
            ];
        });
    }

    private function createRecurringOrder(array $data, $items): void
    {
        $order = RecurringOrder::create([
            'branch_id' => $this->branchId,
            'created_by' => $this->branchManagerId,
            'order_name' => $data['order_name'],
            'order_source_type' => $data['order_source_type'],
            'status' => $data['status'],
            'sourceable_type' => $data['sourceable_type'],
            'sourceable_id' => $data['sourceable_id'],
            'repeat_frequency' => $data['repeat_frequency'],
            'repeat_config' => $data['repeat_config'] ?? null,
            'scheduling_time_am' => isset($data['scheduling_time_am']) ? $data['scheduling_time_am'] : null,
            'scheduling_time_pm' => isset($data['scheduling_time_pm']) ? $data['scheduling_time_pm'] : null,
            'notification_options' => $data['notification_options'] ?? [],
            'smart_settings' => $data['smart_settings'] ?? [],
            'start_date' => $data['start_date'] instanceof Carbon ? $data['start_date'] : Carbon::parse($data['start_date']),
            'end_date' => $this->parseOptionalDate($data['end_date'] ?? null),
            'end_type' => $data['end_type'] ?? 'repeat',
            'next_run_at' => $data['next_run_at'] ?? null,
            'paused_at' => $data['paused_at'] ?? null,
        ]);

        foreach ($items as $row) {
            RecurringOrderItem::create([
                'recurring_order_id' => $order->id,
                'item_id' => $row['item_id'],
                'item_name' => $row['item_name'],
                'item_logo' => $row['item_logo'] ?? null,
                'quantity' => $row['quantity'],
                'quality' => $row['quality'] ?? 'standard',
                'unit_price' => $row['unit_price'],
            ]);
        }
    }

    private function parseOptionalDate($value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }
}
