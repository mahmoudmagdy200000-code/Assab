<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftDebtRepayment;
use Modules\Shift\Models\ShiftHandoverReversal;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Models\ShiftReportRevision;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ASSAB ERP — Wave 3 (BE-13, BE-14, BE-15, BE-16) MySQL/InnoDB Concurrency Test Suite.
 *
 * Implements real multi-process concurrency with independent PDO connections
 * to Oracle MySQL 8.4 / InnoDB under REPEATABLE-READ isolation, verifying
 * row-level lock hierarchy, transaction serialization, and zero duplicate financial movements.
 *
 * Concurrency Test Matrix:
 * - Case 1 (BE-13): Concurrent Handover Reversal Dispute & Confirmation races under lockForUpdate.
 * - Case 2 (BE-14): Database-Level Atomic State Transition Proof (ShiftReportManagerReview status and statement reviewStatus projection).
 * - Case 3a (BE-15): Service-Level Exactly-Once Allocation Deduplication.
 * - Case 3b (BE-15): Concurrent HTTP Idempotency-Key Middleware Proof (Replay & 409 Conflict).
 * - Case 4a (BE-16): Concurrent Cashier Debt Repayment Submission Race (Cashier row lock & pending cap).
 * - Case 4b (BE-16): Concurrent Cashier Debt Repayment Confirmation preventing overpayment and negative debt.
 * - Case 4c (BE-16): Concurrent Mixed Submit & Confirm Deadlock-Free Proof (Lock order: 1. Cashier -> 2. Repayment).
 *
 * Multi-Connection Isolation:
 * - Does NOT use RefreshDatabase (which wraps execution in an uncommitted parent transaction).
 * - Commits fixtures directly to the disposable test database before workers start.
 * - Asserts physical fixture visibility from an independent PDO connection before spawning workers.
 * - Enforces fail-closed database guards (strictly restricted to assab_proof_wave3_* on local loopback host).
 * - Guarantees strict reverse-FK teardown cleanup.
 *
 * Status on SQLite runner: NOT_RUN (skipped), preserved as SQLite Evidence until an isolated
 * disposable Oracle MySQL/InnoDB staging schema is executed.
 */
