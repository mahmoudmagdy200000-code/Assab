<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * BE-09 / MySQL/InnoDB Concurrency Test Suite.
 *
 * Implements real multi-process concurrency with independent PDO connections
 * to MySQL/InnoDB, verifying lock hierarchy, transaction serialization,
 * and zero duplicate financial movements across concurrent operations.
 *
 * Concurrency Test Matrix:
 * - Case 1: Concurrent approvals of same settlement request (approveSettlement).
 * - Case 2: Concurrent settlement creation with identical (company_id, idempotency_key).
 * - Case 3: Concurrent advance posting (addMovement) vs full settlement approval (approveSettlement).
 * - Case 4: Concurrent employee allocation posting (post) on same operation & category.
 *
 * Multi-Connection Isolation:
 * - Does NOT use RefreshDatabase (which wraps execution in an uncommitted parent transaction).
 * - Commits fixtures directly to the disposable test database before workers start.
 * - Asserts physical fixture visibility from an independent PDO connection before spawning workers.
 * - Enforces fail-closed database guards (strictly restricted to assab_proof_be09_* on local host).
 * - Guarantees strict reverse-FK teardown cleanup and per-employee assertion scoping.
 *
 * Status on SQLite runner: NOT_RUN (skipped), preserved as SQLite Evidence until an isolated
 * disposable Oracle MySQL/InnoDB staging schema is executed.
 */
class EmployeeSettlementConcurrencyTest extends TestCase
{
    private array $workers = [];
    private ?string $barriersDir = null;
    private array $createdCompanyIds = [];
    private array $createdBranchIds = [];
    private array $createdEmployeeIds = [];
    private array $createdOperationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        // Strict Fail-Closed Environment Guard: Prevent execution against any non-disposable or non-local database
        $dbName = (string) DB::connection()->getDatabaseName();
        if (! preg_match('/^assab_proof_be09_[0-9]+_[0-9]+$/', $dbName) && ! preg_match('/^assab_proof_be09_/', $dbName)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Concurrency tests are strictly restricted to disposable schema pattern 'assab_proof_be09_*', got '{$dbName}'.");
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

        $this->barriersDir = sys_get_temp_dir().'/be09-concurr-'.Str::uuid();
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
                DB::table('branches')->whereIn('id', $this->createdBranchIds)->delete();
                $this->createdBranchIds = [];
            }

