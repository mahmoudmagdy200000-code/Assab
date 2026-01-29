<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\SupplierItem;
use Modules\Supplier\Models\Supplier;

/**
 * Seeds 20,000 direct_supplier purchase orders with real IDs from DB (branches, suppliers, branch managers, items).
 * Uses chunking (500 per chunk) to avoid memory issues.
 *
 * Run after Branches, BranchManagers, Suppliers, Items/SupplierItems exist:
 *   php artisan db:seed --class="Modules\Purchase\Database\Seeders\PurchaseOrdersBulkSeeder"
 */
class PurchaseOrdersBulkSeeder extends Seeder
{
    private const TOTAL_ORDERS = 20_000;

    private const CHUNK_SIZE = 500;

    /** Allowed values for purchase_order_items.unit_of_measurement (ENUM in DB) */
    private const ALLOWED_UNITS = ['kg', 'pk', 'unit', 'box', 'liter', 'piece'];

    /** @var array<string> */
    private array $branchIds = [];

    /** @var array<string, array<string>> */
    private array $branchManagerIdsByBranch = [];

    /** @var array<string> */
    private array $supplierIds = [];

    /** @var array<int, array{id: string, name: string, unit: string}> */
    private array $items = [];

    /** @var array<string, array<int, array{item_id: string, item_name: string, unit_price: float}>> */
    private array $supplierItemsCache = [];

