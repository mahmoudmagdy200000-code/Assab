<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\InspectionQuality;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\ReturnOrderItem;
use Modules\Purchase\Services\ReturnManagementService;
use Modules\Supplier\Models\Supplier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Isolated Oracle MySQL 8.4.11 Stage 3 Concurrency & Migration Gate.
 * Opt-in only via STAGE3_MYSQL_TESTS=1 against an isolated assab_stage3_mysql schema.
 */
class PurchaseStage3MySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $barriers = [];

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $procurementUser;

    private AsabUser $accountantUser;

    private AsabUser $headUser;

    private Supplier $supplier;

    private Item $itemA;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('STAGE3_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Stage 3 MySQL gate requires STAGE3_MYSQL_TESTS=1.');
        }

        if (DB::connection()->getDriverName() !== 'mysql'
            || ! preg_match('/^assab_stage3_mysql[a-z0-9_]*$/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
            throw new \LogicException('Refusing Stage 3 MySQL concurrency tests outside an isolated local assab_stage3_mysql schema.');
        }

        $tag = bin2hex(random_bytes(4));
        $this->company = AsabCompany::create([
            'name' => 'Stage 3 MySQL Co '.$tag,
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand S3 MySQL '.$tag,
            'abbr' => 'S3M',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->procurementUser = $this->createScopedUser('procurement', $tag);
        $this->accountantUser = $this->createScopedUser('accountant', $tag);
        $this->headUser = $this->createScopedUser('head', $tag);

        $this->supplier = Supplier::create([
            'name' => 'S3 MySQL Supplier '.$tag,
            'email' => "s3m-sup-{$tag}@test.local",
            'password' => 'secret123',
            'is_active' => true,
        ]);

        $this->itemA = Item::create(['name' => 'S3 MySQL Flour '.$tag, 'unit' => 'kg', 'is_active' => true]);
        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $this->itemA->id,
            'price' => 50.00,
            'quantity' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->barriers as $barrier) {
            @file_put_contents($barrier, 'release');
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        parent::tearDown();
    }

    private function createScopedUser(string $roleKey, string $tag): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => "S3M {$roleKey} {$tag}",
            'email' => "s3m-{$roleKey}-{$tag}@test.local",
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role_key' => $roleKey,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createOrder(OrderType $orderType, float $qty, float $price): array
    {
        $order = PurchaseOrder::create([
            'order_number' => 'PO-S3M-'.strtoupper(bin2hex(random_bytes(3))),
            'order_type' => $orderType,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'supplier_id' => $this->supplier->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'total_amount' => round($qty * $price, 2),
            'tax_rate' => 15.0,
            'tax_amount' => round($qty * $price * 0.15, 2),
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->itemA->id,
            'item_name' => $this->itemA->name,
            'quantity_ordered' => $qty,
            'quantity_confirmed' => $qty,
            'unit_price' => $price,
            'total_price' => round($qty * $price, 2),
            'unit_of_measurement' => 'kg',
            'status' => 'confirmed',
        ]);

        return [$order, $item];
    }

    public function test_mysql_new_migration_columns_and_innodb_isolation(): void
    {
        $row = DB::selectOne('SELECT VERSION() AS v, @@transaction_isolation AS iso');
        $this->assertStringStartsWith('8.4.', (string) $row->v);
        $this->assertSame('REPEATABLE-READ', (string) $row->iso);

        foreach ([
            'handed_over_at',
            'handed_over_by',
            'financial_status',
            'reviewed_by_accountant_id',
            'reviewed_by_accountant_at',
            'approved_by_head_id',
            'approved_by_head_at',
            'financial_notes',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('return_orders', $col), "Missing column return_orders.{$col}");
        }
    }

    public function test_mysql_concurrent_duplicate_excess_approval_releases_stock_and_wac_once(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 15, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        [$first, $second] = $this->race(
            ['command' => 'approve_excess', 'receipt_id' => $receipt->id, 'actor_id' => $this->procurementUser->id],
            ['command' => 'approve_excess', 'receipt_id' => $receipt->id, 'actor_id' => $this->procurementUser->id],
        );

        $this->assertSame('ok', $first['status']);
        $this->assertSame('ok', $second['status']);

        $receiptItem->refresh();
        $this->assertEquals(15.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(0.0, (float) $receiptItem->quantity_excess_reserved);
        $this->assertSame('approved', $receiptItem->excess_status);

        $invQty = (float) BranchInventory::where('branch_id', $this->branch->id)
            ->where('item_id', $this->itemA->id)
            ->value('available_quantity');
        $this->assertEquals(15.0, $invQty);

        $movements = InventoryStockMovement::where('idempotency_key', "excess_release:{$receiptItem->id}")->get();
        $this->assertCount(1, $movements);
        $this->assertEquals(5.0, (float) $movements->first()->qty_delta);

        // Verify WAC consistency (10 + 5 = 15 units @ 50 SAR = 75000 halalas)
        $monthly = InventoryMonthlyCost::where('company_id', $this->company->id)
            ->where('branch_id', $this->branch->id)
            ->where('item_id', $this->itemA->id)
            ->firstOrFail();
        $this->assertEquals(15.0, (float) $monthly->purchases_qty);
        $this->assertSame(75000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(5000, (int) $monthly->unit_cost_halalas);
    }

    public function test_mysql_concurrent_excess_approval_vs_rejection_preserves_winner_consistency(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 14, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        [$first, $second] = $this->race(
            ['command' => 'approve_excess', 'receipt_id' => $receipt->id, 'actor_id' => $this->procurementUser->id],
            ['command' => 'reject_excess', 'receipt_id' => $receipt->id, 'actor_id' => $this->procurementUser->id, 'reason' => 'Concurrent reject'],
        );

        $this->assertSame('ok', $first['status']);
        $this->assertSame('ok', $second['status']);

        $receipt->refresh();
        $receiptItem->refresh();
        $this->assertSame('approved', $receiptItem->excess_status);
        $this->assertEquals(14.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(0.0, (float) $receiptItem->quantity_excess_reserved);
        $this->assertArrayNotHasKey('excess_rejection', $receipt->cost_adjustment_meta ?? []);
    }

    public function test_mysql_concurrent_return_creation_enforces_net_received_limit(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        // Two concurrent return creations for 5 units each (10 total > 8 received)
        [$first, $second] = $this->race(
            ['command' => 'create_return', 'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'actor_id' => $this->manager->id, 'qty' => 5.0],
            ['command' => 'create_return', 'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'actor_id' => $this->manager->id, 'qty' => 5.0],
        );

        $this->assertSame('created', $first['status']);
        $this->assertSame('rejected', $second['status']);
        $this->assertSame('RETURN_EXCEEDS_NET_RECEIVED', $second['error_code']);
        $this->assertSame(422, $second['http_status']);

        $totalReturned = (float) ReturnOrderItem::where('purchase_order_item_id', $orderItem->id)->sum('return_quantity');
        $this->assertEquals(5.0, $totalReturned);
    }

    public function test_mysql_concurrent_duplicate_handover_deducts_stock_once(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $returnOrder = app(ReturnManagementService::class)->createReturn($order, [
            'created_by' => $this->manager->id,
            'required_action' => ReturnRequiredAction::CASH_REFUND->value,
            'items' => [[
                'purchase_order_item_id' => $orderItem->id,
                'return_quantity' => 3.0,
                'quality_reason' => InspectionQuality::POOR->value,
            ]],
        ]);

        [$first, $second] = $this->race(
            ['command' => 'confirm_handover', 'return_id' => $returnOrder->id, 'actor_id' => $this->manager->id],
            ['command' => 'confirm_handover', 'return_id' => $returnOrder->id, 'actor_id' => $this->manager->id],
        );

        $this->assertSame('handed_over', $first['status']);
        $this->assertSame('handed_over', $second['status']);

        $invAfter = (float) BranchInventory::where('branch_id', $this->branch->id)
            ->where('item_id', $this->itemA->id)
            ->value('available_quantity');
        $this->assertEquals(5.0, $invAfter);

        $returnItem = $returnOrder->items->first();
        $movements = InventoryStockMovement::where('idempotency_key', "return_handover:{$returnOrder->id}:{$returnItem->id}")->get();
        $this->assertCount(1, $movements);
        $this->assertEquals(-3.0, (float) $movements->first()->qty_delta);
    }

    public function test_mysql_authorization_idempotency_and_financial_approval_sequence(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 12, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        // Idempotent HTTP call with Idempotency-Key on excess approval
        $idemKey = (string) Str::uuid();
        $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', $idemKey)
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/excess-approvals")
            ->assertOk();

        $this->actingAs($this->procurementUser, 'sanctum')
            ->withHeader('Idempotency-Key', $idemKey)
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/excess-approvals")
            ->assertOk();

        $this->assertEquals(12.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));

        // Return financial sequence on MySQL
        $returnOrder = app(ReturnManagementService::class)->createReturn($order, [
            'created_by' => $this->manager->id,
            'required_action' => ReturnRequiredAction::CASH_REFUND->value,
            'items' => [[
                'purchase_order_item_id' => $orderItem->id,
                'return_quantity' => 2.0,
                'quality_reason' => InspectionQuality::POOR->value,
            ]],
        ]);

        // Head cannot approve before accountant review (409)
        $this->actingAs($this->headUser, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/final-approve")
            ->assertStatus(409);

        // Accountant reviews -> Head final approves
        $this->actingAs($this->accountantUser, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/review", ['notes' => 'MySQL verified'])
            ->assertOk();

        $this->actingAs($this->headUser, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/final-approve", ['notes' => 'Head approved'])
            ->assertOk();

        $returnOrder->refresh();
        $this->assertSame('approved_by_head', $returnOrder->financial_status);
        $this->assertSame($this->accountantUser->id, $returnOrder->reviewed_by_accountant_id);
        $this->assertSame($this->headUser->id, $returnOrder->approved_by_head_id);
    }

    /**
     * S3-CODEX-002 (R2 closure):
     * A persisted over-cap return exceeding net received quantity must be rejected
     * at confirmHandover() with RETURN_EXCEEDS_NET_RECEIVED before any stock deduction.
     */
    public function test_mysql_persisted_over_cap_return_rejected_at_handover_without_stock_deduction(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $invBefore = (float) BranchInventory::where('branch_id', $this->branch->id)
            ->where('item_id', $this->itemA->id)
            ->value('available_quantity');
        $branchItemBefore = (float) BranchItem::where('branch_id', $this->branch->id)
            ->where('item_id', $this->itemA->id)
            ->value('quantity');
        $this->assertEquals(8.0, $invBefore);
        $this->assertEquals(8.0, $branchItemBefore);

        // Seed a persisted over-cap return (5 + 5 = 10 > 8 received)
        $returnOrder = ReturnOrder::create([
            'return_number' => 'RET-OVCAP-'.strtoupper(bin2hex(random_bytes(3))),
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
            'return_date' => now(),
            'status' => \Modules\Purchase\Enums\ReturnStatus::PENDING,
            'required_action' => ReturnRequiredAction::CASH_REFUND,
            'total_return_amount' => 500.0,
        ]);

        for ($i = 0; $i < 2; $i++) {
            ReturnOrderItem::create([
                'return_order_id' => $returnOrder->id,
                'purchase_order_item_id' => $orderItem->id,
                'item_name' => $this->itemA->name,
                'return_quantity' => 5.0,
                'unit_price' => 50.0,
                'return_amount' => 250.0,
                'quality_reason' => InspectionQuality::POOR->value,
            ]);
        }

        $caughtCode = null;
        $caughtStatus = null;
        try {
            app(ReturnManagementService::class)->confirmHandover($returnOrder->id, $this->manager->id);
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $caughtCode = $e->errorCode;
            $caughtStatus = $e->status;
        }

        $this->assertSame('RETURN_EXCEEDS_NET_RECEIVED', $caughtCode);
        $this->assertSame(422, $caughtStatus);

        $returnOrder->refresh();
        $this->assertNull($returnOrder->handed_over_at);

        $movementsCount = InventoryStockMovement::where('source_type', 'purchase_return_handover')
            ->where('source_id', $returnOrder->id)
            ->count();
        $this->assertSame(0, $movementsCount);

        $this->assertEquals(8.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertEquals(8.0, (float) BranchItem::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('quantity'));
    }

    private function race(array $firstInput, array $secondInput): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'stage3_mysql_race_');
        unlink($barrier);
        $this->barriers[] = $barrier;

        $first = $this->worker($firstInput + ['hold' => true, 'signal' => $barrier.'.a', 'release' => $barrier]);
        $this->awaitSignal($barrier.'.a.held', $first);

        $second = $this->worker($secondInput + ['signal' => $barrier.'.b']);
        $this->awaitSignal($barrier.'.b.started', $second);

        usleep(250000);
        $this->assertTrue($second->isRunning(), 'The competing MySQL transaction must wait on the locked row.');

        file_put_contents($barrier, 'release');
        $first->wait();
        $second->wait();

        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());

        return [
            json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR),
            json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function worker(array $input): Process
    {
        $worker = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=1G',
            base_path('tests/Support/PurchaseStage3MySqlConcurrencyWorker.php'),
            json_encode($input, JSON_THROW_ON_ERROR),
        ], base_path());
        $worker->setTimeout(25);
        $worker->setEnv([
            'APP_ENV' => 'testing',
            'STAGE3_MYSQL_TESTS' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => DB::connection()->getConfig('host'),
            'DB_PORT' => (string) DB::connection()->getConfig('port'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'DB_USERNAME' => DB::connection()->getConfig('username'),
            'DB_PASSWORD' => DB::connection()->getConfig('password'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ]);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function awaitSignal(string $path, Process $worker): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('MySQL concurrency barrier not reached: '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
        }
    }
}
