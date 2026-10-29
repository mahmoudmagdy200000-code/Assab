<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\EmployeeLedgerService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ASSAB ERP — ELM-1 & ELM-2 Cross-Module Employee Ledger Concurrency Proof.
 *
 * Verifies transaction serialization, row lock exclusivity, and financial atomicity
 * across independent modules writing to the shared asab_employee_movements ledger:
 *
 * - ELM-1: EmployeeLedgerService::approveSettlement() concurrently with
 *          InventoryReconciliationService::allocate().
 * - ELM-2: EmployeeLedgerService::approveSettlement() concurrently with
 *          ShortagePostingService::reconcile() (with employee liability).
 *
 * Invariants proven across both execution orders:
 * 1. Zero lost updates: Row lock on Employee serializes writes.
 * 2. Zero duplicate financial movements: One-to-one mapping per event.
 * 3. Exact final balance integrity:
 *    - If Settlement commits first: debt is cleared to 0, then secondary debit applies on top.
 *    - If Secondary debit commits first: debt increases, Settlement detects fluctuation under lock
 *      and aborts with 409 SETTLEMENT_BALANCE_CHANGED with safe transaction rollback.
 * 4. Multi-connection isolation: Real independent PDO processes with different CONNECTION_ID().
 */
class CrossModuleEmployeeLedgerConcurrencyTest extends TestCase
{
    private array $workers = [];
    private ?string $barriersDir = null;
    private array $createdCompanyIds = [];
    private array $createdBranchIds = [];
    private array $createdEmployeeIds = [];
    private array $createdOperationIds = [];
    private array $createdShiftIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        // Strict Fail-Closed Environment Guard: Prevent execution against any non-disposable or non-local database
        $dbName = (string) DB::connection()->getDatabaseName();
        if (! preg_match('/^assab_proof_elm_[0-9]+_[0-9]+$/', $dbName)
            && ! preg_match('/^assab_proof_elm_/', $dbName)
            && ! preg_match('/^assab_proof_be09_/', $dbName)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Concurrency tests are strictly restricted to disposable schema pattern 'assab_proof_*', got '{$dbName}'.");
        }

        $defaultConn = config('database.default');
        $host = (string) config("database.connections.{$defaultConn}.host", env('DB_HOST', '127.0.0.1'));
        $allowedHosts = ['127.0.0.1', 'localhost', 'mysql', '::1'];
        if (! in_array(strtolower($host), $allowedHosts, true)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Concurrency tests can only run against a local loopback/container host, got '{$host}'.");
        }

        foreach (['prod', 'production', 'staging', 'live', 'coolify', 'railway', 'hostinger', 'shared'] as $blocked) {
            if (str_contains(strtolower($host), $blocked) || str_contains(strtolower($dbName), $blocked)) {
                throw new \RuntimeException("SAFETY GUARD BLOCKED: Host or database name contains forbidden keyword '{$blocked}'.");
            }
        }

        $this->createdCompanyIds = [];
        $this->createdBranchIds = [];
        $this->createdEmployeeIds = [];
        $this->createdOperationIds = [];
        $this->createdShiftIds = [];

        $this->barriersDir = sys_get_temp_dir().'/elm-concurr-'.Str::uuid();
        @mkdir($this->barriersDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->barriersDir && is_dir($this->barriersDir)) {
            @file_put_contents($this->barriersDir.'/start.signal', 'release');
        }