            if (! empty($this->createdCompanyIds)) {
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
        $port = (string) ($connConfig['port'] ?? env('DB_PORT', '3306'));
        $database = (string) ($connConfig['database'] ?? env('DB_DATABASE'));
        $username = (string) ($connConfig['username'] ?? env('DB_USERNAME'));
        $password = (string) ($connConfig['password'] ?? env('DB_PASSWORD'));

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 3,
        ]);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$idColumn}` = :id");
        $stmt->execute(['id' => $idValue]);
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(1, $count, "Fixture {$table}.{$idColumn}={$idValue} must be committed and visible from an independent PDO connection.");
    }

    /**
     * Helper to spawn an independent PHP worker process with its own PDO database connection.
     */
    private function spawnWorker(string $workerId, string $action, array $envParams): Process
    {
        $code = <<<'PHP'
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$barrierDir = getenv('BE09_BARRIER_DIR');
$workerId = getenv('BE09_WORKER_ID');
$action = getenv('BE09_ACTION');

$connId = null;
try {
    // Fail-closed guard: ensure worker targets an authorized disposable schema
    $expectedDb = getenv('DB_DATABASE');
    if (!$expectedDb || (!preg_match('/^assab_proof_be09_[0-9]+_[0-9]+$/', $expectedDb) && !preg_match('/^assab_proof_be09_/', $expectedDb))) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'EXCEPTION',
            'code' => 'SAFETY_GUARD_BLOCKED',
            'message' => 'Worker database must match pattern assab_proof_be09_*',
            'conn' => null,
        ]));
        exit(1);
    }

    // Configure worker process with explicit connection params from parent
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

// Await start barrier
$deadline = microtime(true) + 15;
while (!is_file($barrierDir . '/start.signal')) {
    if (microtime(true) > $deadline) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'TIMEOUT',
            'code' => 'BARRIER_TIMEOUT',
            'message' => 'Worker timed out waiting for start barrier signal',
            'conn' => $connId,
        ]));
        exit(1);
    }
    usleep(5000);
}

// Execute the concurrent financial operation
try {
    if ($action === 'approve_settlement') {
        $settlementId = getenv('BE09_SETTLEMENT_ID');
        $reviewerId = getenv('BE09_REVIEWER_ID');
        $reviewer = Modules\Admin\Models\AsabUser::findOrFail($reviewerId);
        $res = app(Modules\Admin\Services\EmployeeLedgerService::class)->approveSettlement($settlementId, $reviewer);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'create_settlement') {
        $employeeId = getenv('BE09_EMPLOYEE_ID');
        $actorId = getenv('BE09_ACTOR_ID');
        $key = getenv('BE09_IDEMP_KEY');
        $employee = Modules\Admin\Models\Employee::findOrFail($employeeId);
        $actor = Modules\Admin\Models\AsabUser::findOrFail($actorId);
        $res = app(Modules\Admin\Services\EmployeeLedgerService::class)->settleBalance($employee, 5000, $actor, 'تسوية متزامنة', $key);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'add_advance') {
        $employeeId = getenv('BE09_EMPLOYEE_ID');
        $actorId = getenv('BE09_ACTOR_ID');
        $employee = Modules\Admin\Models\Employee::findOrFail($employeeId);
        $actor = Modules\Admin\Models\AsabUser::findOrFail($actorId);
        $res = app(Modules\Admin\Services\EmployeeLedgerService::class)->addMovement($employee, [
            'movementType' => 'debit',
            'amount' => 2000,
            'category' => 'advance',
            'description' => 'سلفة إضافية أثناء الاعتماد',
        ], $actor);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'post_allocation') {
        $opId = getenv('BE09_OP_ID');
        $empId = getenv('BE09_EMP_ID');
        $amount = (int) getenv('BE09_AMOUNT');
        $actorId = getenv('BE09_ACTOR_ID');
        $op = Modules\Admin\Models\Operation::findOrFail($opId);
        $emp = Modules\Admin\Models\Employee::findOrFail($empId);
        $actor = Modules\Admin\Models\AsabUser::findOrFail($actorId);
        $res = app(Modules\Admin\Services\EmployeeAllocationService::class)->post($op, 'waste_charge', 'خصم هدر', [
            ['employee' => $emp, 'amountHalalas' => $amount],
        ], $actor);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    }
} catch (Modules\Admin\Exceptions\AsabException $e) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'ERROR', 'code' => $e->errorCode, 'message' => $e->getMessage(), 'conn' => $connId]));
} catch (\Throwable $t) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'EXCEPTION', 'code' => get_class($t), 'message' => $t->getMessage(), 'conn' => $connId]));
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
            'BE09_BARRIER_DIR' => $this->barriersDir,
            'BE09_WORKER_ID' => $workerId,
            'BE09_ACTION' => $action,
        ], $dbEnv, $envParams));

        $process->setTimeout(30);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function awaitWorkerResult(string $workerId): array
    {
        $deadline = microtime(true) + 20;
        $file = $this->barriersDir.'/'.$workerId.'.result';
        while (! is_file($file)) {
            if (microtime(true) > $deadline) {
                $this->fail("Worker {$workerId} timed out waiting for result file.");
            }
            usleep(10000);
        }

        $decoded = json_decode(file_get_contents($file), true) ?? [];
        if (($decoded['status'] ?? null) === 'EXCEPTION') {
            $this->fail("Worker {$workerId} encountered unexpected exception: [{$decoded['code']}] {$decoded['message']}");
        }

        return $decoded;
    }

    private function releaseStartSignal(): void
    {
        file_put_contents($this->barriersDir.'/start.signal', 'go');
    }

    /**
     * Case 1: Concurrent approvals of the exact same settlement request across 2 independent connections.
     * Lock Order Guarantee: Employee (lockForUpdate) -> Settlement Request (lockForUpdate).
     * Expected: Exactly 1 transaction succeeds and posts 1 movement; 2nd transaction gets 409 REQUEST_ALREADY_PROCESSED.
     */
    public function test_concurrent_approvals_of_same_settlement_request_only_one_posts_movement(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        $company = AsabCompany::create(['name' => 'Concurrency Co 1', 'subdomain' => 'concurr-'.Str::random(6)]);
        $this->createdCompanyIds[] = $company->id;
        $branch = Branch::create(['name' => 'Main', 'location' => 'Riyadh', 'city' => 'Riyadh', 'asab_company_id' => $company->id]);
        $this->createdBranchIds[] = $branch->id;
        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Salem Ali',
            'role' => 'كاشير',
            'emp_number' => 'EMP-'.rand(1000, 9999),
        ]);
        $this->createdEmployeeIds[] = $employee->id;

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

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Acc', 'email' => 'acc1@test.com', 'password' => 'x', 'status' => 'active']);
        $head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head', 'email' => 'head1@test.com', 'password' => 'x', 'status' => 'active']);

        $service = app(\Modules\Admin\Services\EmployeeLedgerService::class);
        $settlement = $service->settleBalance($employee, null, $accountant, 'تسوية كاملة');
        $settlementId = $settlement['id'];

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0 before spawning workers');
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employee->id);
        $this->assertFixtureVisibleFromIndependentConnection('asab_employee_settlement_requests', 'id', $settlementId);

        // Spawn Worker 1 and Worker 2 in independent OS processes
        $this->spawnWorker('w1', 'approve_settlement', [
            'BE09_SETTLEMENT_ID' => $settlementId,
            'BE09_REVIEWER_ID' => $head->id,
        ]);
        $this->spawnWorker('w2', 'approve_settlement', [
            'BE09_SETTLEMENT_ID' => $settlementId,
            'BE09_REVIEWER_ID' => $head->id,
        ]);

        // Release both workers simultaneously
        $this->releaseStartSignal();

        $res1 = $this->awaitWorkerResult('w1');
        $res2 = $this->awaitWorkerResult('w2');

        // Confirm independent connection IDs were used
        $this->assertNotSame($res1['conn'], $res2['conn'], 'Workers must execute on two independent database connections');

        $statuses = [$res1['status'], $res2['status']];
        $this->assertContains('OK', $statuses, 'Exactly one worker transaction must commit successfully');
        $this->assertContains('ERROR', $statuses, 'Concurrent worker transaction must be rejected');

        $failedWorker = $res1['status'] === 'ERROR' ? $res1 : $res2;
        $this->assertSame('REQUEST_ALREADY_PROCESSED', $failedWorker['code']);

        // Database assertions: exactly 1 movement created for this employee
        $this->assertSame(1, EmployeeMovement::where('employee_id', $employee->id)->where('category', 'settlement')->count(), 'Exactly 1 movement posted');
        $this->assertSame(0, $service->currentBalance($employee->id), 'Balance cleared exactly once');
    }

    /**
     * Case 2: Concurrent settlement requests submitted with identical (company_id, idempotency_key).
     * DB Constraint: UNIQUE KEY asab_emp_settle_comp_idemp_unique (company_id, idempotency_key).
     * Expected: Exactly 1 record created; concurrent insert rolls back safely without financial duplication.
     */
    public function test_concurrent_settlement_creation_with_same_idempotency_key_prevents_duplicate(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        $company = AsabCompany::create(['name' => 'Concurrency Co 2', 'subdomain' => 'concurr-'.Str::random(6)]);
        $this->createdCompanyIds[] = $company->id;
        $branch = Branch::create(['name' => 'Main', 'location' => 'Riyadh', 'city' => 'Riyadh', 'asab_company_id' => $company->id]);
        $this->createdBranchIds[] = $branch->id;
        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Khalid Omar',
            'role' => 'كاشير',
            'emp_number' => 'EMP-'.rand(1000, 9999),
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => 'debit',
            'amount' => 10000,
            'category' => 'advance',
            'ref' => 'ADV-'.Str::random(8),
            'description' => 'سلفة',
            'movement_date' => now(),
            'created_by_id' => (string) Str::uuid(),
        ]);

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Acc', 'email' => 'acc2@test.com', 'password' => 'x', 'status' => 'active']);
        $sharedKey = 'IDEMP-SETTLE-'.Str::random(12);

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0 before spawning workers');
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employee->id);

        $this->spawnWorker('w1', 'create_settlement', [
            'BE09_EMPLOYEE_ID' => $employee->id,
            'BE09_ACTOR_ID' => $accountant->id,
            'BE09_IDEMP_KEY' => $sharedKey,
        ]);
        $this->spawnWorker('w2', 'create_settlement', [
            'BE09_EMPLOYEE_ID' => $employee->id,
            'BE09_ACTOR_ID' => $accountant->id,
            'BE09_IDEMP_KEY' => $sharedKey,
        ]);

        $this->releaseStartSignal();

        $res1 = $this->awaitWorkerResult('w1');
        $res2 = $this->awaitWorkerResult('w2');

        $this->assertNotSame($res1['conn'], $res2['conn']);
        $this->assertSame('OK', $res1['status']);
        $this->assertSame('OK', $res2['status']);
        $this->assertSame($res1['res']['id'], $res2['res']['id'], 'Both workers must resolve to the exact same idempotent settlement request ID');
        $this->assertSame(1, EmployeeSettlementRequest::where('company_id', $company->id)->where('idempotency_key', $sharedKey)->count(), 'Exactly one record in DB');
    }

    /**
     * Case 3: Concurrent advance posting (addMovement) vs full settlement approval (approveSettlement).
     * Lock Order Guarantee: Both serialize on Employee::whereKey($id)->lockForUpdate().
     *
     * Invariant:
     * - If addMovement acquires lock first: advance increases debt; when approveSettlement acquires lock,
     *   it detects balance fluctuation under lock and rejects with 409 SETTLEMENT_BALANCE_CHANGED,
     *   posting ZERO settlement movements.
     * - If approveSettlement acquires lock first: it clears standing balance to 0, posts settlement movement,
     *   and commits; addMovement then acquires lock and applies advance on top of the cleared balance.
     * Under NO circumstance can dirty read occur or balance be cleared partially against full settlement intent.
     */
    public function test_concurrent_advance_and_full_settlement_approval_atomicity(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        $company = AsabCompany::create(['name' => 'Concurrency Co 3', 'subdomain' => 'concurr-'.Str::random(6)]);
        $this->createdCompanyIds[] = $company->id;
        $branch = Branch::create(['name' => 'Main', 'location' => 'Riyadh', 'city' => 'Riyadh', 'asab_company_id' => $company->id]);
        $this->createdBranchIds[] = $branch->id;
        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Ahmed Ali',
            'role' => 'كاشير',
            'emp_number' => 'EMP-'.rand(1000, 9999),
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        // Standing debt: -50.00 SAR (5000 halalas)
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

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Acc', 'email' => 'acc3@test.com', 'password' => 'x', 'status' => 'active']);
        $head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head', 'email' => 'head3@test.com', 'password' => 'x', 'status' => 'active']);

        $service = app(\Modules\Admin\Services\EmployeeLedgerService::class);
        $settlement = $service->settleBalance($employee, null, $accountant, 'تسوية كاملة معتمدة');
        $settlementId = $settlement['id'];

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0 before spawning workers');
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employee->id);
        $this->assertFixtureVisibleFromIndependentConnection('asab_employee_settlement_requests', 'id', $settlementId);

        $this->spawnWorker('w_advance', 'add_advance', [
            'BE09_EMPLOYEE_ID' => $employee->id,
            'BE09_ACTOR_ID' => $accountant->id,
        ]);
        $this->spawnWorker('w_approve', 'approve_settlement', [
            'BE09_SETTLEMENT_ID' => $settlementId,
            'BE09_REVIEWER_ID' => $head->id,
        ]);

        $this->releaseStartSignal();

        $resAdvance = $this->awaitWorkerResult('w_advance');
        $resApprove = $this->awaitWorkerResult('w_approve');

        $this->assertNotSame($resAdvance['conn'], $resApprove['conn']);

        // Mutual exclusion verification:
        if ($resApprove['status'] === 'ERROR') {
            $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resApprove['code']);
            $this->assertSame(0, EmployeeMovement::where('employee_id', $employee->id)->where('category', 'settlement')->count());
            $this->assertSame(-7000, $service->currentBalance($employee->id));
        } else {
            $this->assertSame('OK', $resApprove['status']);
            $this->assertSame(1, EmployeeMovement::where('employee_id', $employee->id)->where('category', 'settlement')->count());
            $this->assertSame(-2000, $service->currentBalance($employee->id));
        }
    }

    /**
     * Case 4: Concurrent EmployeeAllocationService::post() for the SAME Operation and Category.
     * Lock Order Guarantee: Operation (lockForUpdate) -> Employee (sorted lockForUpdate).
     *
     * Invariant:
     * - Two concurrent worker processes attempting to post allocations for the exact same Operation
     *   and category serialize on Operation lock.
     * - The second transaction unblocks, reads the latest debits from the first (bypassing stale snapshot),
     *   reverses them cleanly via compensating credit entries (REV-...), and applies its own set.
     * - Result: Exactly ONE active net charge set; zero double-charging or duplicate financial movements.
     */
    public function test_concurrent_employee_allocations_on_same_operation_serialize_and_prevent_duplicate_charges(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        $company = AsabCompany::create(['name' => 'Allocation Co', 'subdomain' => 'alloc-'.Str::random(6)]);
        $this->createdCompanyIds[] = $company->id;
        $branch = Branch::create(['name' => 'Branch 1', 'location' => 'Riyadh', 'city' => 'Riyadh', 'asab_company_id' => $company->id]);
        $this->createdBranchIds[] = $branch->id;
        $employeeA = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Emp A',
            'role' => 'كاشير',
            'emp_number' => 'E1',
        ]);
        $employeeB = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Emp B',
            'role' => 'كاشير',
            'emp_number' => 'E2',
        ]);
        $this->createdEmployeeIds[] = $employeeA->id;
        $this->createdEmployeeIds[] = $employeeB->id;

        $op = Operation::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'waste',
            'operation_type' => 'waste_record',
            'status' => 'pending',
            'public_id' => 'WST-'.rand(1000, 9999),
        ]);
        $this->createdOperationIds[] = $op->id;

        $actor = AsabUser::create(['company_id' => $company->id, 'name' => 'Actor', 'email' => 'act@test.com', 'password' => 'x', 'status' => 'active']);

        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed with transactionLevel=0 before spawning workers');
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employeeA->id);
        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employeeB->id);
        $this->assertFixtureVisibleFromIndependentConnection('asab_operations', 'id', $op->id);

        $this->spawnWorker('w1', 'post_allocation', [
            'BE09_OP_ID' => $op->id,
            'BE09_EMP_ID' => $employeeA->id,
            'BE09_AMOUNT' => '3000',
            'BE09_ACTOR_ID' => $actor->id,
        ]);
        $this->spawnWorker('w2', 'post_allocation', [
            'BE09_OP_ID' => $op->id,
            'BE09_EMP_ID' => $employeeB->id,
            'BE09_AMOUNT' => '4000',
            'BE09_ACTOR_ID' => $actor->id,
        ]);

        $this->releaseStartSignal();

        $res1 = $this->awaitWorkerResult('w1');
        $res2 = $this->awaitWorkerResult('w2');

        $this->assertNotSame($res1['conn'], $res2['conn']);
        $this->assertSame('OK', $res1['status']);
        $this->assertSame('OK', $res2['status']);

        // Exactly one net active charge set, zero unreversed double charges
        $ledger = app(\Modules\Admin\Services\EmployeeLedgerService::class);
        $balances = [$ledger->currentBalance($employeeA->id), $ledger->currentBalance($employeeB->id)];
        $this->assertContains(0, $balances, 'Prior allocation must be reversed to 0');
        $this->assertTrue(in_array(-3000, $balances, true) || in_array(-4000, $balances, true), 'Winning allocation active');
    }
}