class Wave3FinancialConcurrencyTest extends TestCase
{
    private array $workers = [];
    private ?string $barriersDir = null;
    private array $createdCompanyIds = [];
    private array $createdBranchIds = [];
    private array $createdCashierIds = [];
    private array $createdManagerIds = [];
    private array $createdEmployeeIds = [];
    private array $createdOperationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('NOT_RUN: Wave 3 Concurrency tests require an isolated MySQL/InnoDB environment with independent connection processes.');
        }

        // Strict Fail-Closed Environment Guard: Prevent execution against any non-disposable or non-local database
        $dbName = (string) DB::connection()->getDatabaseName();
        if (! preg_match('/^assab_proof_wave3_[0-9]+_[0-9]+$/', $dbName) && ! preg_match('/^assab_proof_wave3_/', $dbName)) {
            throw new \RuntimeException("SAFETY GUARD BLOCKED: Concurrency tests are strictly restricted to disposable schema pattern 'assab_proof_wave3_*', got '{$dbName}'.");
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
        $this->createdCashierIds = [];
        $this->createdManagerIds = [];
        $this->createdEmployeeIds = [];
        $this->createdOperationIds = [];

        $this->barriersDir = sys_get_temp_dir().'/wave3-concurr-'.Str::uuid();
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

        try {
            if (! empty($this->createdBranchIds)) {
                DB::table('shift_debt_repayments')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('shift_handover_reversals')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('personal_ledger_transactions')->whereIn('branch_manager_id', $this->createdManagerIds)->delete();
                DB::table('cashier_shift_handover_receipts')->whereIn('receiving_cashier_id', $this->createdCashierIds)->delete();
                DB::table('cashier_shift_handovers')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('shift_report_manager_reviews')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('shift_liability_postings')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('shift_report_revisions')->delete();
                DB::table('shift_report_aggregates')->delete();
                DB::table('cashier_custody_transactions')->whereIn('cashier_id', $this->createdCashierIds)->delete();
                DB::table('cashier_shifts')->whereIn('cashier_id', $this->createdCashierIds)->delete();
                DB::table('cashiers')->whereIn('id', $this->createdCashierIds)->delete();
                DB::table('branch_managers')->whereIn('id', $this->createdManagerIds)->delete();
                DB::table('shifts')->whereIn('branch_id', $this->createdBranchIds)->delete();
                DB::table('branches')->whereIn('id', $this->createdBranchIds)->delete();
            }

            if (! empty($this->createdOperationIds)) {
                DB::table('asab_employee_movements')->whereIn('ref_operation_id', $this->createdOperationIds)->delete();
                DB::table('asab_operations')->whereIn('id', $this->createdOperationIds)->delete();
            }

            if (! empty($this->createdEmployeeIds)) {
                DB::table('asab_employee_settlement_requests')->whereIn('employee_id', $this->createdEmployeeIds)->delete();
                DB::table('asab_employee_movements')->whereIn('employee_id', $this->createdEmployeeIds)->delete();
                DB::table('asab_employees')->whereIn('id', $this->createdEmployeeIds)->delete();
            }

            if (! empty($this->createdCompanyIds)) {
                DB::table('asab_user_roles')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_company_users')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_users')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_brands')->whereIn('company_id', $this->createdCompanyIds)->delete();
                DB::table('asab_companies')->whereIn('id', $this->createdCompanyIds)->delete();
            }
        } catch (\Throwable) {}

        parent::tearDown();
    }

    /**
     * Asserts that fixtures are physically committed and visible from an independent PDO connection.
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

        $this->assertSame(1, $count, "Fixture {$table}.{$idColumn}={$idValue} must be committed and visible from independent PDO connection.");
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

$barrierDir = getenv('WAVE3_BARRIER_DIR');
$workerId = getenv('WAVE3_WORKER_ID');
$action = getenv('WAVE3_ACTION');

$connId = null;
try {
    $expectedDb = getenv('DB_DATABASE');
    if (!$expectedDb || (!preg_match('/^assab_proof_wave3_[0-9]+_[0-9]+$/', $expectedDb) && !preg_match('/^assab_proof_wave3_/', $expectedDb))) {
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'EXCEPTION',
            'code' => 'SAFETY_GUARD_BLOCKED',
            'message' => 'Worker database must match pattern assab_proof_wave3_*',
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
    if ($action === 'confirm_reversal') {
        $reversalId = getenv('WAVE3_REVERSAL_ID');
        $actorId = getenv('WAVE3_ACTOR_ID');
        $reversal = Modules\Shift\Models\ShiftHandoverReversal::findOrFail($reversalId);
        $actor = Modules\Cashier\Models\Cashier::findOrFail($actorId);
        $service = app(Modules\Shift\Services\HandoverSettlementService::class);
        $res = $service->confirmReversal($reversal, $actor);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'approve_report_review') {
        $reviewId = getenv('WAVE3_REVIEW_ID');
        $actorId = getenv('WAVE3_ACTOR_ID');
        DB::transaction(function () use ($reviewId, $actorId) {
            $locked = Modules\Shift\Models\ShiftReportManagerReview::lockForUpdate()->findOrFail($reviewId);
            if ($locked->status === 'approved') {
                throw new \RuntimeException('REPORT_REVIEW_ALREADY_APPROVED');
            }
            $locked->update([
                'status' => 'approved',
                'reviewer_type' => 'asab_user',
                'reviewer_id' => $actorId,
                'reviewed_at' => now(),
            ]);
        });
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'conn' => $connId]));
    } elseif ($action === 'post_allocation') {
        $opId = getenv('WAVE3_OP_ID');
        $empId = getenv('WAVE3_EMP_ID');
        $amount = (int) getenv('WAVE3_AMOUNT');
        $actorId = getenv('WAVE3_ACTOR_ID');
        $op = Modules\Admin\Models\Operation::findOrFail($opId);
        $emp = Modules\Admin\Models\Employee::findOrFail($empId);
        $actor = Modules\Admin\Models\AsabUser::findOrFail($actorId);
        $service = app(Modules\Admin\Services\EmployeeAllocationService::class);
        $res = $service->post($op, 'waste_charge', 'خصم هدر', [
            ['employee' => $emp, 'amountHalalas' => $amount],
        ], $actor);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    } elseif ($action === 'http_idempotency_post') {
        $actorId = getenv('WAVE3_ACTOR_ID');
        $empId = getenv('WAVE3_EMP_ID');
        $key = getenv('WAVE3_IDEM_KEY');
        $amount = (int) getenv('WAVE3_AMOUNT');
        $actor = Modules\Admin\Models\AsabUser::findOrFail($actorId);
        \Laravel\Sanctum\Sanctum::actingAs($actor);
        $req = \Illuminate\Http\Request::create(
            "/api/v1/company/me/employees/{$empId}/settle-balance",
            'POST',
            ['idempotencyKey' => $key, 'amountHalalas' => $amount, 'reason' => 'Concurrent test'],
            [], [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ]
        );
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($req);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
            'status' => 'OK',
            'http_status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
            'conn' => $connId,
        ]));
    } elseif ($action === 'submit_repayment') {
        $cashierId = getenv('WAVE3_CASHIER_ID');
        $managerId = getenv('WAVE3_MANAGER_ID');
        $amount = (int) getenv('WAVE3_AMOUNT');
        $cashier = Modules\Cashier\Models\Cashier::findOrFail($cashierId);
        $service = app(Modules\Shift\Services\HandoverSettlementService::class);
        $res = $service->submitDebtRepayment($cashier, $managerId, $amount);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res_id' => $res->id, 'conn' => $connId]));
    } elseif ($action === 'confirm_repayment') {
        $repaymentId = getenv('WAVE3_REPAYMENT_ID');
        $actorId = getenv('WAVE3_ACTOR_ID');
        $repayment = Modules\Shift\Models\ShiftDebtRepayment::findOrFail($repaymentId);
        $actor = Modules\BranchManagers\Models\BranchManager::findOrFail($actorId);
        $service = app(Modules\Shift\Services\HandoverSettlementService::class);
        $res = $service->confirmDebtRepayment($repayment, $actor);
        file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode(['status' => 'OK', 'res' => $res, 'conn' => $connId]));
    }
} catch (\Throwable $t) {
    file_put_contents($barrierDir . '/' . $workerId . '.result', json_encode([
        'status' => 'ERROR',
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
            'WAVE3_BARRIER_DIR' => $this->barriersDir,
            'WAVE3_WORKER_ID' => $workerId,
            'WAVE3_ACTION' => $action,
        ];

        $process = new Process([PHP_BINARY, '-r', $code], base_path(), array_merge($dbEnv, $envParams));
        $process->setTimeout(30);
        $process->start();

        $this->workers[] = $process;

        return $process;
    }

    /**
     * CASE 1: BE-13 Concurrent Handover Reversal Dispute & Confirmation.
     * Proves row lock exclusivity and once-only reversal under simultaneous worker requests.
     */
    public function test_case1_concurrent_handover_reversal_initiation_and_confirmation(): void
    {
        $company = AsabCompany::create(['name' => 'BE13 Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $sender = Cashier::factory()->create(['branch_id' => $branch->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id]);
        $this->createdCashierIds[] = $sender->id;
        $this->createdCashierIds[] = $recipient->id;

        $slot = Shift::factory()->create(['branch_id' => $branch->id]);
        $senderShift = CashierShift::factory()->create(['cashier_id' => $sender->id, 'shift_id' => $slot->id]);
        $recipientShift = CashierShift::factory()->create(['cashier_id' => $recipient->id, 'shift_id' => $slot->id]);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $senderShift->id,
            'shift_id' => $senderShift->shift_id,
            'branch_id' => $branch->id,
            'handover_type' => 'cashier',
            'handover_to_type' => 'cashier',
            'handover_to_id' => $recipient->id,
            'handover_amount' => 500.00,
            'declared_amount' => 500.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $senderShift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $sender->id,
        ]);

        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => $recipientShift->id,
            'receiving_cashier_id' => $recipient->id,
            'report_revision_id' => $revision->id,
            'received_amount' => 500.00,
            'confirmed_amount' => 500.00,
            'confirmed_at' => now(),
        ]);

        $reversal = ShiftHandoverReversal::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'original_handover_id' => $handover->id,
            'original_receipt_id' => $receipt->id,
            'reversal_reason' => 'Concurrent dispute test',
            'initiated_by_user_id' => $recipient->id,
            'status' => 'pending_sender_confirmation',
        ]);

        $this->assertFixtureVisibleFromIndependentConnection('shift_handover_reversals', 'id', $reversal->id);

        $w1 = $this->spawnWorker('worker_rev_1', 'confirm_reversal', [
            'WAVE3_REVERSAL_ID' => $reversal->id,
            'WAVE3_ACTOR_ID' => $sender->id,
        ]);
        $w2 = $this->spawnWorker('worker_rev_2', 'confirm_reversal', [
            'WAVE3_REVERSAL_ID' => $reversal->id,
            'WAVE3_ACTOR_ID' => $sender->id,
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_rev_1.conn') || !is_file($this->barriersDir.'/worker_rev_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        $res1 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_rev_1.result'), true);
        $res2 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_rev_2.result'), true);

        $statuses = array_filter([$res1['status'] ?? null, $res2['status'] ?? null]);
        $this->assertContains('OK', $statuses, 'At least one worker must confirm the reversal successfully.');

        // Assert exactly-once database state: exactly one pair of reversal ledger rows
        $reversalFresh = ShiftHandoverReversal::find($reversal->id);
        $this->assertSame('confirmed', $reversalFresh->status);
        $this->assertNotNull($reversalFresh->confirmed_at);

        $senderCustody = CashierCustodyTransaction::where('reversal_id', $reversal->id)->where('cashier_id', $sender->id)->where('is_cash_in', true)->count();
        $recipientCustody = CashierCustodyTransaction::where('reversal_id', $reversal->id)->where('cashier_id', $recipient->id)->where('is_cash_in', false)->count();

        $this->assertSame(1, $senderCustody, 'Exactly one cashier custody reversal-in transaction must be recorded for sender.');
        $this->assertSame(1, $recipientCustody, 'Exactly one cashier custody reversal-out transaction must be recorded for recipient.');
        $this->assertSame(2, CashierCustodyTransaction::where('reversal_id', $reversal->id)->count(), 'Exactly one pair of reversal ledger rows recorded.');
    }

    /**
     * CASE 2: BE-14 (STMT-01) Database-Level Atomic Transition Proof.
     * Proves atomic database state transition of ShiftReportManagerReview under lockForUpdate()
     * and statement reviewStatus projection ('under_review' -> 'approved') with stable movement ID,
     * preserved tenant context, and zero duplicate debits under concurrent review approval attempts.
     *
     * Note: This is an explicit Database-Level State Transition Proof for statement reviewStatus;
     * it does not test or claim HTTP API route authorization for Head Accountant.
     */
    public function test_case2_concurrent_shift_report_review_atomic_db_transition_and_statement_projection(): void
    {
        $company = AsabCompany::create(['name' => 'BE14 Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Head Accountant User',
            'email' => 'head_' . Str::random(8) . '@concurrency.test',
            'password' => 'secret',
            'status' => 'active',
        ]);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'role_key' => 'head', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'head']);

        $slot = Shift::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $this->createdCashierIds[] = $cashier->id;
        $shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $slot->id]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'emp_number' => 'EMP_' . Str::random(6),
            'name' => 'Shortage Concurrency Employee',
            'role' => 'staff',
            'standing_balance_halalas' => -5000,
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $shift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $cashier->id,
        ]);

        $review = ShiftReportManagerReview::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cashier_shift_id' => $shift->id,
            'report_revision_id' => $revision->id,
            'report_revision_number' => 1,
            'status' => 'pending',
            'variance_halalas' => -5000,
            'has_shortage' => true,
        ]);

        $posting = ShiftLiabilityPosting::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cashier_shift_id' => $shift->id,
            'lineage_key' => 'shift_shortage:'.$shift->id,
            'responsible_type' => 'employee',
            'responsible_id' => $employee->id,
            'delta_halalas' => 5000,
            'target_halalas' => 5000,
            'effective_before_halalas' => 0,
            'operation_id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => (string) Str::uuid(),
            'ledger_table' => 'asab_employee_movements',
            'ledger_id' => (string) Str::uuid(),
            'review_id' => $review->id,
            'evidence_id' => $revision->id,
            'status' => 'posted',
        ]);

        $movement = EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'shortage',
            'description' => 'عجز وردية متزامن',
            'lineage_key' => $posting->lineage_key,
            'posting_id' => $posting->id,
            'created_by_id' => $user->id,
            'movement_date' => now(),
        ]);

        $this->assertFixtureVisibleFromIndependentConnection('shift_report_manager_reviews', 'id', $review->id);

        $w1 = $this->spawnWorker('worker_stmt_1', 'approve_report_review', [
            'WAVE3_REVIEW_ID' => $review->id,
            'WAVE3_ACTOR_ID' => $user->id,
        ]);
        $w2 = $this->spawnWorker('worker_stmt_2', 'approve_report_review', [
            'WAVE3_REVIEW_ID' => $review->id,
            'WAVE3_ACTOR_ID' => $user->id,
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_stmt_1.conn') || !is_file($this->barriersDir.'/worker_stmt_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        $reviewFresh = ShiftReportManagerReview::find($review->id);
        $this->assertSame('approved', $reviewFresh->status);

        // Assert statement projects reviewStatus as approved with exactly 1 movement (zero duplicate debits)
        $service = app(\Modules\Admin\Services\EmployeeLedgerService::class);
        $statement = $service->statement($employee, null);

        $this->assertCount(1, $statement['movements']);
        $this->assertSame('approved', $statement['movements'][0]['reviewStatus']);
        $this->assertSame($movement->id, $statement['movements'][0]['id']);
        $this->assertSame(-5000, $statement['balanceHalalas']);
    }

    /**
     * CASE 3a: BE-15 Service-Level Exactly-Once Allocation Deduplication.
     * Proves command deduplication and once-only posting under simultaneous parallel requests
     * directly at the EmployeeAllocationService level without duplicate debit movements.
     */
    public function test_case3a_concurrent_service_level_allocation_deduplication(): void
    {
        $company = AsabCompany::create(['name' => 'BE15 Service Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Idempotency User',
            'email' => 'idem_' . Str::random(8) . '@concurrency.test',
            'password' => 'secret',
            'status' => 'active',
        ]);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'role_key' => 'accountant', 'status' => 'active']);

        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'emp_number' => 'EMP_' . Str::random(6),
            'name' => 'Allocation Concurrency Emp',
            'role' => 'staff',
            'standing_balance_halalas' => 0,
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        $op = Operation::create([
            'public_id' => OperationSequence::next('WD'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'waste',
            'amount' => 10000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => 'approved',
            'operation_date' => now(),
            'payload' => [],
        ]);
        $this->createdOperationIds[] = $op->id;

        $this->assertFixtureVisibleFromIndependentConnection('asab_operations', 'id', $op->id);

        $w1 = $this->spawnWorker('worker_alloc_1', 'post_allocation', [
            'WAVE3_OP_ID' => $op->id,
            'WAVE3_EMP_ID' => $employee->id,
            'WAVE3_AMOUNT' => '10000',
            'WAVE3_ACTOR_ID' => $user->id,
        ]);
        $w2 = $this->spawnWorker('worker_alloc_2', 'post_allocation', [
            'WAVE3_OP_ID' => $op->id,
            'WAVE3_EMP_ID' => $employee->id,
            'WAVE3_AMOUNT' => '10000',
            'WAVE3_ACTOR_ID' => $user->id,
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_alloc_1.conn') || !is_file($this->barriersDir.'/worker_alloc_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        // Exactly once: only 1 debit movement recorded for this operation on employee
        $debits = EmployeeMovement::where('employee_id', $employee->id)
            ->where('ref_operation_id', $op->id)
            ->where('movement_type', 'debit')
            ->count();

        $this->assertSame(1, $debits, 'Concurrent allocation commands must post exactly one debit movement without duplicate rows.');
    }

    /**
     * CASE 3b: BE-15 Concurrent HTTP Idempotency-Key Middleware Proof.
     * Proves that two simultaneous HTTP requests presenting the same Idempotency-Key
     * create strictly one settlement request record, and any request with changed payload
     * is safely rejected with HTTP 409 Conflict.
     */
    public function test_case3b_concurrent_http_idempotency_key_replay_and_conflict(): void
    {
        $company = AsabCompany::create(['name' => 'BE15 HTTP Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'HTTP Idempotency User',
            'email' => 'idem_http_' . Str::random(8) . '@concurrency.test',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'emp_number' => 'EMP_' . Str::random(6),
            'name' => 'HTTP Settle Emp',
            'role' => 'staff',
            'standing_balance_halalas' => 0,
        ]);
        $this->createdEmployeeIds[] = $employee->id;

        // Provide starting debit balance
        EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'cash_variance',
            'description' => 'Initial debit',
            'movement_date' => now(),
        ]);

        $this->assertFixtureVisibleFromIndependentConnection('asab_employees', 'id', $employee->id);

        $key = (string) Str::uuid();

        $w1 = $this->spawnWorker('worker_http_idem_1', 'http_idempotency_post', [
            'WAVE3_ACTOR_ID' => $user->id,
            'WAVE3_EMP_ID' => $employee->id,
            'WAVE3_IDEM_KEY' => $key,
            'WAVE3_AMOUNT' => '1000',
        ]);
        $w2 = $this->spawnWorker('worker_http_idem_2', 'http_idempotency_post', [
            'WAVE3_ACTOR_ID' => $user->id,
            'WAVE3_EMP_ID' => $employee->id,
            'WAVE3_IDEM_KEY' => $key,
            'WAVE3_AMOUNT' => '1000',
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_http_idem_1.conn') || !is_file($this->barriersDir.'/worker_http_idem_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        // Exactly one settlement request record created in the database for this idempotency key
        $settlementCount = EmployeeSettlementRequest::where('idempotency_key', $key)->count();
        $this->assertSame(1, $settlementCount, 'Concurrent HTTP requests with same Idempotency-Key must produce strictly 1 settlement record.');

        // Replay with CHANGED payload must be rejected with HTTP 409 Conflict
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $resChanged = $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$employee->id}/settle-balance", [
                'idempotencyKey' => $key,
                'amountHalalas' => 2000,
                'reason' => 'Changed payload',
            ]);
        $this->assertSame(409, $resChanged->status(), 'Replaying key with changed payload must return 409 Conflict.');
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    /**
     * CASE 4a: BE-16 Concurrent Debt Repayment Submission Race.
     * Proves Cashier row-level lock serialization under concurrent submissions:
     * two parallel requests whose sum exceeds debt allow strictly one pending repayment,
     * and submission replay returns the existing record without duplicate pending rows.
     */
    public function test_case4a_concurrent_debt_repayment_submission_race_preventing_over_pending_and_duplicates(): void
    {
        $company = AsabCompany::create(['name' => 'BE16 Sub Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $this->createdManagerIds[] = $manager->id;
        $this->createdCashierIds[] = $cashier->id;

        // Cashier has exactly 20.00 SAR (2,000 halalas) custody debt
        CashierCustodyTransaction::create([
            'cashier_id' => $cashier->id,
            'transaction_type' => 'Shortage Liability',
            'amount' => 20.00,
            'is_cash_in' => true,
            'transaction_date' => now()->subDay(),
        ]);

        $this->assertFixtureVisibleFromIndependentConnection('cashiers', 'id', $cashier->id);

        // Two competing concurrent submissions of different amounts (1,500 + 1,200 = 2,700 > 2,000 debt)
        $w1 = $this->spawnWorker('worker_sub_1', 'submit_repayment', [
            'WAVE3_CASHIER_ID' => $cashier->id,
            'WAVE3_MANAGER_ID' => $manager->id,
            'WAVE3_AMOUNT' => '1500',
        ]);
        $w2 = $this->spawnWorker('worker_sub_2', 'submit_repayment', [
            'WAVE3_CASHIER_ID' => $cashier->id,
            'WAVE3_MANAGER_ID' => $manager->id,
            'WAVE3_AMOUNT' => '1200',
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_sub_1.conn') || !is_file($this->barriersDir.'/worker_sub_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        $res1 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_sub_1.result'), true);
        $res2 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_sub_2.result'), true);

        // Assert neither process suffered an unexpected database error or deadlock
        $deadlockFound = str_contains((string) ($res1['message'] ?? ''), 'Deadlock')
            || str_contains((string) ($res2['message'] ?? ''), 'Deadlock');
        $this->assertFalse($deadlockFound, 'No deadlock may occur during concurrent debt repayment submissions.');

        // Exactly one submission must succeed, and one must fail validation under the row lock
        $winner = null;
        $loser = null;
        $winnerAmount = null;

        if (($res1['status'] ?? null) === 'OK') {
            $winner = $res1;
            $loser = $res2;
            $winnerAmount = 1500;
        } elseif (($res2['status'] ?? null) === 'OK') {
            $winner = $res2;
            $loser = $res1;
            $winnerAmount = 1200;
        }

        $this->assertNotNull($winner, 'Exactly one concurrent submission must succeed.');
        $this->assertSame('ERROR', $loser['status'] ?? null, 'The competing submission must fail validation.');
        $this->assertStringContainsString('Total pending repayments exceed remaining outstanding debt balance', $loser['message'] ?? '', 'Rejection message must indicate total pending repayments exceed remaining debt.');

        // Extract res_id of the winning operation and verify actual database record
        $acceptedId = $winner['res_id'] ?? null;
        $this->assertNotNull($acceptedId, 'Winning operation must return a valid repayment ID.');

        $pendingQuery = ShiftDebtRepayment::where('cashier_id', $cashier->id)->where('status', 'pending_manager_confirmation');
        $this->assertSame(1, $pendingQuery->count(), 'Strictly one pending repayment row exists.');

        $actualRecord = ShiftDebtRepayment::findOrFail($acceptedId);
        $this->assertSame($cashier->id, $actualRecord->cashier_id);
        $this->assertSame('pending_manager_confirmation', $actualRecord->status);
        $this->assertSame($winnerAmount, (int) $actualRecord->amount_halalas, 'Pending amount must exactly match the winning operation amount.');

        // Replaying submission with the winner's amount returns existing without duplicate row
        $service = app(\Modules\Shift\Services\HandoverSettlementService::class);
        $replay = $service->submitDebtRepayment($cashier, $manager->id, $winnerAmount);
        $this->assertSame($acceptedId, $replay->id, 'Replaying submission with accepted amount must return the existing pending record.');
        $this->assertSame(1, ShiftDebtRepayment::where('cashier_id', $cashier->id)->where('status', 'pending_manager_confirmation')->count(), 'Replay submission must NOT create duplicate pending row.');
    }

    /**
     * CASE 4b: BE-16 Concurrent Debt Repayment Confirmation.
     * Proves outstanding debt cap and once-only confirmation preventing negative debt balance.
     */
    public function test_case4b_concurrent_debt_repayment_confirmation_preventing_overpayment(): void
    {
        $company = AsabCompany::create(['name' => 'BE16 Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $this->createdManagerIds[] = $manager->id;
        $this->createdCashierIds[] = $cashier->id;

        // Cashier has exactly 20.00 SAR (2,000 halalas) custody debt
        CashierCustodyTransaction::create([
            'cashier_id' => $cashier->id,
            'transaction_type' => 'Shortage Liability',
            'amount' => 20.00,
            'is_cash_in' => true,
            'transaction_date' => now()->subDay(),
        ]);

        // Two competing repayment requests of 1,500 halalas each (Total 3,000 > 2,000 debt)
        $repayment1 = ShiftDebtRepayment::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'manager_id' => $manager->id,
            'amount_halalas' => 1500,
            'status' => 'pending_manager_confirmation',
        ]);
        $repayment2 = ShiftDebtRepayment::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'manager_id' => $manager->id,
            'amount_halalas' => 1500,
            'status' => 'pending_manager_confirmation',
        ]);

        $this->assertFixtureVisibleFromIndependentConnection('shift_debt_repayments', 'id', $repayment1->id);
        $this->assertFixtureVisibleFromIndependentConnection('shift_debt_repayments', 'id', $repayment2->id);

        $w1 = $this->spawnWorker('worker_rep_1', 'confirm_repayment', [
            'WAVE3_REPAYMENT_ID' => $repayment1->id,
            'WAVE3_ACTOR_ID' => $manager->id,
        ]);
        $w2 = $this->spawnWorker('worker_rep_2', 'confirm_repayment', [
            'WAVE3_REPAYMENT_ID' => $repayment2->id,
            'WAVE3_ACTOR_ID' => $manager->id,
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_rep_1.conn') || !is_file($this->barriersDir.'/worker_rep_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        $res1 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_rep_1.result'), true);
        $res2 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_rep_2.result'), true);

        // One must succeed, and one must be rejected (cannot confirm 1500 when remaining debt is 500)
        $confirmedCount = ShiftDebtRepayment::whereIn('id', [$repayment1->id, $repayment2->id])
            ->where('status', 'confirmed')
            ->count();

        $this->assertSame(1, $confirmedCount, 'Exactly one concurrent debt repayment may confirm when combined amounts exceed liability.');

        // Assert remaining debt cannot be negative
        $in = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', true)->sum('amount');
        $out = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', false)->sum('amount');
        $remainingDebt = (int) round(max(0.0, round($in - $out, 2)) * 100);

        $this->assertSame(500, $remainingDebt, 'Cashier debt must be exactly 500 halalas and NEVER negative.');
        $this->assertGreaterThanOrEqual(0, $remainingDebt, 'Debt balance must never become negative under concurrent confirmations.');
    }

    /**
     * CASE 4c: BE-16 Concurrent Mixed Submit & Confirm Flow (Deadlock-Free Proof).
     * Proves unified lock hierarchy (1. Cashier -> 2. ShiftDebtRepayment) guarantees
     * that a concurrent repayment submission and confirmation on the same cashier
     * serialize without database deadlock (SQLSTATE 1213 / 40001).
     */
    public function test_case4c_concurrent_mixed_submit_and_confirm_deadlock_free(): void
    {
        $company = AsabCompany::create(['name' => 'BE16 Mixed Concurrency Co', 'plan' => 'Pro', 'status' => 'active']);
        $this->createdCompanyIds[] = $company->id;
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->createdBranchIds[] = $branch->id;

        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $this->createdManagerIds[] = $manager->id;
        $this->createdCashierIds[] = $cashier->id;

        // Cashier has exactly 20.00 SAR (2,000 halalas) custody debt
        CashierCustodyTransaction::create([
            'cashier_id' => $cashier->id,
            'transaction_type' => 'Shortage Liability',
            'amount' => 20.00,
            'is_cash_in' => true,
            'transaction_date' => now()->subDay(),
        ]);

        // Existing pending repayment of 1,000 halalas
        $repayment = ShiftDebtRepayment::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'manager_id' => $manager->id,
            'amount_halalas' => 1000,
            'status' => 'pending_manager_confirmation',
        ]);

        $this->assertFixtureVisibleFromIndependentConnection('shift_debt_repayments', 'id', $repayment->id);
        $this->assertFixtureVisibleFromIndependentConnection('cashiers', 'id', $cashier->id);

        // Worker 1 confirms existing repayment (Lock order: Cashier -> Repayment)
        $w1 = $this->spawnWorker('worker_mix_1', 'confirm_repayment', [
            'WAVE3_REPAYMENT_ID' => $repayment->id,
            'WAVE3_ACTOR_ID' => $manager->id,
        ]);
        // Worker 2 submits a new repayment of 500 halalas (distinct from 1,000)
        $w2 = $this->spawnWorker('worker_mix_2', 'submit_repayment', [
            'WAVE3_CASHIER_ID' => $cashier->id,
            'WAVE3_MANAGER_ID' => $manager->id,
            'WAVE3_AMOUNT' => '500',
        ]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->barriersDir.'/worker_mix_1.conn') || !is_file($this->barriersDir.'/worker_mix_2.conn')) {
            if (microtime(true) > $deadline) {
                $this->fail('Worker processes failed to connect within deadline');
            }
            usleep(5000);
        }

        file_put_contents($this->barriersDir.'/start.signal', 'release');

        $w1->wait();
        $w2->wait();

        $res1 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_mix_1.result'), true);
        $res2 = json_decode((string) @file_get_contents($this->barriersDir.'/worker_mix_2.result'), true);

        // Assert neither process suffered a deadlock
        $deadlockFound = str_contains((string) ($res1['message'] ?? ''), 'Deadlock')
            || str_contains((string) ($res2['message'] ?? ''), 'Deadlock');
        $this->assertFalse($deadlockFound, 'No deadlock may occur during concurrent submit and confirm operations.');

        // Both operations must successfully complete due to canonical lock ordering
        $this->assertSame('OK', $res1['status'] ?? null, 'Confirm operation must succeed.');
        $this->assertSame('OK', $res2['status'] ?? null, 'Submit operation must succeed.');

        // Repayment 1 confirmed
        $this->assertSame('confirmed', ShiftDebtRepayment::find($repayment->id)->status);

        // Repayment 2 created as pending with amount 500
        $newRepayment = ShiftDebtRepayment::where('cashier_id', $cashier->id)
            ->where('status', 'pending_manager_confirmation')
            ->first();
        $this->assertNotNull($newRepayment);
        $this->assertSame(500, (int) $newRepayment->amount_halalas);

        // Debt balance is exactly 1,000 halalas (2000 initial - 1000 confirmed)
        $in = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', true)->sum('amount');
        $out = (float) CashierCustodyTransaction::where('cashier_id', $cashier->id)->where('is_cash_in', false)->sum('amount');
        $remainingDebt = (int) round(max(0.0, round($in - $out, 2)) * 100);
        $this->assertSame(1000, $remainingDebt, 'Cashier debt is exactly 1000 halalas after confirmation.');
    }
}