        foreach ($this->workers as $worker) {
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->workers = [];

        if ($this->barriersDir && is_dir($this->barriersDir)) {
            foreach (glob($this->barriersDir.'/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->barriersDir);
            $this->barriersDir = null;
        }

        // Guaranteed cleanup in reverse foreign key order (leaves zero debris for subsequent tests)
        try {
            if (! empty($this->createdShiftIds)) {
                DB::table('shift_liability_postings')->whereIn('cashier_shift_id', $this->createdShiftIds)->delete();
                DB::table('shift_liability_posting_states')->whereIn('branch_id', $this->createdBranchIds)->delete();
                $allocIds = DB::table('shift_liability_allocations')->whereIn('cashier_shift_id', $this->createdShiftIds)->pluck('id');
                DB::table('shift_liability_shares')->whereIn('allocation_id', $allocIds)->delete();
                DB::table('shift_liability_allocations')->whereIn('cashier_shift_id', $this->createdShiftIds)->delete();
                $aggIds = DB::table('shift_report_aggregates')->whereIn('source_id', $this->createdShiftIds)->pluck('id');
                DB::table('shift_report_revisions')->whereIn('report_aggregate_id', $aggIds)->delete();
                DB::table('shift_report_aggregates')->whereIn('source_id', $this->createdShiftIds)->delete();
                DB::table('cashier_shifts')->whereIn('id', $this->createdShiftIds)->delete();
                $this->createdShiftIds = [];
            }

            if (! empty($this->createdCompanyIds)) {
                DB::table('asab_employee_settlement_requests')->whereIn('company_id', $this->createdCompanyIds)->delete();
            }

            if (! empty($this->createdEmployeeIds)) {
                DB::table('asab_employee_settlement_requests')->whereIn('employee_id', $this->createdEmployeeIds)->delete();
                DB::table('asab_employee_movements')->whereIn('employee_id', $this->createdEmployeeIds)->delete();
                DB::table('asab_employees')->whereIn('id', $this->createdEmployeeIds)->delete();
                $this->createdEmployeeIds = [];
            }

            if (! empty($this->createdOperationIds)) {
                DB::table('asab_operations')->whereIn('id', $this->createdOperationIds)->delete();
                $this->createdOperationIds = [];
            }

            if (! empty($this->createdBranchIds)) {
                DB::table('cashiers')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('shifts')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('branch_managers')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('branches')->whereIn('id', $this->createdBranchIds)->delete();
                $this->createdBranchIds = [];
            }

            if (! empty($this->createdCompanyIds)) {
                DB::table('asab_brands')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_user_roles')->whereIn('user_id', function ($q) {
                    $q->select('id')->from('asab_users')->whereIn('company_id', $this->createdCompanyIds);
                })->delete();
                DB::table('asab_users')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_companies')->whereIn('id', $this->createdCompanyIds)->delete();
                $this->createdCompanyIds = [];
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Asserts that the created fixtures are physically visible from a separate, fresh PDO connection
     * (proving real COMMIT, not uncommitted buffer).
     */
    private function assertFixtureVisibleFromIndependentConnection(string $table, string $idColumn, string $idValue): void
    {
        $defaultConn = config('database.default');
        $connConfig = config("database.connections.{$defaultConn}", []);

        $host = (string) ($connConfig['host'] ?? env('DB_HOST', '127.0.0.1'));
        $port = (int) ($connConfig['port'] ?? env('DB_PORT', 3306));
        $db = (string) ($connConfig['database'] ?? env('DB_DATABASE'));
        $user = (string) ($connConfig['username'] ?? env('DB_USERNAME', 'root'));
        $pass = (string) ($connConfig['password'] ?? env('DB_PASSWORD', ''));

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$idColumn}` = ?");
        $stmt->execute([$idValue]);
        $row = $stmt->fetch();

        $this->assertGreaterThan(
            0,
            (int) ($row['c'] ?? 0),
            "Fixture row in '{$table}' ({$idColumn}={$idValue}) must be physically committed and visible from an independent PDO connection before spawning workers."
        );
    }

    /**
     * Spawns an independent OS PHP worker process connected to the disposable database.
     */
    private function spawnWorker(string $workerId, string $action, array $envParams = []): Process
    {
        $code = <<<'PHP'
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$barrierDir = getenv('ELM_BARRIER_DIR');
$workerId = getenv('ELM_WORKER_ID');
$action = getenv('ELM_ACTION');
$signalFile = 'start.signal';

$connId = null;
try {
    // Fail-closed guard: ensure worker targets an authorized disposable schema
    $expectedDb = getenv('DB_DATABASE');
    if (!$expectedDb || (!preg_match('/^assab_proof_elm_[0-9]+_[0-9]+$/', $expectedDb) && !preg_match('/^assab_proof_elm_/', $expectedDb) && !preg_match('/^assab_proof_be09_/', $expectedDb))) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'EXCEPTION',
            'code' => 'SAFETY_GUARD_BLOCKED',
            'message' => 'Worker database must match pattern assab_proof_*',
            'conn' => null,
        ]));
        exit(1);
    }

    $expectedConn = getenv('DB_CONNECTION') ?: 'mysql';
    $expectedHost = getenv('DB_HOST');
    $expectedPort = getenv('DB_PORT');
    $expectedUser = getenv('DB_USERNAME');
    $expectedPass = getenv('DB_PASSWORD');

    if ($expectedConn) {
        config(['database.default' => $expectedConn]);
    }
    config(["database.connections.{$expectedConn}.database" => $expectedDb]);
    if ($expectedHost) {
        config(["database.connections.{$expectedConn}.host" => $expectedHost]);
    }
    if ($expectedPort) {
        config(["database.connections.{$expectedConn}.port" => $expectedPort]);
    }
    if ($expectedUser) {
        config(["database.connections.{$expectedConn}.username" => $expectedUser]);
    }
    if ($expectedPass !== false) {
        config(["database.connections.{$expectedConn}.password" => $expectedPass]);
    }
    DB::purge();
    DB::reconnect();

    $connId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($barrierDir . '/' . $workerId . '.conn', (string) $connId);
} catch (\Throwable $e) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'EXCEPTION',
        'code' => get_class($e),
        'message' => 'Connection failed: ' . $e->getMessage(),
        'conn' => null,
    ]));
    exit(1);
}

// Await start barrier signal
$deadline = microtime(true) + 30;
while (!is_file($barrierDir . '/' . $signalFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'TIMEOUT',
            'code' => 'BARRIER_TIMEOUT',
            'message' => 'Worker timed out waiting for barrier signal: ' . $signalFile,
            'conn' => $connId,
        ]));
        exit(1);
    }
    usleep(5000);
}

// Execute the concurrent financial operation
try {
    if ($action === 'approve_settlement') {
        $settlementId = getenv('ELM_SETTLEMENT_ID');
        $reviewerId = getenv('ELM_REVIEWER_ID');
        $reviewer = Modules\Admin\Models\AsabUser::findOrFail($reviewerId);
        $res = app(Modules\Admin\Services\EmployeeLedgerService::class)->approveSettlement($settlementId, $reviewer);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'inventory_allocate') {
        $companyId = getenv('ELM_COMPANY_ID');
        $branchId = getenv('ELM_BRANCH_ID');
        $date = getenv('ELM_DATE');
        $userId = getenv('ELM_USER_ID');
        $allocations = json_decode(getenv('ELM_ALLOCATIONS_JSON'), true) ?: [];
        $res = app(Modules\Admin\Services\InventoryReconciliationService::class)->allocate($companyId, $branchId, $date, $allocations, $userId);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'shortage_reconcile') {
        $shiftId = getenv('ELM_SHIFT_ID');
        $actorId = getenv('ELM_ACTOR_ID');
        $actorType = getenv('ELM_ACTOR_TYPE') ?: 'branch_manager';
        $shift = Modules\Shift\Models\CashierShift::findOrFail($shiftId);
        $actor = $actorType === 'asab_user'
            ? Modules\Admin\Models\AsabUser::findOrFail($actorId)
            : Modules\BranchManagers\Models\BranchManager::findOrFail($actorId);
        $res = app(Modules\Shift\Services\ShortagePostingService::class)->reconcile(
            $shift,
            $actor,
            expectedRevision: 1,
            expectedAllocationVersion: 1,
            operationId: (string) Illuminate\Support\Str::uuid()
        );
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } else {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'EXCEPTION',
            'code' => 'UNKNOWN_ACTION',
            'message' => 'Action ' . $action . ' is not recognized',
            'conn' => $connId,
        ]));
    }
} catch (Modules\Admin\Exceptions\AsabException $e) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'ERROR',
        'code' => $e->errorCode,
        'message' => $e->getMessage(),
        'http_status' => $e->status,
        'conn' => $connId,
    ]));
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $h) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'ERROR',
        'code' => $h->getMessage(),
        'message' => $h->getMessage(),
        'http_status' => $h->getStatusCode(),
        'conn' => $connId,
    ]));
} catch (\Throwable $t) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'EXCEPTION',
        'code' => get_class($t),
        'message' => $t->getMessage(),
        'conn' => $connId,
    ]));
}
PHP;

        $defaultConn = config('database.default');
        $connConfig = config("database.connections.{$defaultConn}", []);
        $dbEnv = [
            'DB_CONNECTION' => (string) $defaultConn,
            'DB_HOST' => (string) ($connConfig['host'] ?? env('DB_HOST', '127.0.0.1')),
            'DB_PORT' => (string) ($connConfig['port'] ?? env('DB_PORT', '3306')),
            'DB_DATABASE' => (string) ($connConfig['database'] ?? env('DB_DATABASE')),
            'DB_USERNAME' => (string) ($connConfig['username'] ?? env('DB_USERNAME')),
            'DB_PASSWORD' => (string) ($connConfig['password'] ?? env('DB_PASSWORD')),
        ];

        $process = new Process([PHP_BINARY, '-r', $code], base_path(), array_merge([
            'ELM_BARRIER_DIR' => $this->barriersDir,
            'ELM_WORKER_ID' => $workerId,
            'ELM_ACTION' => $action,
        ], $dbEnv, $envParams));

        $process->setTimeout(30);
        $process->start();
        $this->workers[$workerId] = $process;

        return $process;
    }

    private function awaitWorkerResult(string $workerId): array
    {
        $deadline = microtime(true) + 30;
        $file = $this->barriersDir.'/'.$workerId.'.result';
        $process = $this->workers[$workerId] ?? null;

        while (! is_file($file)) {
            if ($process instanceof Process && ! $process->isRunning()) {
                $err = $process->getErrorOutput();
                $out = $process->getOutput();
                $this->fail("Worker {$workerId} terminated prematurely (exit code {$process->getExitCode()}): Output: {$out} | Error: {$err}");
            }
            if (microtime(true) > $deadline) {
                $err = $process instanceof Process ? $process->getErrorOutput() : 'N/A';
                $out = $process instanceof Process ? $process->getOutput() : 'N/A';
                $this->fail("Worker {$workerId} timed out waiting for result file. Output: {$out} | Error: {$err}");
            }
            usleep(10000);
        }

        $decoded = json_decode(file_get_contents($file), true) ?? [];
        if (($decoded['status'] ?? null) === 'EXCEPTION') {
            $this->fail("Worker {$workerId} encountered unexpected exception: [{$decoded['code']}] {$decoded['message']}");
        }

        return $decoded;
    }

    private function releaseSignal(): void
    {
        file_put_contents($this->barriersDir.'/start.signal', 'go');
    }

    private function resetSignal(): void
    {
        @unlink($this->barriersDir.'/start.signal');
    }

    // =========================================================================
    // ELM-1: approveSettlement() vs InventoryReconciliationService::allocate()
    // =========================================================================

    /**
     * Helper to setup shared ELM-1 fixtures.
     */
    private function setupElm1Fixtures(string $companyPrefix = 'ELM1'): array
    {
        $company = AsabCompany::create(['name' => "{$companyPrefix} Co", 'subdomain' => 'elm1-'.Str::random(6)]);
        $this->createdCompanyIds[] = $company->id;

        $branch = Branch::create(['name' => 'Main', 'location' => 'Riyadh', 'city' => 'Riyadh', 'asab_company_id' => $company->id]);
        $this->createdBranchIds[] = $branch->id;

        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Tariq Mansoor',
            'role' => 'كاشير',
            'emp_number' => 'EMP-'.rand(1000, 9999),
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        // Standing debt: -5000 halalas (-50.00 SAR)
        EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'advance',
            'ref' => 'ADV-'.Str::random(8),
            'description' => 'سلفة أولية',
            'movement_date' => now(),
            'created_by_id' => (string) Str::uuid(),
        ]);

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Acc', 'email' => 'acc_'.Str::random(5).'@test.com', 'password' => 'x', 'status' => 'active']);
        $head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head', 'email' => 'head_'.Str::random(5).'@test.com', 'password' => 'x', 'status' => 'active']);

        $ledger = app(EmployeeLedgerService::class);
        $settlement = $ledger->settleBalance($employee, null, $accountant, 'تسوية كاملة مستحقة');
        $settlementId = $settlement['id'];

        $today = now()->toDateString();
        $op = Operation::create([
            'public_id' => 'INV-'.rand(1000, 9999),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'inventory',
            'amount' => 0,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => $today,
            'payload' => [
                'items' => [
                    ['itemId' => 'sku-1', 'name' => 'منتج تجريبي', 'expectedQty' => 10, 'actualQty' => 8, 'unitPriceHalalas' => 1000],
                ],
            ],
        ]);
        $this->createdOperationIds[] = $op->id;

        $allocations = [
            [
                'itemId' => 'sku-1',
                'allocations' => [
                    ['employeeId' => (string) $employee->id, 'qty' => 2], // 2 * 1000 = 2000 halalas debt
                ],
            ],
        ];

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0 before spawning workers');
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employee->id);
        $this->assertFixtureVisibleFromIndependentConnection('asab_employee_settlement_requests', 'id', $settlementId);
        $this->assertFixtureVisibleFromIndependentConnection('asab_operations', 'id', $op->id);

        return compact('company', 'branch', 'employee', 'accountant', 'head', 'ledger', 'settlementId', 'op', 'today', 'allocations');
    }

    /**
     * ELM-1 Case A (Simultaneous Race under Row Lock Exclusivity):
     * Both approveSettlement and Inventory allocate race concurrently across 2 independent PDO connections.
     * Lock Invariant: Employee row lock serializes writes. Exactly one sequence resolves:
     * - If Inventory commits first: settlement rejects with 409 SETTLEMENT_BALANCE_CHANGED; balance = -7000.
     * - If Settlement commits first: debt is zeroed (credit 5000), inventory adds 2000 debit; balance = -2000.
     * Zero lost updates, zero double credits, 100% mathematical integrity.
     */
    public function test_elm1_concurrent_race_inventory_allocation_vs_settlement_approval(): void
    {
        $f = $this->setupElm1Fixtures('ELM1_Race');

        $this->resetSignal();
        $this->spawnWorker('w_settle', 'approve_settlement', [
            'ELM_SETTLEMENT_ID' => $f['settlementId'],
            'ELM_REVIEWER_ID' => $f['head']->id,
        ]);
        $this->spawnWorker('w_inv', 'inventory_allocate', [
            'ELM_COMPANY_ID' => $f['company']->id,
            'ELM_BRANCH_ID' => $f['branch']->id,
            'ELM_DATE' => $f['today'],
            'ELM_USER_ID' => $f['accountant']->id,
            'ELM_ALLOCATIONS_JSON' => json_encode($f['allocations']),
        ]);

        $this->releaseSignal();

        $resSettle = $this->awaitWorkerResult('w_settle');
        $resInv = $this->awaitWorkerResult('w_inv');

        $this->assertNotSame($resSettle['conn'], $resInv['conn'], 'Workers must use independent MySQL connection IDs');
        $this->assertSame('OK', $resInv['status'], 'Inventory allocation must succeed');

        $empId = $f['employee']->id;
        $finalBalance = $f['ledger']->currentBalance($empId);

        if ($resSettle['status'] === 'ERROR') {
            // Inventory committed first: balance changed from -5000 to -7000; Settlement detected fluctuation
            $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resSettle['code']);
            $this->assertSame(409, $resSettle['http_status']);
            $this->assertSame(0, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count(), 'Zero settlement movements posted');
            $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'inventory_variance')->count(), 'Inventory variance posted');
            $this->assertSame(-7000, $finalBalance, 'Balance is exactly -5000 standing - 2000 inventory = -7000');
        } else {
            // Settlement committed first: balance zeroed, then inventory added 2000 debit
            $this->assertSame('OK', $resSettle['status']);
            $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count(), 'Settlement movement posted');
            $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'inventory_variance')->count(), 'Inventory variance posted');
            $this->assertSame(-2000, $finalBalance, 'Balance is exactly 0 cleared - 2000 inventory = -2000');
        }
    }

    /**
     * ELM-1 Case B (Controlled Order 1: Inventory commits first -> Settlement rejection):
     * Proves that when Inventory changes the employee balance while a settlement is pending,
     * the settlement approval fails-closed under lock with 409 and rolls back cleanly.
     */
    public function test_elm1_order_a_inventory_first_rejects_settlement_with_409(): void
    {
        $f = $this->setupElm1Fixtures('ELM1_OrderA');

        // Worker 1 executes Inventory allocation first on independent connection
        $this->resetSignal();
        $this->spawnWorker('w_inv', 'inventory_allocate', [
            'ELM_COMPANY_ID' => $f['company']->id,
            'ELM_BRANCH_ID' => $f['branch']->id,
            'ELM_DATE' => $f['today'],
            'ELM_USER_ID' => $f['accountant']->id,
            'ELM_ALLOCATIONS_JSON' => json_encode($f['allocations']),
        ]);
        $this->releaseSignal();
        $resInv = $this->awaitWorkerResult('w_inv');
        $this->assertSame('OK', $resInv['status']);

        // Worker 2 attempts Settlement approval on separate independent connection
        $this->resetSignal();
        $this->spawnWorker('w_settle', 'approve_settlement', [
            'ELM_SETTLEMENT_ID' => $f['settlementId'],
            'ELM_REVIEWER_ID' => $f['head']->id,
        ]);
        $this->releaseSignal();
        $resSettle = $this->awaitWorkerResult('w_settle');

        $this->assertNotSame($resSettle['conn'], $resInv['conn']);
        $this->assertSame('ERROR', $resSettle['status']);
        $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resSettle['code']);
        $this->assertSame(409, $resSettle['http_status']);

        $empId = $f['employee']->id;
        $this->assertSame(0, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count());
        $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'inventory_variance')->count());
        $this->assertSame(-7000, $f['ledger']->currentBalance($empId));
    }

    /**
     * ELM-1 Case C (Controlled Order 2: Settlement commits first -> Inventory charges on top):
     * Proves that when Settlement approval commits first, standing debt is cleanly cleared to 0,
     * and subsequent Inventory allocation applies without conflict, resulting in exactly -2000 balance.
     */
    public function test_elm1_order_b_settlement_first_then_inventory_charges_cleanly(): void
    {
        $f = $this->setupElm1Fixtures('ELM1_OrderB');

        // Worker 1 executes Settlement approval first on independent connection
        $this->resetSignal();
        $this->spawnWorker('w_settle', 'approve_settlement', [
            'ELM_SETTLEMENT_ID' => $f['settlementId'],
            'ELM_REVIEWER_ID' => $f['head']->id,
        ]);
        $this->releaseSignal();
        $resSettle = $this->awaitWorkerResult('w_settle');
        $this->assertSame('OK', $resSettle['status']);

        // Worker 2 executes Inventory allocation on separate independent connection
        $this->resetSignal();
        $this->spawnWorker('w_inv', 'inventory_allocate', [
            'ELM_COMPANY_ID' => $f['company']->id,
            'ELM_BRANCH_ID' => $f['branch']->id,
            'ELM_DATE' => $f['today'],
            'ELM_USER_ID' => $f['accountant']->id,
            'ELM_ALLOCATIONS_JSON' => json_encode($f['allocations']),
        ]);
        $this->releaseSignal();
        $resInv = $this->awaitWorkerResult('w_inv');
        $this->assertSame('OK', $resInv['status']);

        $this->assertNotSame($resSettle['conn'], $resInv['conn']);

        $empId = $f['employee']->id;
        $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count());
        $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'inventory_variance')->count());
        $this->assertSame(-2000, $f['ledger']->currentBalance($empId));
    }

    // =========================================================================
    // ELM-2: approveSettlement() vs ShortagePostingService::reconcile()
    // =========================================================================

    /**
     * Helper to setup shared ELM-2 fixtures.
     */
    private function setupElm2Fixtures(string $companyPrefix = 'ELM2'): array
    {
        $company = AsabCompany::create(['name' => "{$companyPrefix} Co", 'subdomain' => 'elm2-'.Str::random(6)]);
        $this->createdCompanyIds[] = $company->id;

        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'Brand',
            'abbr' => 'BRD',
            'status' => 'active',
            'sub_status' => 'active',
        ]);

        $branch = Branch::create([
            'name' => 'Branch 2',
            'location' => 'Riyadh',
            'city' => 'Riyadh',
            'asab_company_id' => $company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->createdBranchIds[] = $branch->id;

        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Rashid Al-Dosari',
            'role' => 'كاشير',
            'emp_number' => 'EMP-'.rand(1000, 9999),
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        // Standing debt: -5000 halalas
        EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'advance',
            'ref' => 'ADV-'.Str::random(8),
            'description' => 'سلفة أولية',
            'movement_date' => now(),
            'created_by_id' => (string) Str::uuid(),
        ]);

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Acc', 'email' => 'acc_'.Str::random(5).'@test.com', 'password' => 'x', 'status' => 'active']);
        $head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head', 'email' => 'head_'.Str::random(5).'@test.com', 'password' => 'x', 'status' => 'active']);

        $ledger = app(EmployeeLedgerService::class);
        $settlement = $ledger->settleBalance($employee, null, $accountant, 'تسوية كاملة مستحقة');
        $settlementId = $settlement['id'];

        // Shift fixtures for ShortagePostingService
        $shiftTemplate = Shift::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $manager = BranchManager::where('branch_id', $branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $branch->id]);

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '40.00',
            'cash_collected' => '45.00',
            'closing_balance' => '45.00',
            'variance' => -15.00, // Shortage: -1500 halalas
        ]);
        $this->createdShiftIds[] = $cashierShift->id;

        // Create Report Aggregate & Revision 1
        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $cashierShift->id,
            'current_revision_number' => 1,
        ]);
        $aggregate->revisions()->create([
            'revision_number' => 1,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $manager->id,
        ]);

        // Create approved liability allocation assigning -1500 halalas to $employee
        $alloc = ShiftLiabilityAllocation::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cashier_shift_id' => $cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -1500,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $manager->id,
            'manager_approved_at' => now(),
            'reason' => 'Approved shortage allocation for employee',
        ]);
        $alloc->shares()->create([
            'id' => (string) Str::uuid(),
            'responsible_type' => 'employee',
            'responsible_id' => $employee->id,
            'amount_halalas' => 1500,
        ]);

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0 before spawning workers');
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employee->id);
        $this->assertFixtureVisibleFromIndependentConnection('asab_employee_settlement_requests', 'id', $settlementId);
        $this->assertFixtureVisibleFromIndependentConnection('cashier_shifts', 'id', $cashierShift->id);

        return compact('company', 'branch', 'employee', 'accountant', 'head', 'ledger', 'settlementId', 'manager', 'cashierShift');
    }

    /**
     * ELM-2 Case A (Simultaneous Race under Row Lock Exclusivity):
     * approveSettlement and ShortagePostingService::reconcile race concurrently on independent connections.
     * Lock Invariant: Employee row lock serializes writes. Exactly one sequence resolves:
     * - If Shortage commits first: settlement rejects with 409 SETTLEMENT_BALANCE_CHANGED; balance = -6500.
     * - If Settlement commits first: debt is cleared to 0, then shortage debit 1500 applies; balance = -1500.
     * Zero lost updates, zero double credits, 100% mathematical integrity.
     */
    public function test_elm2_concurrent_race_shortage_reconcile_vs_settlement_approval(): void
    {
        $f = $this->setupElm2Fixtures('ELM2_Race');

        $this->resetSignal();
        $this->spawnWorker('w_settle', 'approve_settlement', [
            'ELM_SETTLEMENT_ID' => $f['settlementId'],
            'ELM_REVIEWER_ID' => $f['head']->id,
        ]);
        $this->spawnWorker('w_shortage', 'shortage_reconcile', [
            'ELM_SHIFT_ID' => $f['cashierShift']->id,
            'ELM_ACTOR_ID' => $f['manager']->id,
            'ELM_ACTOR_TYPE' => 'branch_manager',
        ]);

        $this->releaseSignal();

        $resSettle = $this->awaitWorkerResult('w_settle');
        $resShortage = $this->awaitWorkerResult('w_shortage');

        $this->assertNotSame($resSettle['conn'], $resShortage['conn'], 'Workers must use independent MySQL connection IDs');
        $this->assertSame('OK', $resShortage['status'], 'Shortage reconcile must succeed');

        $empId = $f['employee']->id;
        $finalBalance = $f['ledger']->currentBalance($empId);

        if ($resSettle['status'] === 'ERROR') {
            // Shortage committed first: balance changed from -5000 to -6500; Settlement detected fluctuation
            $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resSettle['code']);
            $this->assertSame(409, $resSettle['http_status']);
            $this->assertSame(0, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count(), 'Zero settlement movements posted');
            $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'cash_variance')->count(), 'Cash variance posted');
            $this->assertSame(-6500, $finalBalance, 'Balance is exactly -5000 standing - 1500 shortage = -6500');
        } else {
            // Settlement committed first: balance zeroed, then shortage added 1500 debit
            $this->assertSame('OK', $resSettle['status']);
            $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count(), 'Settlement movement posted');
            $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'cash_variance')->count(), 'Cash variance posted');
            $this->assertSame(-1500, $finalBalance, 'Balance is exactly 0 cleared - 1500 shortage = -1500');
        }
    }

    /**
     * ELM-2 Case B (Controlled Order 1: Shortage commits first -> Settlement rejection):
     * Proves that when Shortage posts cash variance debit while settlement is pending,
     * settlement approval fails-closed under lock with 409 and rolls back cleanly.
     */
    public function test_elm2_order_a_shortage_first_rejects_settlement_with_409(): void
    {
        $f = $this->setupElm2Fixtures('ELM2_OrderA');

        // Worker 1 executes Shortage reconcile first on independent connection
        $this->resetSignal();
        $this->spawnWorker('w_shortage', 'shortage_reconcile', [
            'ELM_SHIFT_ID' => $f['cashierShift']->id,
            'ELM_ACTOR_ID' => $f['manager']->id,
            'ELM_ACTOR_TYPE' => 'branch_manager',
        ]);
        $this->releaseSignal();
        $resShortage = $this->awaitWorkerResult('w_shortage');
        $this->assertSame('OK', $resShortage['status']);

        // Worker 2 attempts Settlement approval on separate independent connection
        $this->resetSignal();
        $this->spawnWorker('w_settle', 'approve_settlement', [
            'ELM_SETTLEMENT_ID' => $f['settlementId'],
            'ELM_REVIEWER_ID' => $f['head']->id,
        ]);
        $this->releaseSignal();
        $resSettle = $this->awaitWorkerResult('w_settle');

        $this->assertNotSame($resSettle['conn'], $resShortage['conn']);
        $this->assertSame('ERROR', $resSettle['status']);
        $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resSettle['code']);
        $this->assertSame(409, $resSettle['http_status']);

        $empId = $f['employee']->id;
        $this->assertSame(0, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count());
        $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'cash_variance')->count());
        $this->assertSame(-6500, $f['ledger']->currentBalance($empId));
    }

    /**
     * ELM-2 Case C (Controlled Order 2: Settlement commits first -> Shortage charges on top):
     * Proves that when Settlement approval commits first, standing debt is cleanly cleared to 0,
     * and subsequent Shortage reconciliation applies without conflict, resulting in exactly -1500 balance.
     */
    public function test_elm2_order_b_settlement_first_then_shortage_charges_cleanly(): void
    {
        $f = $this->setupElm2Fixtures('ELM2_OrderB');

        // Worker 1 executes Settlement approval first on independent connection
        $this->resetSignal();
        $this->spawnWorker('w_settle', 'approve_settlement', [
            'ELM_SETTLEMENT_ID' => $f['settlementId'],
            'ELM_REVIEWER_ID' => $f['head']->id,
        ]);
        $this->releaseSignal();
        $resSettle = $this->awaitWorkerResult('w_settle');
        $this->assertSame('OK', $resSettle['status']);

        // Worker 2 executes Shortage reconcile on separate independent connection
        $this->resetSignal();
        $this->spawnWorker('w_shortage', 'shortage_reconcile', [
            'ELM_SHIFT_ID' => $f['cashierShift']->id,
            'ELM_ACTOR_ID' => $f['manager']->id,
            'ELM_ACTOR_TYPE' => 'branch_manager',
        ]);
        $this->releaseSignal();
        $resShortage = $this->awaitWorkerResult('w_shortage');
        $this->assertSame('OK', $resShortage['status']);

        $this->assertNotSame($resSettle['conn'], $resShortage['conn']);

        $empId = $f['employee']->id;
        $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'settlement')->count());
        $this->assertSame(1, EmployeeMovement::where('employee_id', $empId)->where('category', 'cash_variance')->count());
        $this->assertSame(-1500, $f['ledger']->currentBalance($empId));
    }
}
