<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Services\ExpenseCustodyService;
use Modules\Expense\Models\Expense;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Opt-in only. This suite never migrates, truncates, or connects to a non-audit schema.
 * It requires an already migrated, disposable local MySQL schema.
 */
class ExpenseCustodyMySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $barriers = [];

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $formerManager;

    private BranchManager $currentManager;

    private BrandOwner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EXPENSE_CUSTODY_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('MySQL concurrency gate requires EXPENSE_CUSTODY_MYSQL_TESTS=1.');
        }
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! preg_match('/^assab_phase5_audit_[a-z0-9_]+$/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
            throw new \LogicException('Refusing MySQL concurrency tests outside an explicitly isolated local audit schema.');
        }

        $tag = bin2hex(random_bytes(5));
        $this->company = AsabCompany::create(['name' => 'Phase 5 '.$tag, 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Phase 5 '.$tag, 'status' => 'active']);
        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);
        $this->formerManager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id, 'is_active' => false, 'status' => 'inactive',
        ]);
        $this->currentManager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active',
        ]);
        $this->owner = BrandOwner::create([
            'name' => 'Phase 5 owner '.$tag,
            'email' => 'phase5-'.$tag.'@expense-custody.test',
            'phone' => '0500000000', 'password' => 'secret-password', 'is_active' => true, 'status' => 'active',
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

    public function test_two_managers_cannot_overdraw_one_branch_custody_on_mysql(): void
    {
        CustodyTransaction::create([
            'branch_manager_id' => $this->formerManager->id, 'branch_id' => $this->branch->id,
            'type' => 'Cash Handover', 'amount' => '100.00', 'is_cash_in' => true,
            'transaction_date' => now(),
        ]);
        $firstExpense = $this->pendingCashExpense($this->formerManager, 80);
        $secondExpense = $this->pendingCashExpense($this->currentManager, 80);

        [$first, $second] = $this->race(
            ['command' => 'reserve', 'expense' => $firstExpense->id],
            ['command' => 'reserve', 'expense' => $secondExpense->id],
        );

        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame(['insufficient', 'reserved'], $statuses);
        $this->assertSame(1, CustodyTransaction::whereIn('related_expense_id', [$firstExpense->id, $secondExpense->id])->where('is_cash_in', false)->count());
        $this->assertSame('20.00', number_format((float) app(\Modules\Custody\Services\CustodyBalanceService::class)
            ->getCustodyBalance((string) $this->currentManager->id, (string) $this->branch->id), 2, '.', ''));
    }

    public function test_only_one_opposite_expense_decision_wins_on_mysql(): void
    {
        CustodyTransaction::create([
            'branch_manager_id' => $this->currentManager->id, 'branch_id' => $this->branch->id,
            'type' => 'Cash Handover', 'amount' => '100.00', 'is_cash_in' => true,
            'transaction_date' => now(),
        ]);
        $expense = $this->pendingCashExpense($this->currentManager, 30);
        app(ExpenseCustodyService::class)->reserve($expense);

        [$first, $second] = $this->race(
            ['command' => 'approve', 'expense' => $expense->id, 'owner' => $this->owner->id],
            ['command' => 'reject', 'expense' => $expense->id, 'owner' => $this->owner->id],
        );

        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame(['approved', 'conflict'], $statuses);
        $this->assertSame('approved', $expense->fresh()->status);
        $this->assertSame(1, CustodyTransaction::where('related_expense_id', $expense->id)->where('is_cash_in', false)->count());
        $this->assertSame(0, CustodyTransaction::where('related_expense_id', $expense->id)->where('is_cash_in', true)->count());
    }

    public function test_concurrent_confirm_receipt_credits_branch_custody_once_on_mysql(): void
    {
        $request = CustodyRequest::create([
            'branch_manager_id' => $this->currentManager->id,
            'branch_id' => $this->branch->id,
            'requested_amount' => 100,
            'purpose' => 'Phase 5 MySQL concurrent receipt',
            'preferred_receipt_method' => 'Bank Transfer',
            'status' => 'Approved',
            'approved_by' => $this->owner->id,
            'approved_at' => now(),
            'executed_at' => now(),
            'transfer_reference' => 'TRX-MYSQL-001',
        ]);

        [$first, $second] = $this->race(
            ['command' => 'confirm_receipt', 'custody_request' => $request->id, 'manager' => $this->currentManager->id],
            ['command' => 'confirm_receipt', 'custody_request' => $request->id, 'manager' => $this->currentManager->id],
        );

        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame(['conflict', 'received'], $statuses);
        $this->assertSame('Completed', $request->fresh()->status);
        $this->assertSame(1, CustodyTransaction::where('related_custody_request_id', $request->id)->where('is_cash_in', true)->count());
        $this->assertSame('100.00', number_format((float) app(\Modules\Custody\Services\CustodyBalanceService::class)
            ->getCustodyBalance((string) $this->currentManager->id, (string) $this->branch->id), 2, '.', ''));
    }

    private function pendingCashExpense(BranchManager $manager, int $amount): Expense
    {
        return Expense::create([
            'branch_manager_id' => $manager->id, 'expense_type' => 'quick_cash', 'status' => 'pending',
            'total_amount' => $amount, 'net_amount' => $amount, 'vat_amount' => 0,
            'payment_method' => 'cash', 'submitted_at' => now(), 'review_revision' => 1,
        ]);
    }

    private function race(array $firstInput, array $secondInput): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'expense_custody_race_');
        unlink($barrier);
        $this->barriers[] = $barrier;
        $first = $this->worker($firstInput + ['hold' => true, 'signal' => $barrier.'.a', 'release' => $barrier]);
        $this->awaitSignal($barrier.'.a.held', $first);
        $second = $this->worker($secondInput + ['signal' => $barrier.'.b']);
        $this->awaitSignal($barrier.'.b.started', $second);
        usleep(250000);
        $this->assertTrue($second->isRunning(), 'the competing MySQL transaction must wait on the same locked row');
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
            base_path('tests/Support/ExpenseCustodyMySqlConcurrencyWorker.php'),
            json_encode($input, JSON_THROW_ON_ERROR),
        ], base_path());
        $worker->setTimeout(20);
        $worker->setEnv([
            'APP_ENV' => 'testing', 'EXPENSE_CUSTODY_MYSQL_TESTS' => '1', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => DB::connection()->getConfig('host'), 'DB_PORT' => (string) DB::connection()->getConfig('port'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => DB::connection()->getConfig('username'),
            'DB_PASSWORD' => DB::connection()->getConfig('password'), 'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ]);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function awaitSignal(string $path, Process $worker): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($path)) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('MySQL concurrency barrier not reached: '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
        }
    }
}
