<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Supplier\Models\Supplier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Stage 1 Oracle MySQL 8.4.11 / InnoDB (REPEATABLE READ) Concurrency Suite.
 *
 * Opt-in only: requires PURCHASE_STAGE1_MYSQL_TESTS=1 and a dedicated isolated
 * `assab_stage1_*` database on `127.0.0.1:3310` (does NOT use RefreshDatabase and
 * NEVER touches Stage 0 D4 `assab_stage0_d4_*` schemas).
 */
class PurchaseStage1MySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $tempPaths = [];

    private array $createdCompanyIds = [];

    private array $createdBranchIds = [];

    private array $createdManagerIds = [];

    private array $createdSupplierIds = [];

    private array $createdItemIds = [];

    private array $createdOrderIds = [];

    private array $createdOperationIds = [];

    private array $createdProcurementUserIds = [];

    private array $createdIdempotencyKeys = [];

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $bm;

    private Supplier $supplier;

    private string $bmToken;

    private Item $itemA;

    private Item $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PURCHASE_STAGE1_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('NOT_RUN: Stage 1 MySQL concurrency suite requires PURCHASE_STAGE1_MYSQL_TESTS=1.');
        }

        $db = DB::connection();
        $dbName = (string) $db->getDatabaseName();
        $host = (string) $db->getConfig('host');
        $port = (string) $db->getConfig('port');

        if (
            $db->getDriverName() !== 'mysql'
            || ! in_array($host, ['127.0.0.1', 'localhost'], true)
            || $port !== '3310'
            || preg_match('/^assab_stage1_[a-z0-9_]+$/', $dbName) !== 1
        ) {
            throw new \LogicException("SAFETY GUARD BLOCKED: Refusing Stage 1 concurrency test outside '{$host}:{$port}/{$dbName}'.");
        }

        $meta = $db->getPdo()->query('SELECT CONNECTION_ID() AS cid, DATABASE() AS db_name, USER() AS db_user, VERSION() AS version, @@default_storage_engine AS engine, @@transaction_isolation AS iso')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($dbName, (string) ($meta['db_name'] ?? ''));
        $this->assertMatchesRegularExpression('/^8\.4\.11(?:\b|$)/', (string) ($meta['version'] ?? ''));
        $this->assertStringNotContainsStringIgnoringCase('MariaDB', (string) ($meta['version'] ?? ''));
        $this->assertSame('InnoDB', (string) ($meta['engine'] ?? ''));
        $this->assertSame('REPEATABLE-READ', strtoupper((string) ($meta['iso'] ?? '')));

        $participatingTables = [
            'purchase_orders',
            'purchase_order_items',
            'goods_receipts',
            'goods_receipt_items',
            'purchase_invoices',
            'purchase_variances',
            'order_documents',
            'branch_inventory',
            'branch_item',
            'inventory_stock_movements',
            'inventory_monthly_costs',
            'asab_command_idempotency_keys',
            'asab_command_idempotency_owners',
            'asab_operations',
            'asab_approval_steps',
            'asab_users',
            'asab_user_roles',
            'asab_company_users',
            'asab_brands',
            'branches',
        ];
        $placeholders = implode(',', array_fill(0, count($participatingTables), '?'));
        $tableEngines = DB::select(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$placeholders})",
            array_merge([$dbName], $participatingTables)
        );
        $engineMap = [];
        foreach ($tableEngines as $row) {
            $engineMap[(string) ($row->TABLE_NAME ?? $row->table_name)] = (string) ($row->ENGINE ?? $row->engine);
        }
        foreach ($participatingTables as $tbl) {
            $this->assertArrayHasKey($tbl, $engineMap, "Missing participating Stage 1 table: {$tbl}");
            $this->assertSame('InnoDB', $engineMap[$tbl], "Table {$tbl} must use InnoDB engine.");
        }

        $tag = bin2hex(random_bytes(5));
        $this->company = AsabCompany::create(['name' => 'S1 Co '.$tag, 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'S1 Brand '.$tag, 'sub_status' => 'active', 'status' => 'active']);

        $this->branch = Branch::factory()->create([
            'name' => 'S1 Branch '.$tag, 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);
        $this->bm = BranchManager::factory()->create([
            'branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active',
        ]);
        $this->bmToken = $this->bm->createToken('stage1-mysql-worker')->plainTextToken;

        $this->supplier = Supplier::create([
            'name' => 'S1 Supplier '.$tag,
            'email' => "s1-sup-{$tag}@test.local",
            'password' => 'secret123',
            'is_active' => true,
        ]);

        $this->itemA = Item::create(['name' => 'S1 Item Alpha '.$tag, 'unit' => 'kg', 'is_active' => true]);
        $this->itemB = Item::create(['name' => 'S1 Item Beta '.$tag, 'unit' => 'kg', 'is_active' => true]);

        foreach ([$this->itemA, $this->itemB] as $item) {
            BranchItem::create([
                'branch_id' => $this->branch->id,
                'item_id' => $item->id,
                'price' => 25.00,
                'quantity' => 0,
            ]);
        }

        $this->createdCompanyIds[] = $this->company->id;
        $this->createdBranchIds[] = $this->branch->id;
        $this->createdManagerIds[] = $this->bm->id;
        $this->createdSupplierIds[] = $this->supplier->id;
        $this->createdItemIds[] = $this->itemA->id;
        $this->createdItemIds[] = $this->itemB->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                foreach (glob($path.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempPaths = [];

        foreach ($this->workers as $worker) {
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->workers = [];

        try {
            if ($this->createdIdempotencyKeys !== []) {
                $keyHashes = array_map(static fn (string $k): string => hash('sha256', $k), $this->createdIdempotencyKeys);
                DB::table('asab_command_idempotency_keys')->whereIn('idempotency_key', $this->createdIdempotencyKeys)->delete();
                DB::table('asab_command_idempotency_owners')->whereIn('key_hash', $keyHashes)->delete();
            }
            if ($this->createdOrderIds !== []) {
                $receiptIds = DB::table('goods_receipts')->whereIn('purchase_order_id', $this->createdOrderIds)->pluck('id')->all();
                if ($receiptIds !== []) {
                    DB::table('purchase_variances')->whereIn('goods_receipt_id', $receiptIds)->delete();
                    DB::table('purchase_invoices')->whereIn('goods_receipt_id', $receiptIds)->delete();
                    DB::table('goods_receipt_items')->whereIn('goods_receipt_id', $receiptIds)->delete();
                    DB::table('inventory_stock_movements')->where('source_type', 'goods_receipt')->whereIn('source_id', $receiptIds)->delete();
                    DB::table('goods_receipts')->whereIn('id', $receiptIds)->delete();
                }
                DB::table('order_timelines')->whereIn('purchase_order_id', $this->createdOrderIds)->delete();
                DB::table('purchase_order_items')->whereIn('purchase_order_id', $this->createdOrderIds)->delete();
                DB::table('purchase_orders')->whereIn('id', $this->createdOrderIds)->delete();
            }
            if ($this->createdBranchIds !== []) {
                DB::table('inventory_monthly_costs')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('branch_inventory')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('branch_item')->whereIn('branch_id', $this->createdBranchIds)->delete();
            }
            if ($this->createdManagerIds !== []) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', BranchManager::class)
                    ->whereIn('tokenable_id', $this->createdManagerIds)
                    ->delete();
                DB::table('branch_managers')->whereIn('id', $this->createdManagerIds)->delete();
            }
            if ($this->createdSupplierIds !== []) {
                DB::table('suppliers')->whereIn('id', $this->createdSupplierIds)->delete();
            }
            if ($this->createdItemIds !== []) {
                DB::table('items')->whereIn('id', $this->createdItemIds)->delete();
            }
            if ($this->createdBranchIds !== []) {
                DB::table('branches')->whereIn('id', $this->createdBranchIds)->delete();
            }
            if ($this->createdCompanyIds !== []) {
                $companyOpIds = DB::table('asab_operations')->whereIn('company_id', $this->createdCompanyIds)->pluck('id')->all();
                if ($companyOpIds !== []) {
                    DB::table('asab_approval_steps')->whereIn('operation_id', $companyOpIds)->delete();
                    DB::table('asab_operations')->whereIn('id', $companyOpIds)->delete();
                }
            }
            if ($this->createdOperationIds !== []) {
                DB::table('asab_approval_steps')->whereIn('operation_id', $this->createdOperationIds)->delete();
                DB::table('asab_operations')->whereIn('id', $this->createdOperationIds)->delete();
            }
            if ($this->createdProcurementUserIds !== []) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', AsabUser::class)
                    ->whereIn('tokenable_id', $this->createdProcurementUserIds)
                    ->delete();
                DB::table('asab_company_users')->whereIn('user_id', $this->createdProcurementUserIds)->delete();
                DB::table('asab_user_roles')->whereIn('user_id', $this->createdProcurementUserIds)->delete();
                DB::table('asab_users')->whereIn('id', $this->createdProcurementUserIds)->delete();
            }
            if ($this->createdCompanyIds !== []) {
                DB::table('asab_brands')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_companies')->whereIn('id', $this->createdCompanyIds)->delete();
            }
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    private function createConfirmedOrder(array $lines, OrderType $orderType = OrderType::DIRECT_SUPPLIER): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += $line['qty'] * $line['price'];
        }
        $order = PurchaseOrder::create([
            'order_number' => 'PO-S1-'.strtoupper(Str::random(8)),
            'order_type' => $orderType,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->bm->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => round($subtotal, 2),
            'tax_rate' => 15.00,
            'tax_amount' => round($subtotal * 0.15, 2),
            'total_amount' => round($subtotal * 1.15, 2),
        ]);
        $this->createdOrderIds[] = $order->id;

        $orderItems = [];
        foreach ($lines as $line) {
            $item = $line['item'];
            $orderItems[] = PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'quantity_ordered' => $line['qty'],
                'quantity_confirmed' => $line['qty'],
                'unit_price' => $line['price'],
                'total_price' => round($line['qty'] * $line['price'], 2),
                'unit_of_measurement' => 'kg',
                'status' => OrderItemStatus::CONFIRMED,
            ]);
        }

        return ['order' => $order, 'items' => $orderItems];
    }

    private function makeBarrierDir(): string
    {
        $dir = sys_get_temp_dir().'/s1_mysql_race_'.Str::uuid();
        mkdir($dir, 0700, true);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    private function spawnWorker(array $input): Process
    {
        $worker = new Process([
            PHP_BINARY,
            base_path('tests/Support/PurchaseStage1MySqlConcurrencyWorker.php'),
            json_encode($input, JSON_THROW_ON_ERROR),
        ], base_path());
        $worker->setTimeout(25);
        $worker->setEnv([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'PURCHASE_STAGE1_MYSQL_TESTS' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) DB::connection()->getConfig('host'),
            'DB_PORT' => (string) DB::connection()->getConfig('port'),
            'DB_DATABASE' => (string) DB::connection()->getDatabaseName(),
            'DB_USERNAME' => (string) DB::connection()->getConfig('username'),
            'DB_PASSWORD' => (string) DB::connection()->getConfig('password'),
            'DB_URL' => '',
            'DATABASE_URL' => '',
            'DB_SOCKET' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function awaitFile(string $path, Process ...$watchWorkers): void
    {
        $deadline = microtime(true) + 15.0;
        while (! is_file($path)) {
            foreach ($watchWorkers as $w) {
                if (! $w->isRunning()) {
                    $this->fail('Worker exited before reaching barrier '.$path.': '.$w->getErrorOutput().$w->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for barrier file: '.$path);
            }
            usleep(10000);
        }
    }

    private function decodeWorkerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), 'Worker failed: '.$worker->getErrorOutput().$worker->getOutput());
        $decoded = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame((string) DB::connection()->getDatabaseName(), (string) ($decoded['database'] ?? ''));
        $this->assertMatchesRegularExpression('/^8\.4\.11(?:\b|$)/', (string) ($decoded['version'] ?? ''));
        $this->assertSame('InnoDB', $decoded['engine']);
        $this->assertSame('REPEATABLE-READ', strtoupper($decoded['isolation']));
        fwrite(STDERR, sprintf(
            "WORKER cid=%d db=%s user=%s version=%s engine=%s iso=%s http=%d elapsed_ms=%s\n",
            (int) $decoded['connection_id'],
            (string) ($decoded['database'] ?? ''),
            (string) ($decoded['user'] ?? ''),
            (string) ($decoded['version'] ?? ''),
            (string) $decoded['engine'],
            (string) $decoded['isolation'],
            (int) $decoded['http_status'],
            (string) ($decoded['elapsed_ms'] ?? '')
        ));

        return $decoded;
    }

    public function test_s1_1_same_key_receipt_replay_is_serialized_on_mysql(): void
    {
        ['order' => $order, 'items' => [$poItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 10.0, 'price' => 25.0],
        ]);
        $key = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $key;

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';
        $payload = [
            'items' => [['item_id' => $poItem->id, 'quantity_received' => 10, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ];

        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'idempotency_key' => $key,
            'payload' => $payload,
            'signal' => $dir.'/w1',
            'hold_hook' => 'goods_receipt_creating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'idempotency_key' => $key,
            'payload' => $payload,
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        $r2 = $this->decodeWorkerResult($w2);

        file_put_contents($release, 'go');
        $r1 = $this->decodeWorkerResult($w1);

        $this->assertNotSame($r1['connection_id'], $r2['connection_id']);
        $this->assertSame(201, $r1['http_status']);
        $this->assertSame(409, $r2['http_status'], 'Concurrent in-flight duplicate key must return 409.');
        $receiptId1 = (string) ($r1['body']['data']['id'] ?? '');
        $this->assertNotEmpty($receiptId1);

        // Post-commit same-key replay from a third independent MySQL connection must return stored response + identical receipt ID
        $w3 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'idempotency_key' => $key,
            'payload' => $payload,
            'signal' => $dir.'/w3',
        ]);
        $r3 = $this->decodeWorkerResult($w3);

        $this->assertNotSame($r1['connection_id'], $r3['connection_id']);
        $this->assertSame(201, $r3['http_status'], 'Post-commit replay must return stored 201 HTTP status.');
        $receiptId3 = (string) ($r3['body']['data']['id'] ?? '');
        $this->assertSame($receiptId1, $receiptId3, 'Replayed same-key request must return the identical GoodsReceipt ID.');
        $this->assertSame(1, GoodsReceipt::where('purchase_order_id', $order->id)->count());
        $this->assertEquals(10.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
    }

    public function test_s1_2_same_order_partial_contention_tracks_cumulative_qty_without_overshoot(): void
    {
        ['order' => $order, 'items' => [$poItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 10.0, 'price' => 25.0],
        ]);
        $k1 = (string) Str::uuid();
        $k2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $k1;
        $this->createdIdempotencyKeys[] = $k2;

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';

        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'idempotency_key' => $k1,
            'payload' => [
                'items' => [['item_id' => $poItem->id, 'quantity_received' => 4, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ],
            'signal' => $dir.'/w1',
            'hold_hook' => 'goods_receipt_creating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'idempotency_key' => $k2,
            'payload' => [
                'items' => [['item_id' => $poItem->id, 'quantity_received' => 4, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(200000);
        file_put_contents($release, 'go');

        $r1 = $this->decodeWorkerResult($w1);
        $r2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($r1['connection_id'], $r2['connection_id']);
        $this->assertSame(201, $r1['http_status']);
        $this->assertSame(201, $r2['http_status']);

        $poItem->refresh();
        $this->assertEquals(8.0, (float) $poItem->quantity_received);
        $this->assertEquals(8.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
    }

    public function test_s1_3_rollback_under_contention_leaves_zero_orphan_stock_or_cost(): void
    {
        ['order' => $failOrder, 'items' => [$failItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 10.0, 'price' => 25.0],
        ]);
        ['order' => $okOrder, 'items' => [$okItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 6.0, 'price' => 25.0],
        ]);

        $dir = $this->makeBarrierDir();
        $startBarrier = $dir.'/start';

        $wFail = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$failOrder->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'items' => [['item_id' => $failItem->id, 'quantity_received' => 5, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
                'variance' => ['action' => 'accept'],
            ],
            'signal' => $dir.'/wfail',
            'fail_hook' => 'purchase_variance_creating',
            'fail_n' => 1,
            'start_barrier' => $startBarrier,
        ]);
        $wOk = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$okOrder->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'items' => [['item_id' => $okItem->id, 'quantity_received' => 6, 'quality' => 'normal']],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-S1-ROLL-OK',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 150.00,
                    'tax_rate' => 15,
                ],
            ],
            'signal' => $dir.'/wok',
            'start_barrier' => $startBarrier,
        ]);

        $this->awaitFile($dir.'/wfail.started', $wFail);
        $this->awaitFile($dir.'/wok.started', $wOk);
        file_put_contents($startBarrier, 'go');

        $rFail = $this->decodeWorkerResult($wFail);
        $rOk = $this->decodeWorkerResult($wOk);

        $this->assertNotSame($rFail['connection_id'], $rOk['connection_id']);
        $this->assertGreaterThanOrEqual(400, $rFail['http_status']);
        $this->assertSame(201, $rOk['http_status']);
        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $failOrder->id)->count());
        $this->assertEquals(6.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));

        $monthly = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertEquals(6.0, (float) $monthly->purchases_qty);
        $this->assertSame(15000, (int) $monthly->purchases_value_halalas);
    }

    public function test_s1_4_overlapping_different_orders_serialize_stock_and_monthly_weighted_cost(): void
    {
        ['order' => $order1, 'items' => [$poItem1]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 6.0, 'price' => 20.0],
        ]);
        ['order' => $order2, 'items' => [$poItem2]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 4.0, 'price' => 30.0],
        ]);

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';

        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order1->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'items' => [['item_id' => $poItem1->id, 'quantity_received' => 6, 'quality' => 'normal']],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-S1-OVL-1',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 120.00,
                    'tax_rate' => 15,
                ],
            ],
            'signal' => $dir.'/w1',
            'hold_hook' => 'monthly_cost_saving',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/orders/{$order2->id}/start",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'items' => [['item_id' => $poItem2->id, 'quantity_received' => 4, 'quality' => 'normal']],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-S1-OVL-2',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 120.00,
                    'tax_rate' => 15,
                ],
            ],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(200000);
        file_put_contents($release, 'go');

        $r1 = $this->decodeWorkerResult($w1);
        $r2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($r1['connection_id'], $r2['connection_id']);
        $this->assertSame(201, $r1['http_status']);
        $this->assertSame(201, $r2['http_status']);

        $this->assertEquals(10.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $monthly = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);
        $this->assertSame(24000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(2400, (int) $monthly->unit_cost_halalas);
    }

    public function test_s1_5_concurrent_invoice_adjustments_and_deduction_cap_serialize_on_mysql(): void
    {
        ['order' => $order, 'items' => [$poItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 10.0, 'price' => 30.0],
        ]);
        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $poItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-S1-CAP-0',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 240.00,
                    'tax_rate' => 0,
                ],
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();
        $variance = PurchaseVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $receiptItem->id,
            'purchase_order_id' => $order->id,
            'item_name' => $this->itemA->name,
            'variance_type' => 'short',
            'quantity_ordered' => 10,
            'quantity_received' => 8,
            'quantity_variance' => 2,
            'unit_price' => 30,
            'variance_amount' => 60,
            'status' => 'pending',
        ]);
        $varianceB = PurchaseVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $receiptItem->id,
            'purchase_order_id' => $order->id,
            'item_name' => $this->itemA->name,
            'variance_type' => 'damage',
            'quantity_ordered' => 10,
            'quantity_received' => 8,
            'quantity_variance' => 2,
            'unit_price' => 30,
            'variance_amount' => 60,
            'status' => 'pending',
        ]);

        // Two concurrent workers try to deduct 160 each from a 240.00 invoice -> second MUST hit cap and never exceed 240.00!
        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';

        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/variances/{$variance->id}/action",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => ['action' => 'deduct_from_invoice', 'amount' => 160.00, 'reason' => 'short_quantity'],
            'signal' => $dir.'/w1',
            'hold_hook' => 'purchase_invoice_updating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/variances/{$varianceB->id}/action",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => ['action' => 'deduct_from_invoice', 'amount' => 160.00, 'reason' => 'damaged_quality'],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(200000);
        file_put_contents($release, 'go');

        $r1 = $this->decodeWorkerResult($w1);
        $r2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($r1['connection_id'], $r2['connection_id']);
        $this->assertSame(200, $r1['http_status']);
        $this->assertSame(422, $r2['http_status']);

        $invoice = PurchaseInvoice::where('goods_receipt_id', $receipt->id)->firstOrFail();
        $this->assertEquals(160.00, (float) $invoice->deduction_amount);
        $this->assertEquals(80.00, (float) $invoice->final_amount);
    }

    private function createProcurementActor(): array
    {
        $procUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'S1 MySQL Procurement',
            'email' => 's1-mysql-proc-'.bin2hex(random_bytes(4)).'@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $this->createdProcurementUserIds[] = $procUser->id;
        AsabUserRole::create([
            'user_id' => $procUser->id,
            'role_key' => 'procurement',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'user_id' => $procUser->id,
            'company_id' => $this->company->id,
            'role_key' => 'procurement',
            'status' => 'active',
        ]);
        $token = $procUser->createToken('stage1-mysql-proc')->plainTextToken;

        return [$procUser, $token];
    }

    private function approveReceiptViaHttp(GoodsReceipt $receipt, AsabUser $actor, string $reason): array
    {
        $review = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")
            ->assertOk()
            ->json();

        $res = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals", [
                'context_hash' => $review['context_hash'],
                'receipt_item_ids' => array_column($review['lines'], 'receipt_item_id'),
                'reason' => $reason,
            ])
            ->assertOk();

        $opData = $res->json();
        if (! empty($opData['id'])) {
            $this->createdOperationIds[] = $opData['id'];
        }

        return $opData;
    }

    public function test_s1_6_concurrent_invoice_value_revisions_serialize_on_mysql(): void
    {
        ['order' => $order, 'items' => [$poItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 10.0, 'price' => 30.0],
        ], OrderType::VIA_PURCHASING_OFFICER);

        // Initial delivery_note receiving posts 10 @ 30 = 30000 halalas provisional cost
        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $poItem->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        [$procUser] = $this->createProcurementActor();

        // Real HTTP invoice + procurement price approval for 350.00, then 275.00
        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-S1-VAL-350',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);
        $this->approveReceiptViaHttp($receipt, $procUser, 'Authorize 350 revision on MySQL');

        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-S1-VAL-275',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 275.00,
                'tax_rate' => 15,
            ]);
        $this->approveReceiptViaHttp($receipt, $procUser, 'Authorize 275 revision on MySQL');

        $monthlyInit = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame(27500, (int) $monthlyInit->purchases_value_halalas);

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';

        // Competing concurrent invoice value revisions (350 vs 275) serialize on receipt/invoice row locks
        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/{$receipt->id}/invoice",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'invoice_number' => 'INV-S1-VAL-REV',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ],
            'signal' => $dir.'/w1',
            'hold_hook' => 'purchase_invoice_updating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/{$receipt->id}/invoice",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'invoice_number' => 'INV-S1-VAL-REV',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 275.00,
                'tax_rate' => 15,
            ],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(200000);
        file_put_contents($release, 'go');

        $r1 = $this->decodeWorkerResult($w1);
        $r2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($r1['connection_id'], $r2['connection_id']);
        $this->assertTrue(in_array($r1['http_status'], [200, 201], true), 'Worker 1 status '.$r1['http_status']);
        $this->assertTrue(in_array($r2['http_status'], [200, 201], true), 'Worker 2 status '.$r2['http_status']);

        $invoice = PurchaseInvoice::where('goods_receipt_id', $receipt->id)->firstOrFail();
        $this->assertEquals(275.00, (float) $invoice->amount_before_tax);

        $totalValue = (int) InventoryStockMovement::where('source_type', 'goods_receipt')
            ->where('source_id', $receipt->id)
            ->sum('total_cost_halalas');
        $totalQty = (float) InventoryStockMovement::where('source_type', 'goods_receipt')
            ->where('source_id', $receipt->id)
            ->sum('qty_delta');
        $this->assertSame(27500, $totalValue);
        $this->assertEquals(10.0, $totalQty);

        $monthly = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame(27500, (int) $monthly->purchases_value_halalas);
        $this->assertSame(2750, (int) $monthly->unit_cost_halalas);
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);

        // Subsequent 275 -> 350 with real HTTP approval + exact replay 350 -> 350 preserve serialized ledger totals
        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-S1-VAL-REV',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ]);
        $this->approveReceiptViaHttp($receipt, $procUser, 'Authorize final 350 revision on MySQL');

        // Replay identical 350 invoice must not duplicate movements or alter 35000
        $replayInv = $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-S1-VAL-REV',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ]);
        $this->assertTrue(in_array($replayInv->getStatusCode(), [200, 201], true));

        $this->assertSame(
            35000,
            (int) InventoryStockMovement::where('source_type', 'goods_receipt')->where('source_id', $receipt->id)->sum('total_cost_halalas')
        );
        $monthly->refresh();
        $this->assertSame(35000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(3500, (int) $monthly->unit_cost_halalas);
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);
        $this->assertSame(0, (int) $receipt->fresh()->pending_cost_delta_halalas);
    }

    public function test_s1_7_concurrent_price_approval_vs_invoice_revision_and_replay_serialize_on_mysql(): void
    {
        ['order' => $order, 'items' => [$poItem]] = $this->createConfirmedOrder([
            ['item' => $this->itemA, 'qty' => 10.0, 'price' => 30.0],
        ], OrderType::VIA_PURCHASING_OFFICER);

        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $poItem->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();
        [$procUser, $procToken] = $this->createProcurementActor();

        // Upload 350.00 invoice (unapproved: stays 30000)
        $this->actingAs($this->bm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-S1-7-350',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);

        $review350 = $this->actingAs($procUser, 'sanctum')
            ->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")
            ->assertOk()
            ->json();

        // Part A: Worker 1 holds procurement price approval lock while Worker 2 submits competing 380.00 invoice
        $dirA = $this->makeBarrierDir();
        $releaseA = $dirA.'/release';
        $kApprove = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $kApprove;

        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/procurement/receiving/{$receipt->id}/price-approvals",
            'method' => 'POST',
            'token' => $procToken,
            'idempotency_key' => $kApprove,
            'payload' => [
                'context_hash' => $review350['context_hash'],
                'receipt_item_ids' => [$receiptItem->id],
                'reason' => 'Concurrent approval vs invoice revision',
            ],
            'signal' => $dirA.'/w1',
            'hold_hook' => 'price_operation_creating',
            'hold_n' => 1,
            'release' => $releaseA,
        ]);
        $this->awaitFile($dirA.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/receiving/{$receipt->id}/invoice",
            'method' => 'POST',
            'token' => $this->bmToken,
            'payload' => [
                'invoice_number' => 'INV-S1-7-380',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 380.00,
                'tax_rate' => 15,
            ],
            'signal' => $dirA.'/w2',
        ]);
        $this->awaitFile($dirA.'/w2.started', $w2);
        usleep(200000);
        file_put_contents($releaseA, 'go');

        $r1 = $this->decodeWorkerResult($w1);
        $r2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($r1['connection_id'], $r2['connection_id']);
        $this->assertSame(200, $r1['http_status']);
        $this->assertTrue(in_array($r2['http_status'], [200, 201], true));
        $op350Id = (string) ($r1['body']['id'] ?? '');
        $this->assertNotEmpty($op350Id);
        $this->createdOperationIds[] = $op350Id;

        // 35000 historical approved baseline preserved while 3000 (380 - 350) stays pending
        $monthly = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame(35000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(3000, (int) $receipt->fresh()->pending_cost_delta_halalas);

        // Stale context_hash from 350 review must be rejected with 409 STALE_PRICE_REVIEW
        $this->actingAs($procUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals", [
                'context_hash' => $review350['context_hash'],
                'receipt_item_ids' => [$receiptItem->id],
                'reason' => 'Stale hash attempt',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STALE_PRICE_REVIEW');

        // Part B: Concurrent approval same-key replay & supersession across 3 independent MySQL connections
        $review380 = $this->actingAs($procUser, 'sanctum')
            ->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")
            ->assertOk()
            ->json();

        $dirB = $this->makeBarrierDir();
        $releaseB = $dirB.'/release';
        $kReplay = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $kReplay;

        $w3 = $this->spawnWorker([
            'uri' => "/api/v1/procurement/receiving/{$receipt->id}/price-approvals",
            'method' => 'POST',
            'token' => $procToken,
            'idempotency_key' => $kReplay,
            'payload' => [
                'context_hash' => $review380['context_hash'],
                'receipt_item_ids' => [$receiptItem->id],
                'reason' => 'Approve 380 superseding 350',
            ],
            'signal' => $dirB.'/w3',
            'hold_hook' => 'price_operation_creating',
            'hold_n' => 1,
            'release' => $releaseB,
        ]);
        $this->awaitFile($dirB.'/w3.held', $w3);

        $w4 = $this->spawnWorker([
            'uri' => "/api/v1/procurement/receiving/{$receipt->id}/price-approvals",
            'method' => 'POST',
            'token' => $procToken,
            'idempotency_key' => $kReplay,
            'payload' => [
                'context_hash' => $review380['context_hash'],
                'receipt_item_ids' => [$receiptItem->id],
                'reason' => 'Approve 380 superseding 350',
            ],
            'signal' => $dirB.'/w4',
        ]);
        $this->awaitFile($dirB.'/w4.started', $w4);
        $r4 = $this->decodeWorkerResult($w4);

        file_put_contents($releaseB, 'go');
        $r3 = $this->decodeWorkerResult($w3);

        $this->assertNotSame($r3['connection_id'], $r4['connection_id']);
        $this->assertSame(200, $r3['http_status']);
        $this->assertSame(409, $r4['http_status']);
        $op380Id = (string) ($r3['body']['id'] ?? '');
        $this->assertNotEmpty($op380Id);
        $this->createdOperationIds[] = $op380Id;

        // Post-commit same-key replay from a third independent MySQL connection returns identical Operation ID & once-only posting
        $w5 = $this->spawnWorker([
            'uri' => "/api/v1/procurement/receiving/{$receipt->id}/price-approvals",
            'method' => 'POST',
            'token' => $procToken,
            'idempotency_key' => $kReplay,
            'payload' => [
                'context_hash' => $review380['context_hash'],
                'receipt_item_ids' => [$receiptItem->id],
                'reason' => 'Approve 380 superseding 350',
            ],
            'signal' => $dirB.'/w5',
        ]);
        $r5 = $this->decodeWorkerResult($w5);

        $this->assertNotSame($r3['connection_id'], $r5['connection_id']);
        $this->assertSame(200, $r5['http_status']);
        $this->assertSame($op380Id, (string) ($r5['body']['id'] ?? ''));

        $monthly->refresh();
        $this->assertSame(38000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(3800, (int) $monthly->unit_cost_halalas);
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);
        $this->assertSame(0, (int) $receipt->fresh()->pending_cost_delta_halalas);
        $this->assertSame(
            38000,
            (int) InventoryStockMovement::where('source_type', 'goods_receipt')->where('source_id', $receipt->id)->sum('total_cost_halalas')
        );

        // Prior 350 decision was superseded; only 1 active approved Operation exists
        $this->assertSame(Operation::STATUS_REJECTED, Operation::withoutGlobalScopes()->whereKey($op350Id)->value('status'));
        $this->assertSame('superseded', Operation::withoutGlobalScopes()->whereKey($op350Id)->value('reject_reason'));
        $this->assertSame(
            1,
            Operation::withoutGlobalScopes()->where('source_id', $receipt->id)->where('status', Operation::STATUS_APPROVED)->count()
        );
    }
}
