<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InternalTransferVariance;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Stage 0 D4 Oracle MySQL 8.4.11 / InnoDB (REPEATABLE READ) Concurrency Suite.
 *
 * Opt-in only: requires PURCHASE_STAGE0_MYSQL_TESTS=1 and a dedicated disposable
 * `assab_stage0_d4_*` database on `127.0.0.1:3310` (does NOT use RefreshDatabase so
 * committed fixtures are visible across independent worker PDO connections).
 */
class PurchaseStage0MySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $tempPaths = [];

    private array $createdCompanyIds = [];

    private array $createdBranchIds = [];

    private array $createdManagerIds = [];

    private array $createdItemIds = [];

    private array $createdOrderIds = [];

    private array $createdIdempotencyKeys = [];

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $requestingBranch;

    private Branch $sendingBranch;

    private BranchManager $requestingBm;

    private BranchManager $sendingBm;

    private string $sendingToken;

    private Item $itemA;

    private Item $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PURCHASE_STAGE0_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('NOT_RUN: Stage 0 D4 MySQL concurrency suite requires PURCHASE_STAGE0_MYSQL_TESTS=1.');
        }

        $db = DB::connection();
        $dbName = (string) $db->getDatabaseName();
        $host = (string) $db->getConfig('host');
        $port = (string) $db->getConfig('port');

        if (
            $db->getDriverName() !== 'mysql'
            || ! in_array($host, ['127.0.0.1', 'localhost'], true)
            || $port !== '3310'
            || preg_match('/^assab_stage0_d4_[a-z0-9_]+$/', $dbName) !== 1
        ) {
            throw new \LogicException("SAFETY GUARD BLOCKED: Refusing D4 concurrency test outside '{$host}:{$port}/{$dbName}'.");
        }

        $meta = $db->getPdo()->query('SELECT VERSION() AS version, @@default_storage_engine AS engine, @@transaction_isolation AS iso')->fetch(\PDO::FETCH_ASSOC);
        $this->assertMatchesRegularExpression('/^8\.4\.[0-9]+/', (string) ($meta['version'] ?? ''));
        $this->assertStringNotContainsStringIgnoringCase('MariaDB', (string) ($meta['version'] ?? ''));
        $this->assertSame('InnoDB', (string) ($meta['engine'] ?? ''));
        $this->assertSame('REPEATABLE-READ', strtoupper((string) ($meta['iso'] ?? '')));

        $tag = bin2hex(random_bytes(5));
        $this->company = AsabCompany::create(['name' => 'D4 Co '.$tag, 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'D4 Brand '.$tag, 'sub_status' => 'active', 'status' => 'active']);

        $this->requestingBranch = Branch::factory()->create([
            'name' => 'D4 Requesting '.$tag, 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);
        $this->sendingBranch = Branch::factory()->create([
            'name' => 'D4 Sending '.$tag, 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);

        $this->requestingBm = BranchManager::factory()->create([
            'branch_id' => $this->requestingBranch->id, 'is_active' => true, 'status' => 'active',
        ]);
        $this->sendingBm = BranchManager::factory()->create([
            'branch_id' => $this->sendingBranch->id, 'is_active' => true, 'status' => 'active',
        ]);
        $this->sendingToken = $this->sendingBm->createToken('stage0-d4-worker')->plainTextToken;

        $this->itemA = Item::create(['name' => 'D4 Item Alpha '.$tag, 'unit' => 'kg', 'is_active' => true]);
        $this->itemB = Item::create(['name' => 'D4 Item Beta '.$tag, 'unit' => 'kg', 'is_active' => true]);

        $this->createdCompanyIds[] = $this->company->id;
        $this->createdBranchIds[] = $this->requestingBranch->id;
        $this->createdBranchIds[] = $this->sendingBranch->id;
        $this->createdManagerIds[] = $this->requestingBm->id;
        $this->createdManagerIds[] = $this->sendingBm->id;
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
                DB::table('internal_transfer_variances')->whereIn('purchase_order_id', $this->createdOrderIds)->delete();
                DB::table('inventory_stock_movements')->whereIn('source_id', $this->createdOrderIds)->delete();
                DB::table('order_timelines')->whereIn('purchase_order_id', $this->createdOrderIds)->delete();
                DB::table('purchase_order_items')->whereIn('purchase_order_id', $this->createdOrderIds)->delete();
                DB::table('purchase_orders')->whereIn('id', $this->createdOrderIds)->delete();
            }
            if ($this->createdBranchIds !== []) {
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
            if ($this->createdItemIds !== []) {
                DB::table('items')->whereIn('id', $this->createdItemIds)->delete();
            }
            if ($this->createdBranchIds !== []) {
                DB::table('branches')->whereIn('id', $this->createdBranchIds)->delete();
            }
            if ($this->createdCompanyIds !== []) {
                DB::table('asab_brands')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_companies')->whereIn('id', $this->createdCompanyIds)->delete();
            }
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    private function seedSendingStock(Item $item, float $qty): void
    {
        BranchItem::create([
            'branch_id' => $this->sendingBranch->id,
            'item_id' => $item->id,
            'price' => 40,
            'quantity' => $qty,
        ]);
        BranchInventory::create([
            'branch_id' => $this->sendingBranch->id,
            'item_id' => $item->id,
            'available_quantity' => $qty,
            'minimum_stock' => 2,
        ]);
    }

    /**
     * @param  array<int, array{item: Item, qty: float}>  $lines
     * @return array{order: PurchaseOrder, items: array<int, PurchaseOrderItem>}
     */
    private function createInternalTransfer(array $lines): array
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'TR-D4-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->requestingBranch->id,
            'to_branch_id' => $this->requestingBranch->id,
            'from_branch_id' => $this->sendingBranch->id,
            'requested_by' => $this->requestingBm->id,
        ]);
        $this->createdOrderIds[] = $order->id;

        $items = [];
        foreach ($lines as $line) {
            $item = $line['item'];
            $qty = $line['qty'];
            $items[] = PurchaseOrderItem::factory()->create([
                'purchase_order_id' => $order->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'status' => OrderItemStatus::PENDING,
                'quantity_ordered' => $qty,
                'unit_price' => 40,
                'total_price' => $qty * 40,
            ]);
        }

        return ['order' => $order, 'items' => $items];
    }

    private function workerEvidence(array $result): array
    {
        return [
            'connection_id' => $result['connection_id'],
            'version' => $result['version'],
            'engine' => $result['engine'],
            'isolation' => $result['isolation'],
            'http_status' => $result['http_status'],
            'elapsed_ms' => $result['elapsed_ms'],
        ];
    }

    private function recordD4Evidence(string $scenario, array $evidence): void
    {
        $directory = getenv('PURCHASE_STAGE0_MYSQL_EVIDENCE_DIR');
        if (! is_string($directory) || $directory === '' || ! is_dir($directory)) {
            return;
        }

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$scenario.'.json';
        file_put_contents($path, json_encode($evidence + ['recorded_at_utc' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    }

    private function makeBarrierDir(): string
    {
        $dir = sys_get_temp_dir().'/d4_mysql_race_'.Str::uuid();
        mkdir($dir, 0700, true);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    private function spawnWorker(array $input): Process
    {
        $worker = new Process([
            PHP_BINARY,
            base_path('tests/Support/PurchaseStage0MySqlConcurrencyWorker.php'),
            json_encode($input, JSON_THROW_ON_ERROR),
        ], base_path());
        $worker->setTimeout(25);
        $worker->setEnv([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'PURCHASE_STAGE0_MYSQL_TESTS' => '1',
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
        $this->assertSame('InnoDB', $decoded['engine']);
        $this->assertSame('REPEATABLE-READ', strtoupper($decoded['isolation']));

        return $decoded;
    }

    /**
     * D4 Scenario 1: Same transfer decision + same Idempotency-Key under concurrent cold & in-flight HTTP races.
     */
    public function test_d4_1_same_transfer_decision_same_idempotency_key_is_serialized_on_mysql(): void
    {
        // Part A: In-flight concurrent duplicate while Worker 1 holds InnoDB locks inside DB::transaction
        $this->seedSendingStock($this->itemA, 50.0);
        ['order' => $order1] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 10.0]]);
        $keyA = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $keyA;

        $dirA = $this->makeBarrierDir();
        $releaseA = $dirA.'/release';
        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order1->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $keyA,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dirA.'/w1',
            'hold_hook' => 'variance_creating',
            'hold_n' => 1,
            'release' => $releaseA,
        ]);
        $this->awaitFile($dirA.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order1->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $keyA,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dirA.'/w2',
        ]);
        $res2 = $this->decodeWorkerResult($w2);

        file_put_contents($releaseA, 'go');
        $res1 = $this->decodeWorkerResult($w1);

        $this->assertNotSame($res1['connection_id'], $res2['connection_id']);
        $this->assertSame(200, $res1['http_status']);
        $this->assertSame(409, $res2['http_status']);
        $this->assertSame(OrderStatus::FULLY_APPROVED, $order1->fresh()->status);
        $this->assertSame(1, InventoryStockMovement::where('source_id', $order1->id)->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, InternalTransferVariance::where('purchase_order_id', $order1->id)->count());
        $this->assertEquals(40.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));

        // Part B: Simultaneous cold-key reservation race on a fresh order & fresh key (both fire at exact barrier)
        ['order' => $order2] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 5.0]]);
        $keyB = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $keyB;

        $dirB = $this->makeBarrierDir();
        $startBarrier = $dirB.'/start';
        $w3 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order2->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $keyB,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dirB.'/w3',
            'start_barrier' => $startBarrier,
        ]);
        $w4 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order2->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $keyB,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dirB.'/w4',
            'start_barrier' => $startBarrier,
        ]);
        $this->awaitFile($dirB.'/w3.started', $w3);
        $this->awaitFile($dirB.'/w4.started', $w4);
        file_put_contents($startBarrier, 'go');

        $res3 = $this->decodeWorkerResult($w3);
        $res4 = $this->decodeWorkerResult($w4);
        $this->assertNotSame($res3['connection_id'], $res4['connection_id']);
        $statuses = [$res3['http_status'], $res4['http_status']];
        sort($statuses);
        $this->assertTrue(
            $statuses === [200, 409] || $statuses === [200, 200],
            'Expected [200, 409] or [200, 200] (replay), got '.json_encode($statuses).' details: '.json_encode([$res3, $res4])
        );
        $this->assertSame(1, InventoryStockMovement::where('source_id', $order2->id)->where('movement_type', 'transfer_out')->count());
        $this->assertEquals(35.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
    }

    /**
     * D4 Scenario 2: Same transfer decision + different Idempotency-Keys serialized by PurchaseOrder::lockForUpdate().
     */
    public function test_d4_2_same_transfer_decision_different_keys_blocks_and_deducts_once(): void
    {
        $this->seedSendingStock($this->itemA, 50.0);
        ['order' => $order] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 10.0]]);
        $key1 = (string) Str::uuid();
        $key2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $key1;
        $this->createdIdempotencyKeys[] = $key2;

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';
        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $key1,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w1',
            'hold_hook' => 'variance_creating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $key2,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.connected', $w2);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(300000);
        $this->assertTrue($w2->isRunning(), 'Worker 2 with different key must block on PurchaseOrder::lockForUpdate().');

        file_put_contents($release, 'go');
        $res1 = $this->decodeWorkerResult($w1);
        $res2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($res1['connection_id'], $res2['connection_id']);
        $this->assertSame(200, $res1['http_status']);
        $this->assertSame(400, $res2['http_status']);
        $this->assertSame(OrderStatus::FULLY_APPROVED, $order->fresh()->status);
        $this->assertSame(1, InventoryStockMovement::where('source_id', $order->id)->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, InternalTransferVariance::where('purchase_order_id', $order->id)->count());
        $this->assertEquals(40.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertEquals(40.0, (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('quantity'));
    }

    /**
     * D4 requirement 04: A full approval and a competing partial approval serialize on the order row.
     */
    public function test_d4_full_approval_serializes_against_partial_approval_and_dispatches_once(): void
    {
        $this->seedSendingStock($this->itemA, 50.0);
        ['order' => $order, 'items' => $items] = $this->createInternalTransfer([
            ['item' => $this->itemA, 'qty' => 10.0],
        ]);
        $line = $items[0];
        $fullKey = (string) Str::uuid();
        $partialKey = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $fullKey;
        $this->createdIdempotencyKeys[] = $partialKey;

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';
        $full = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $fullKey,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/full',
            'hold_hook' => 'variance_creating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/full.held', $full);

        $partial = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $partialKey,
            'payload' => [
                'action' => 'partial_approve',
                'ready_time' => '1_hour',
                'items' => [['item_id' => $line->id, 'new_quantity' => 5.0]],
            ],
            'signal' => $dir.'/partial',
        ]);
        $this->awaitFile($dir.'/partial.connected', $partial);
        $this->awaitFile($dir.'/partial.started', $partial);
        usleep(300000);
        $partialStillRunningWhileFullHeld = $partial->isRunning();
        $this->assertTrue($partialStillRunningWhileFullHeld, 'Partial approval must wait on the full approval order lock.');

        file_put_contents($release, 'go');
        $fullResult = $this->decodeWorkerResult($full);
        $partialResult = $this->decodeWorkerResult($partial);

        $this->assertNotSame($fullResult['connection_id'], $partialResult['connection_id']);
        $this->assertSame(200, $fullResult['http_status']);
        $this->assertSame(400, $partialResult['http_status']);
        $this->assertSame(OrderStatus::FULLY_APPROVED, $order->fresh()->status);
        $this->assertEquals(10.0, (float) $line->fresh()->quantity_confirmed);
        $movement = InventoryStockMovement::where('source_id', $order->id)->where('movement_type', 'transfer_out')->sole();
        $this->assertEquals(-10.0, (float) $movement->qty_delta);
        $this->assertSame(1, InternalTransferVariance::where('purchase_order_id', $order->id)->count());
        $this->assertEquals(10.0, (float) InternalTransferVariance::where('purchase_order_id', $order->id)->value('dispatched_qty'));
        $this->assertEquals(40.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertEquals(40.0, (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('quantity'));

        $this->recordD4Evidence('d4-04', [
            'worker_results' => [
                'full' => $this->workerEvidence($fullResult),
                'partial' => $this->workerEvidence($partialResult),
            ],
            'overlap' => [
                'full_held_inside_transaction' => true,
                'partial_connected_before_request' => is_file($dir.'/partial.connected'),
                'partial_request_started_while_full_held' => true,
                'partial_process_still_running_while_full_held' => $partialStillRunningWhileFullHeld,
            ],
            'persisted' => [
                'order_status' => $order->fresh()->status?->value,
                'quantity_confirmed' => (float) $line->fresh()->quantity_confirmed,
                'transfer_out_count' => InventoryStockMovement::where('source_id', $order->id)->where('movement_type', 'transfer_out')->count(),
                'movement_qty_delta' => (float) $movement->qty_delta,
                'variance_count' => InternalTransferVariance::where('purchase_order_id', $order->id)->count(),
                'variance_dispatched_qty' => (float) InternalTransferVariance::where('purchase_order_id', $order->id)->value('dispatched_qty'),
                'branch_inventory_quantity' => (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'),
                'branch_item_quantity' => (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('quantity'),
            ],
        ]);
    }

    /**
     * D4 Scenario 3: Competing opposite transfer decisions (approve_all vs reject) with different keys.
     */
    public function test_d4_3_competing_opposite_decisions_approve_vs_reject_have_single_consistent_outcome(): void
    {
        $this->seedSendingStock($this->itemA, 50.0);

        // Direction A: approve_all acquires lock first and holds; reject waits on PurchaseOrder::lockForUpdate()
        ['order' => $orderA] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 10.0]]);
        $kA1 = (string) Str::uuid();
        $kA2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $kA1;
        $this->createdIdempotencyKeys[] = $kA2;

        $dirA = $this->makeBarrierDir();
        $relA = $dirA.'/release';
        $wA1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$orderA->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $kA1,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dirA.'/w1',
            'hold_hook' => 'variance_creating',
            'hold_n' => 1,
            'release' => $relA,
        ]);
        $this->awaitFile($dirA.'/w1.held', $wA1);

        $wA2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$orderA->id}/reject",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $kA2,
            'payload' => ['reason' => 'Concurrent rejection attempt'],
            'signal' => $dirA.'/w2',
        ]);
        $this->awaitFile($dirA.'/w2.started', $wA2);
        usleep(300000);
        $this->assertTrue($wA2->isRunning(), 'Reject worker must block on PurchaseOrder::lockForUpdate().');

        file_put_contents($relA, 'go');
        $rA1 = $this->decodeWorkerResult($wA1);
        $rA2 = $this->decodeWorkerResult($wA2);
        $this->assertSame(200, $rA1['http_status']);
        $this->assertSame(400, $rA2['http_status']);
        $this->assertSame(OrderStatus::FULLY_APPROVED, $orderA->fresh()->status);
        $this->assertSame(1, InventoryStockMovement::where('source_id', $orderA->id)->count());

        // Direction B: reject acquires lock first and holds; approve_all waits and fails cleanly with 0 deductions
        ['order' => $orderB] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 10.0]]);
        $kB1 = (string) Str::uuid();
        $kB2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $kB1;
        $this->createdIdempotencyKeys[] = $kB2;

        $dirB = $this->makeBarrierDir();
        $relB = $dirB.'/release';
        $wB1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$orderB->id}/reject",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $kB1,
            'payload' => ['reason' => 'Reject wins first'],
            'signal' => $dirB.'/w1',
            'hold_hook' => 'order_updating',
            'hold_n' => 1,
            'release' => $relB,
        ]);
        $this->awaitFile($dirB.'/w1.held', $wB1);

        $wB2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$orderB->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $kB2,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dirB.'/w2',
        ]);
        $this->awaitFile($dirB.'/w2.started', $wB2);
        usleep(300000);
        $this->assertTrue($wB2->isRunning(), 'Approve worker must block on PurchaseOrder::lockForUpdate().');

        file_put_contents($relB, 'go');
        $rB1 = $this->decodeWorkerResult($wB1);
        $rB2 = $this->decodeWorkerResult($wB2);
        $this->assertSame(200, $rB1['http_status']);
        $this->assertSame(400, $rB2['http_status']);
        $this->assertSame(OrderStatus::REJECTED, $orderB->fresh()->status);
        $this->assertSame(0, InventoryStockMovement::where('source_id', $orderB->id)->count());
        $this->assertEquals(40.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
    }

    /**
     * D4 Scenario 4: Competing transfers racing on the same branch_inventories row prevent overspend.
     */
    public function test_d4_4_competing_transfers_racing_on_same_branch_inventory_prevent_overspend(): void
    {
        $this->seedSendingStock($this->itemA, 50.0);
        ['order' => $order1] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 35.0]]);
        ['order' => $order2] = $this->createInternalTransfer([['item' => $this->itemA, 'qty' => 35.0]]);

        $k1 = (string) Str::uuid();
        $k2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $k1;
        $this->createdIdempotencyKeys[] = $k2;

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';
        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order1->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $k1,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w1',
            'hold_hook' => 'inventory_updating',
            'hold_n' => 1,
            'release' => $release,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$order2->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $k2,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(300000);
        $this->assertTrue($w2->isRunning(), 'Order 2 worker must block on BranchInventory::lockForUpdate().');

        file_put_contents($release, 'go');
        $res1 = $this->decodeWorkerResult($w1);
        $res2 = $this->decodeWorkerResult($w2);

        $this->assertNotSame($res1['connection_id'], $res2['connection_id']);
        $this->assertSame(200, $res1['http_status']);
        $this->assertSame(400, $res2['http_status']);
        $this->assertSame(OrderStatus::FULLY_APPROVED, $order1->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, $order2->fresh()->status);
        $this->assertSame(1, InventoryStockMovement::where('source_id', $order1->id)->count());
        $this->assertSame(0, InventoryStockMovement::where('source_id', $order2->id)->count());
        $this->assertSame(1, InternalTransferVariance::where('purchase_order_id', $order1->id)->count());
        $this->assertSame(0, InternalTransferVariance::where('purchase_order_id', $order2->id)->count());
        $this->assertEquals(15.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertEquals(15.0, (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('quantity'));
    }

    /**
     * D4 Scenario 5: Cross-order opposing item order ([A, B] vs [B, A]) must not deadlock on shared branch_inventories.
     */
    public function test_d4_5_opposing_item_order_transfers_on_shared_inventory_do_not_deadlock(): void
    {
        $this->seedSendingStock($this->itemA, 100.0);
        $this->seedSendingStock($this->itemB, 100.0);

        ['order' => $orderAB] = $this->createInternalTransfer([
            ['item' => $this->itemA, 'qty' => 10.0],
            ['item' => $this->itemB, 'qty' => 10.0],
        ]);
        ['order' => $orderBA] = $this->createInternalTransfer([
            ['item' => $this->itemB, 'qty' => 10.0],
            ['item' => $this->itemA, 'qty' => 10.0],
        ]);

        $k1 = (string) Str::uuid();
        $k2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $k1;
        $this->createdIdempotencyKeys[] = $k2;

        $dir = $this->makeBarrierDir();
        $rel1 = $dir.'/rel1';
        $rel2 = $dir.'/rel2';

        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$orderAB->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $k1,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w1',
            'hold_hook' => 'inventory_updating',
            'hold_n' => 1,
            'release' => $rel1,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$orderBA->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $k2,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w2',
            'hold_hook' => 'inventory_updating',
            'hold_n' => 1,
            'release' => $rel2,
        ]);
        $this->awaitFile($dir.'/w2.connected', $w2);
        $this->awaitFile($dir.'/w2.started', $w2);

        // Wait up to 800ms to observe whether Worker 2 acquires a DIFFERENT item lock (both .held -> cyclic risk)
        // OR blocks cleanly on Worker 1's canonical first item lock.
        $waitUntil = microtime(true) + 0.8;
        while (! is_file($dir.'/w2.held') && microtime(true) < $waitUntil) {
            usleep(20000);
        }

        $worker2HeldBeforeRelease = is_file($dir.'/w2.held');
        $worker2StillRunningBeforeRelease = false;
        if ($worker2HeldBeforeRelease) {
            // Both hold their 1st item simultaneously; release both to test real InnoDB deadlock behavior
            file_put_contents($rel1, 'go');
            file_put_contents($rel2, 'go');
        } else {
            // Canonical lock order: Worker 2 is blocked behind Worker 1's 1st item
            $worker2StillRunningBeforeRelease = $w2->isRunning();
            $this->assertTrue($worker2StillRunningBeforeRelease);
            file_put_contents($rel1, 'go');
            file_put_contents($rel2, 'go');
        }

        $res1 = $this->decodeWorkerResult($w1);
        $res2 = $this->decodeWorkerResult($w2);

        $this->assertSame(200, $res1['http_status'], 'Order AB failed: '.json_encode($res1));
        $this->assertSame(200, $res2['http_status'], 'Order BA failed: '.json_encode($res2));
        $this->assertSame(OrderStatus::FULLY_APPROVED, $orderAB->fresh()->status);
        $this->assertSame(OrderStatus::FULLY_APPROVED, $orderBA->fresh()->status);
        $this->assertEquals(80.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertEquals(80.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemB->id)->value('available_quantity'));

        $this->recordD4Evidence('d4-06', [
            'worker_results' => [
                'order_ab' => $this->workerEvidence($res1),
                'order_ba' => $this->workerEvidence($res2),
            ],
            'overlap' => [
                'order_ab_held_inside_transaction' => true,
                'order_ba_connected_before_request' => is_file($dir.'/w2.connected'),
                'order_ba_request_started_while_ab_held' => true,
                'order_ba_acquired_first_inventory_update_before_release' => $worker2HeldBeforeRelease,
                'order_ba_still_running_while_blocked' => $worker2StillRunningBeforeRelease,
            ],
            'persisted' => [
                'order_ab_status' => $orderAB->fresh()->status?->value,
                'order_ba_status' => $orderBA->fresh()->status?->value,
                'transfer_out_count' => InventoryStockMovement::whereIn('source_id', [$orderAB->id, $orderBA->id])->where('movement_type', 'transfer_out')->count(),
                'item_a_branch_inventory_quantity' => (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'),
                'item_a_branch_item_quantity' => (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('quantity'),
                'item_b_branch_inventory_quantity' => (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemB->id)->value('available_quantity'),
                'item_b_branch_item_quantity' => (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemB->id)->value('quantity'),
            ],
        ]);
    }

    /**
     * D4 Scenario 6: Failure during multi-item dispatch rolls back cleanly and restores balance for concurrent sibling.
     */
    public function test_d4_6_partial_dispatch_failure_rolls_back_atomically_for_concurrent_sibling(): void
    {
        $this->seedSendingStock($this->itemA, 50.0);
        $this->seedSendingStock($this->itemB, 50.0);

        ['order' => $failingOrder, 'items' => $failingItems] = $this->createInternalTransfer([
            ['item' => $this->itemA, 'qty' => 10.0],
            ['item' => $this->itemB, 'qty' => 10.0],
        ]);
        ['order' => $waitingOrder] = $this->createInternalTransfer([
            ['item' => $this->itemA, 'qty' => 45.0],
        ]);

        $k1 = (string) Str::uuid();
        $k2 = (string) Str::uuid();
        $this->createdIdempotencyKeys[] = $k1;
        $this->createdIdempotencyKeys[] = $k2;

        $dir = $this->makeBarrierDir();
        $release = $dir.'/release';

        // Worker 1 deducts Item A (50 -> 40), pauses on Variance #1, then fails on Variance #2 (rolling back Item A 40 -> 50)
        $w1 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$failingOrder->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $k1,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w1',
            'hold_hook' => 'variance_creating',
            'hold_n' => 1,
            'release' => $release,
            'fail_hook' => 'variance_creating',
            'fail_n' => 2,
        ]);
        $this->awaitFile($dir.'/w1.held', $w1);

        // Worker 2 wants 45 of Item A (cannot succeed unless Worker 1's Item A deduction rolls back completely!)
        $w2 = $this->spawnWorker([
            'uri' => "/api/v1/purchase/pending/{$waitingOrder->id}/approve-transfer",
            'method' => 'POST',
            'token' => $this->sendingToken,
            'idempotency_key' => $k2,
            'payload' => ['action' => 'approve_all', 'ready_time' => '1_hour'],
            'signal' => $dir.'/w2',
        ]);
        $this->awaitFile($dir.'/w2.started', $w2);
        usleep(300000);
        $this->assertTrue($w2->isRunning(), 'Waiting order worker must block on BranchInventory::lockForUpdate() held by failingOrder.');

        file_put_contents($release, 'go');
        $res1 = $this->decodeWorkerResult($w1);
        $res2 = $this->decodeWorkerResult($w2);

        $this->assertSame(500, $res1['http_status']);
        $this->assertSame(200, $res2['http_status'], 'Sibling order should succeed after rollback: '.json_encode($res2));

        $this->assertSame(OrderStatus::PENDING, $failingOrder->fresh()->status);
        $this->assertSame(OrderItemStatus::PENDING, $failingItems[0]->fresh()->status);
        $this->assertSame(OrderItemStatus::PENDING, $failingItems[1]->fresh()->status);
        $this->assertSame(0, InventoryStockMovement::where('source_id', $failingOrder->id)->count());
        $this->assertSame(0, InternalTransferVariance::where('purchase_order_id', $failingOrder->id)->count());

        $this->assertSame(OrderStatus::FULLY_APPROVED, $waitingOrder->fresh()->status);
        $this->assertSame(1, InventoryStockMovement::where('source_id', $waitingOrder->id)->count());
        $this->assertEquals(5.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertEquals(5.0, (float) BranchItem::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemA->id)->value('quantity'));
        $this->assertEquals(50.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->where('item_id', $this->itemB->id)->value('available_quantity'));
    }
}