    public function run(): void
    {
        $this->loadRealIds();

        if ($this->branchIds === [] || $this->supplierIds === []) {
            $this->command->error('Need at least one Branch and one Supplier. Run Branch and Supplier seeders first.');
            return;
        }

        if ($this->items === []) {
            $this->command->error('Need at least one Item. Run Item/SupplierItem seeders first.');
            return;
        }

        $this->command->info('Seeding ' . self::TOTAL_ORDERS . ' purchase orders (chunks of ' . self::CHUNK_SIZE . ')...');

        $bar = $this->command->getOutput()->createProgressBar(self::TOTAL_ORDERS);
        $bar->start();

        $created = 0;
        for ($chunkStart = 0; $chunkStart < self::TOTAL_ORDERS; $chunkStart += self::CHUNK_SIZE) {
            $chunkCount = min(self::CHUNK_SIZE, self::TOTAL_ORDERS - $chunkStart);
            $this->createChunk($chunkCount, $chunkStart, $bar);
            $created += $chunkCount;
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Created {$created} purchase orders with items.");
    }

    private function loadRealIds(): void
    {
        $this->branchIds = Branch::pluck('id')->all();
        $this->supplierIds = Supplier::pluck('id')->all();

        foreach ($this->branchIds as $branchId) {
            $managers = BranchManager::where('branch_id', $branchId)->pluck('id')->all();
            $this->branchManagerIdsByBranch[$branchId] = $managers;
        }

        // Flatten: if a branch has no manager, we'll pick any manager later
        $allManagerIds = BranchManager::pluck('id')->all();
        foreach ($this->branchIds as $branchId) {
            if (empty($this->branchManagerIdsByBranch[$branchId])) {
                $this->branchManagerIdsByBranch[$branchId] = $allManagerIds;
            }
        }

        $itemsFromDb = Item::select('id', 'name', 'unit')->get();
        foreach ($itemsFromDb as $i => $row) {
            $this->items[$i] = [
                'id' => $row->id,
                'name' => $row->name,
                'unit' => $row->unit ?? 'piece',
            ];
        }
        $this->items = array_values($this->items);
    }

    private function createChunk(int $count, int $offset, $progressBar): void
    {
        $ordersData = [];
        $itemsData = [];

        $statuses = [
            OrderStatus::PENDING,
            OrderStatus::CONFIRMED,
            OrderStatus::PREPARING,
            OrderStatus::ON_THE_WAY,
            OrderStatus::DELAYED,
            OrderStatus::DELAYED_CONFIRMED,
            OrderStatus::DELAYED_CANCELED,
            OrderStatus::DELIVERED,
            OrderStatus::CLOSED,
            OrderStatus::REJECTED,
        ];

        $qualities = [null, QualityLevel::ECONOMY, QualityLevel::STANDARD, QualityLevel::PREMIUM];

        for ($i = 0; $i < $count; $i++) {
            $globalIndex = $offset + $i;
            $branchId = $this->branchIds[array_rand($this->branchIds)];
            $managerIds = $this->branchManagerIdsByBranch[$branchId] ?? $this->branchManagerIdsByBranch[$this->branchIds[0]];
            $requestedBy = $managerIds[array_rand($managerIds)];
            $supplierId = $this->supplierIds[array_rand($this->supplierIds)];

            $orderNumber = 'DS-SEED-' . str_pad((string) ($globalIndex + 1), 6, '0', STR_PAD_LEFT);

            $numItems = random_int(1, 4);
            $orderItems = $this->pickOrderItems($supplierId, $numItems);
            if ($orderItems === []) {
                $orderItems = $this->pickFallbackItems($numItems);
            }

            $subtotal = 0.0;
            foreach ($orderItems as $oi) {
                $subtotal += $oi['total_price'];
            }
            $taxRate = 15.0;
            $taxAmount = round($subtotal * ($taxRate / 100), 2);
            $totalAmount = round($subtotal + $taxAmount, 2);

            $status = $statuses[array_rand($statuses)];
            $itemStatus = $this->itemStatusForOrderStatus($status);

            $createdAt = now()->subDays(rand(0, 60))->subHours(rand(0, 23));

            $orderId = (string) Str::uuid();
            $ordersData[] = [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'order_type' => OrderType::DIRECT_SUPPLIER->value,
                'status' => $status->value,
                'branch_id' => $branchId,
                'requested_by' => $requestedBy,
                'sourceable_type' => Supplier::class,
                'sourceable_id' => $supplierId,
                'from_branch_id' => null,
                'to_branch_id' => null,
                'supplier_id' => $supplierId,
                'quality_level' => $qualities[array_rand($qualities)]?->value,
                'processing_time' => 'standard',
                'priority' => 'normal',
                'preferred_delivery_date' => $createdAt->copy()->addDays(rand(1, 5))->format('Y-m-d'),
                'latest_delivery_date' => null,
                'expected_delivery_at' => null,
                'actual_delivery_at' => null,
                'notification_channels' => json_encode(['email', 'sms']),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'total_amount' => $totalAmount,
                'discount_amount' => 0,
                'total_items' => $numItems,
                'received_items' => 0,
                'message' => 'Seeded order',
                'special_instructions' => null,
                'rejection_reason' => null,
                'cancellation_reason' => null,
                'delay_reason' => null,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $createdAt->format('Y-m-d H:i:s'),
            ];

            foreach ($orderItems as $oi) {
                $itemsData[] = [
                    'id' => (string) Str::uuid(),
                    'purchase_order_id' => $orderId,
                    'item_id' => $oi['item_id'],
                    'item_name' => $oi['item_name'],
                    'item_logo' => null,
                    'item_sku' => null,
                    'category' => null,
                    'subcategory' => null,
                    'quantity_ordered' => $oi['quantity'],
                    'quantity_confirmed' => $oi['quantity'],
                    'quantity_received' => null,
                    'unit_of_measurement' => $oi['unit'],
                    'unit_price' => $oi['unit_price'],
                    'total_price' => $oi['total_price'],
                    'discount' => 0,
                    'quality_ordered' => $qualities[array_rand($qualities)]?->value,
                    'quality_received' => null,
                    'available_in_source' => null,
                    'remaining_balance' => null,
                    'daily_consumption' => null,
                    'weekend_forecast' => null,
                    'next_supply_date' => null,
                    'expiry_date' => null,
                    'temperature' => null,
                    'cooling_status' => null,
                    'inspection_photo' => null,
                    'inspection_notes' => null,
                    'status' => $itemStatus->value,
                    'original_quantity' => $oi['quantity'],
                    'new_quantity' => $oi['quantity'],
                    'modification_note' => null,
                    'is_alternative' => false,
                    'original_item_id' => null,
                    'is_gift' => false,
                    'gift_reason' => null,
                    'approval_type' => null,
                    'approval_data' => null,
                    'created_at' => $createdAt->format('Y-m-d H:i:s'),
                    'updated_at' => $createdAt->format('Y-m-d H:i:s'),
                ];
            }

            $progressBar->advance(1);
        }

        DB::transaction(function () use ($ordersData, $itemsData) {
            PurchaseOrder::insert($ordersData);
            PurchaseOrderItem::insert($itemsData);
        });
    }

    /**
     * @return array<int, array{item_id: string, item_name: string, unit: string, quantity: int, unit_price: float, total_price: float}>
     */
    private function pickOrderItems(string $supplierId, int $numItems): array
    {
        if (!isset($this->supplierItemsCache[$supplierId])) {
            $rows = SupplierItem::where('supplier_id', $supplierId)
                ->with('item:id,name,unit')
                ->get();
            $list = [];
            foreach ($rows as $si) {
                $list[] = [
                    'item_id' => $si->item_id,
                    'item_name' => $si->item->name ?? 'Item',
                    'unit' => $this->normalizeUnit($si->item->unit ?? 'piece'),
                    'unit_price' => (float) ($si->unit_price ?? $si->standard_price ?? 10),
                ];
            }
            $this->supplierItemsCache[$supplierId] = $list;
        }

        $list = $this->supplierItemsCache[$supplierId];
        if ($list === []) {
            return [];
        }

        $picked = [];
        $indices = array_rand($list, min($numItems, count($list)));
        if (!is_array($indices)) {
            $indices = [$indices];
        }
        foreach ($indices as $idx) {
            $row = $list[$idx];
            $qty = random_int(1, 20);
            $unitPrice = $row['unit_price'];
            $picked[] = [
                'item_id' => $row['item_id'],
                'item_name' => $row['item_name'],
                'unit' => $row['unit'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => round($qty * $unitPrice, 2),
            ];
        }
        return $picked;
    }

    /**
     * @return array<int, array{item_id: string, item_name: string, unit: string, quantity: int, unit_price: float, total_price: float}>
     */
    private function pickFallbackItems(int $numItems): array
    {
        if ($this->items === []) {
            $first = Item::select('id', 'name', 'unit')->first()
                ?? SupplierItem::with('item:id,name,unit')->first()?->item;
            if (!$first) {
                throw new \RuntimeException('No Items in DB. Run Item/SupplierItem seeders first.');
            }
            $qty = random_int(1, 10);
            $price = (float) random_int(5, 100);
            return [
                [
                    'item_id' => $first->id,
                    'item_name' => $first->name,
                    'unit' => $this->normalizeUnit($first->unit ?? 'piece'),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'total_price' => round($qty * $price, 2),
                ],
            ];
        }

        $picked = [];
        $indices = array_rand($this->items, min($numItems, count($this->items)));
        if (!is_array($indices)) {
            $indices = [$indices];
        }
        foreach ($indices as $idx) {
            $row = $this->items[$idx];
            $qty = random_int(1, 20);
            $unitPrice = (float) random_int(2, 50);
            $picked[] = [
                'item_id' => $row['id'],
                'item_name' => $row['name'],
                'unit' => $this->normalizeUnit($row['unit']),
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => round($qty * $unitPrice, 2),
            ];
        }
        return $picked;
    }

    /**
     * Map item unit to purchase_order_items.unit_of_measurement ENUM (kg, pk, unit, box, liter, piece).
     */
    private function normalizeUnit(?string $unit): string
    {
        if ($unit === null || $unit === '') {
            return 'piece';
        }
        $u = strtolower(trim($unit));
        if (in_array($u, self::ALLOWED_UNITS, true)) {
            return $u;
        }
        $map = [
            'pack' => 'box',
            'pcs' => 'piece',
            'pieces' => 'piece',
            'litre' => 'liter',
            'litres' => 'liter',
            'l' => 'liter',
            'kgs' => 'kg',
            'gram' => 'kg',
            'grams' => 'kg',
            'g' => 'kg',
        ];
        return $map[$u] ?? 'piece';
    }

    private function itemStatusForOrderStatus(OrderStatus $orderStatus): OrderItemStatus
    {
        return match ($orderStatus) {
            OrderStatus::PENDING => OrderItemStatus::PENDING,
            OrderStatus::CONFIRMED => OrderItemStatus::CONFIRMED,
            OrderStatus::PREPARING => OrderItemStatus::PREPARING,
            OrderStatus::ON_THE_WAY => OrderItemStatus::ON_THE_WAY,
            OrderStatus::DELAYED => OrderItemStatus::DELAYED,
            OrderStatus::DELAYED_CONFIRMED => OrderItemStatus::DELAYED_CONFIRMED,
            OrderStatus::DELAYED_CANCELED => OrderItemStatus::DELAYED_CANCELED,
            OrderStatus::DELIVERED => OrderItemStatus::DELIVERED,
            OrderStatus::CLOSED => OrderItemStatus::CLOSED,
            OrderStatus::REJECTED => OrderItemStatus::REJECTED,
            default => OrderItemStatus::PENDING,
        };
    }
}
