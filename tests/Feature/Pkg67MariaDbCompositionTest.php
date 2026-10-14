<?php

namespace Tests\Feature;

// Worker entry: runs in an independent PHP process / PDO connection against the same disposable MariaDB schema.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--pkg67-worker') {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql' || ! preg_match('/\Apkg67_disposable_[a-z0-9_]+\z/', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new \LogicException('Worker refuses any shared/non-disposable database.');
    }
    $in = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $touch = fn (string $suffix) => file_put_contents($in['barrier'].'.'.$suffix, (string) microtime(true));
    $wait = function (string $suffix, float $seconds = 20.0) use ($in) {
        $deadline = microtime(true) + $seconds;
        while (! file_exists($in['barrier'].'.'.$suffix)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException("Barrier '{$suffix}' timed out");
            }
            usleep(5000);
        }
    };
    $classify = function (\Throwable $error): array {
        $code = $error instanceof \Modules\Admin\Exceptions\AsabException ? $error->errorCode : $error->getMessage();
        $infra = $error instanceof \Illuminate\Database\QueryException
            && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true);

        return ['status' => $infra ? 'INFRA_LOCK_ERROR' : 'conflict', 'code' => $code, 'class' => $error::class];
    };
    $result = null;
    try {
        $shift = fn (string $id) => \Modules\Shift\Models\CashierShift::withoutEagerLoads()->findOrFail($id);
        $manager = fn () => \Modules\BranchManagers\Models\BranchManager::findOrFail($in['manager']);
        $accountant = fn () => \Modules\Admin\Models\AsabUser::findOrFail($in['accountant'] ?? '');
        $touch('ready');
        if (! in_array($in['command'], ['stale_correct', 'stale_scope'], true)) {
            $wait('go');
        }
        $started = microtime(true);
        switch ($in['command']) {
            case 'mark':
                app(\Modules\Shift\Services\ShiftNotWorkedService::class)->mark($shift($in['shift']), $manager(), 'Two-connection no-work');
                $result = ['status' => 'ok'];
                break;
            case 'correct':
                $revision = app(\Modules\Shift\Services\AccountantReportCorrectionService::class)->correct(
                    $shift($in['shift']), $accountant(), ['counted_cash' => '101.00'], null, 1, null,
                    'Two-connection correction', (string) \Illuminate\Support\Str::uuid());
                $result = ['status' => 'ok', 'revision' => $revision->revision_number];
                break;
            case 'return':
                $op = \Modules\Admin\Models\Operation::findOrFail($in['operation']);
                $result = ['status' => 'ok', 'operation' => app(\Modules\Admin\Services\OperationService::class)
                    ->returnForReview($op, \Modules\Admin\Models\AsabUser::findOrFail($in['head']), 'Two-connection return')->status];
                break;
            case 'stale_correct':
                // Snapshot first (what the idempotency/transaction wrapper does), then wait for a competing commit.
                \Illuminate\Support\Facades\DB::beginTransaction();
                \Illuminate\Support\Facades\DB::table('cashier_shifts')->where('id', $in['shift'])->first();
                $touch('snapshot');
                $wait('marked');
                try {
                    app(\Modules\Shift\Services\ShiftReportMutationGuard::class)->lockCorrection($shift($in['shift']), $accountant());
                    $result = ['status' => 'PASSED_FENCE'];
                } finally {
                    \Illuminate\Support\Facades\DB::rollBack();
                }
                break;
            case 'stale_scope':
                // Day-close discovery inside a transaction whose snapshot predates a committed no-work mark.
                \Illuminate\Support\Facades\DB::beginTransaction();
                \Illuminate\Support\Facades\DB::table('cashier_shifts')->where('id', $in['shift'])->first();
                $touch('snapshot');
                $wait('marked');
                try {
                    $day = \Modules\Shift\Models\BranchManagerShift::findOrFail($in['day']);
                    $scope = app(\Modules\Shift\Services\ShiftDailyCloseScopeService::class)->resolve($day, true);
                    $result = ['status' => 'RESOLVED', 'ids' => $scope['cashier_shift_ids']];
                } finally {
                    \Illuminate\Support\Facades\DB::rollBack();
                }
                break;
            default:
                throw new \LogicException('Unsupported worker command');
        }
        $result['ms'] = (int) ((microtime(true) - $started) * 1000);
    } catch (\Throwable $error) {
        $result = $classify($error);
    }
    $touch('result');
    file_put_contents($in['barrier'].'.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftNotWorkedService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Opt-in (PKG67_MARIADB_TESTS=1) disposable-MariaDB gate for the composed PKG-2/3/6/7 source.
 * Independent PHP processes = independent InnoDB connections. SQLite must yield NOT_RUN, never PASS.
 */
class Pkg67MariaDbCompositionTest extends TestCase
{
    private static bool $migrated = false;

    private array $workers = [];

    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (env('PKG67_MARIADB_TESTS') !== '1' || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT_RUN: isolated MariaDB unavailable. SQLite cannot establish financial lock safety.');
        }
        if (! preg_match('/\Apkg67_disposable_[a-z0-9_]+\z/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
            throw new \LogicException('Refusing non-disposable MariaDB schema');
        }
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS v')->v);
        if (! self::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            self::$migrated = true;
        }
        $this->assertSame(0, DB::transactionLevel(), 'Workers must see committed fixtures.');
    }

    protected function tearDown(): void
    {
        foreach ($this->barriers as $path) {
            @file_put_contents($path.'.go', 'go');
            @file_put_contents($path.'.marked', 'x');
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        parent::tearDown();
    }

    /** A committed scope: one submitted report A (today) and one untouched assignment B of the SAME cashier (yesterday). */
    private function scope(bool $approved = false): array
    {
        $tag = Str::lower(Str::random(8));
        $company = AsabCompany::create(['name' => 'PKG67-'.$tag, 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'PKG67-'.$tag, 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::where('branch_id', $branch->id)->first() ?? BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $templateA = Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => '06:00:00', 'end_time' => '10:00:00']);
        $templateB = Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => '11:00:00', 'end_time' => '15:00:00']);
        $a = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $templateA->id, 'shift_date' => today(),
            'total_sales' => '100.00', 'card_payments' => '0.00', 'cash_collected' => '100.00']));
        $day = BranchManagerShift::where('branch_id', $branch->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today(), 'status' => 'completed']);
        DB::table('branch_manager_shifts')->where('id', $day->id)->update(['daily_report_submitted' => true, 'daily_report_submitted_at' => now()]);
        $admin = AdminShift::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'legacy_shift_id' => $a->id,
            'cashier_name' => 'PKG67', 'shift_type' => 'test', 'status' => 'pending_review', 'sales_amount' => 10000,
            'cash_count_state' => 'counted', 'cash_actual' => 10000, 'cash_expected' => 10000, 'variance' => 0]);
        $operation = Operation::create(['public_id' => 'SHF-'.strtoupper(Str::random(10)), 'company_id' => $company->id, 'branch_id' => $branch->id,
            'module_key' => 'shifts', 'origin' => 'mobile', 'status' => 'pending', 'operation_date' => now(),
            'payload' => ['shiftId' => $admin->id, 'legacyShiftId' => $a->id, 'salesHalalas' => 10000, 'cardTotalHalalas' => 0,
                'aggregatorTotalsHalalas' => 0, 'cashActualHalalas' => 10000, 'cashExpectedHalalas' => 10000, 'varianceHalalas' => 0]]);
        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Accountant', 'email' => "acc-$tag@test.invalid", 'password' => 'test', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head', 'email' => "head-$tag@test.invalid", 'password' => 'test', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $head->id, 'role_key' => 'head', 'scope' => 'all']);
        $count = DB::transaction(function () use ($a, $cashier) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($a, 'cashier', $cashier->id);

            return app(ShiftCashCountService::class)->record($a, $revision, 10000, 0, 0, 10000);
        });
        ShiftLiabilityDailyLock::create(['cashier_shift_id' => $a->id, 'branch_manager_shift_id' => $day->id,
            'report_revision' => $count->evidence_revision_id, 'locked_by_id' => $manager->id, 'locked_at' => now()]);
        if ($approved) {
            $operation = app(OperationService::class)->approve($operation, $accountant);
        }
        $b = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $templateB->id, 'shift_date' => today()->subDay()]);

        return compact('company', 'branch', 'manager', 'cashier', 'a', 'b', 'day', 'operation', 'accountant', 'head', 'templateB');
    }

    /** A branch whose workday is still open (no submitted report), so a no-work mark on today is permitted. */
    private function openDay(): array
    {
        $tag = Str::lower(Str::random(8));
        $company = AsabCompany::create(['name' => 'PKG67O-'.$tag, 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'PKG67O-'.$tag, 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::where('branch_id', $branch->id)->first() ?? BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $day = BranchManagerShift::where('branch_id', $branch->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today(), 'status' => 'completed']);

        return compact('company', 'branch', 'manager', 'cashier', 'day');
    }

    private function untouched(array $scope, ?\Carbon\CarbonInterface $date = null): CashierShift
    {
        $template = Shift::factory()->create(['branch_id' => $scope['branch']->id,
            'start_time' => sprintf('%02d:00:00', random_int(0, 4)), 'end_time' => sprintf('%02d:30:00', random_int(5, 9))]);

        return CashierShift::factory()->create(['cashier_id' => $scope['cashier']->id, 'shift_id' => $template->id,
            'shift_date' => $date ?? today()->subDays(random_int(2, 400))]);
    }

    private function launch(array $input): array
    {
        $config = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'],
            'DB_PORT' => (string) ($config['port'] ?? 3306), 'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file() ?: '/dev/null', __FILE__, '--pkg67-worker', json_encode($input)], base_path(), $environment);
        $process->setTimeout(60);
        $process->start();
        $this->workers[] = $process;

        return [$process, $input['barrier']];
    }

    private function waitFor(string $path, ?Process $process = null, float $seconds = 20.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (! file_exists($path) && microtime(true) < $deadline && (! $process || $process->isRunning())) {
            usleep(5000);
        }
        $this->assertFileExists($path, $process?->getErrorOutput().$process?->getOutput());
    }

    /** Release two commands together and return both decoded outcomes. */
    private function race(array $first, array $second): array
    {
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$p1, $b1] = $this->launch($first + ['barrier' => $barrier.'.1']);
        [$p2, $b2] = $this->launch($second + ['barrier' => $barrier.'.2']);
        $this->waitFor($b1.'.ready', $p1);
        $this->waitFor($b2.'.ready', $p2);
        file_put_contents($b1.'.go', 'go');
        file_put_contents($b2.'.go', 'go');
        $p1->wait();
        $p2->wait();
        $this->assertSame(0, $p1->getExitCode(), $p1->getErrorOutput());
        $this->assertSame(0, $p2->getExitCode(), $p2->getErrorOutput());

        return [json_decode(file_get_contents($b1.'.json'), true), json_decode(file_get_contents($b2.'.json'), true)];
    }

    private function assertNoInfraLockErrors(array ...$results): void
    {
        foreach ($results as $result) {
            $this->assertNotSame('INFRA_LOCK_ERROR', $result['status'], json_encode($result));
        }
    }

    // ---- shared-root contention: no deadlock, one authoritative outcome, no financial side effect ----------

    public function test_mark_vs_accountant_correction_on_the_same_cashier_never_deadlocks(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $s = $this->scope();
            $postingsBefore = DB::table('shift_liability_postings')->count();
            [$mark, $correct] = $this->race(
                ['command' => 'mark', 'manager' => $s['manager']->id, 'shift' => $s['b']->id],
                ['command' => 'correct', 'accountant' => $s['accountant']->id, 'manager' => $s['manager']->id, 'shift' => $s['a']->id]);
            $this->assertNoInfraLockErrors($mark, $correct);
            $this->assertSame('ok', $mark['status'], json_encode($mark));
            $this->assertSame('ok', $correct['status'], json_encode($correct));
            $this->assertSame(1, $s['b']->notWorkedHistory()->count());
            $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $s['b']->id)->count());
            $this->assertSame(2, (int) DB::table('shift_report_aggregates')->where('source_id', $s['a']->id)->value('current_revision_number'));
            $this->assertSame($postingsBefore, DB::table('shift_liability_postings')->count());
        }
    }

    public function test_mark_vs_head_return_on_the_same_cashier_never_deadlocks(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $s = $this->scope(approved: true);
            [$mark, $return] = $this->race(
                ['command' => 'mark', 'manager' => $s['manager']->id, 'shift' => $s['b']->id],
                ['command' => 'return', 'head' => $s['head']->id, 'operation' => $s['operation']->id, 'manager' => $s['manager']->id]);
            $this->assertNoInfraLockErrors($mark, $return);
            $this->assertSame('ok', $mark['status'], json_encode($mark));
            $this->assertSame('ok', $return['status'], json_encode($return));
            $this->assertSame(1, $s['b']->notWorkedHistory()->count());
            $this->assertSame('pending', $s['operation']->fresh()->status);
            $this->assertNull(DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $s['a']->id)->value('released_at'));
        }
    }

    public function test_mark_vs_correction_on_the_same_untouched_shift_has_one_winner_and_no_report_evidence(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $s = $this->scope();
            [$mark, $correct] = $this->race(
                ['command' => 'mark', 'manager' => $s['manager']->id, 'shift' => $s['b']->id],
                ['command' => 'correct', 'accountant' => $s['accountant']->id, 'manager' => $s['manager']->id, 'shift' => $s['b']->id]);
            $this->assertNoInfraLockErrors($mark, $correct);
            $this->assertSame('ok', $mark['status'], 'No-work must win or commit exactly once: '.json_encode([$mark, $correct]));
            $this->assertNotSame('ok', $correct['status'], 'A correction must never succeed on a shift without a report: '.json_encode($correct));
            $this->assertSame(1, $s['b']->notWorkedHistory()->count());
            foreach (['shift_report_aggregates' => 'source_id', 'shift_report_cash_counts' => 'cashier_shift_id',
                'shift_liability_allocations' => 'cashier_shift_id'] as $table => $column) {
                $this->assertSame(0, DB::table($table)->where($column, $s['b']->id)->count(), $table);
            }
        }
    }

    public function test_two_identical_marks_commit_exactly_one_audit_fact_on_mariadb(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $s = $this->scope();
            $target = $this->untouched($s);
            [$one, $two] = $this->race(
                ['command' => 'mark', 'manager' => $s['manager']->id, 'shift' => $target->id],
                ['command' => 'mark', 'manager' => $s['manager']->id, 'shift' => $target->id]);
            $this->assertNoInfraLockErrors($one, $two);
            $this->assertSame(1, count(array_filter([$one, $two], fn ($r) => $r['status'] === 'ok')), json_encode([$one, $two]));
            $this->assertSame(1, $target->notWorkedHistory()->count());
        }
    }

    // ---- REPEATABLE READ stale-snapshot probe for the I6-01 fence -----------------------------------------

    public function test_fence_holds_when_transaction_snapshot_predates_a_committed_mark(): void
    {
        $s = $this->scope();
        $target = $this->untouched($s);
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$stale, $staleBarrier] = $this->launch(['command' => 'stale_correct', 'accountant' => $s['accountant']->id,
            'manager' => $s['manager']->id, 'shift' => $target->id, 'barrier' => $barrier.'.stale']);
        $this->waitFor($staleBarrier.'.snapshot', $stale);
        app(ShiftNotWorkedService::class)->mark($target, $s['manager'], 'Closed after the other transaction took its snapshot');
        $this->assertSame(1, $target->notWorkedHistory()->count());
        file_put_contents($staleBarrier.'.marked', 'x');
        $stale->wait();
        $result = json_decode(file_get_contents($staleBarrier.'.json'), true);
        $this->assertNotSame('INFRA_LOCK_ERROR', $result['status'], json_encode($result));
        $this->assertSame(['status' => 'conflict', 'code' => 'SHIFT_MARKED_NOT_WORKED'], array_intersect_key($result, array_flip(['status', 'code'])),
            'A correction whose snapshot predates the commit must still be fenced: '.json_encode($result));
    }

    public function test_daily_close_scope_excludes_a_mark_committed_after_the_transaction_snapshot(): void
    {
        $s = $this->openDay();
        $this->assertFalse((bool) $s['day']->fresh()->daily_report_submitted);
        $target = $this->untouched($s, today());
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$stale, $staleBarrier] = $this->launch(['command' => 'stale_scope', 'day' => $s['day']->id,
            'manager' => $s['manager']->id, 'shift' => $target->id, 'barrier' => $barrier.'.scope']);
        $this->waitFor($staleBarrier.'.snapshot', $stale);
        app(ShiftNotWorkedService::class)->mark($target, $s['manager'], 'Closed after the day-close snapshot');
        file_put_contents($staleBarrier.'.marked', 'x');
        $stale->wait();
        $result = json_decode(file_get_contents($staleBarrier.'.json'), true);
        $this->assertSame('RESOLVED', $result['status'], json_encode($result));
        $this->assertNotContains((string) $target->id, $result['ids'],
            'A committed no-work fact must not leave the shift in the day-close scope: '.json_encode($result));
    }

    // ---- durable idempotency, isolation and rollback on InnoDB (single process, real stack) ---------------

    public function test_http_idempotency_isolation_and_rollback_on_mariadb(): void
    {
        $s = $this->scope();
        $target = $this->untouched($s);
        $url = "/api/v1/branch-manager/shifts/{$target->id}/mark-not-worked";
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $sibling = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->postJson($url, ['reason' => 'Closed'], $key)->assertUnauthorized();
        $this->actingAs($s['cashier'], 'sanctum')->postJson($url, ['reason' => 'Closed'], $key)->assertForbidden();
        $this->actingAs($sibling, 'sanctum')->postJson($url, ['reason' => 'Closed'], $key)->assertNotFound();
        $this->actingAs($s['manager'], 'sanctum')->postJson($url, ['reason' => 'Closed'])->assertUnprocessable();
        $this->assertSame(0, $target->notWorkedHistory()->count());

        $first = $this->actingAs($s['manager'], 'sanctum')->postJson($url, ['reason' => 'Closed'], $key);
        $first->assertOk();
        $replay = $this->postJson($url, ['reason' => 'Closed'], $key);
        $this->assertSame($first->getContent(), $replay->getContent(), 'Same key + same payload replays the stored response.');
        $this->postJson($url, ['reason' => 'Different payload'], $key)->assertConflict();
        $this->postJson($url, ['reason' => 'Closed'], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, $target->notWorkedHistory()->count());
        $this->assertSame(1, DB::table('asab_command_idempotency_keys')->where('idempotency_key', $key['Idempotency-Key'])->count());

        // Rollback: a failure after the audit row is written leaves neither status nor history behind on InnoDB.
        $other = $this->untouched($s);
        $failing = \Mockery::mock(\Modules\Shift\Services\ShiftReportCacheInvalidator::class);
        $failing->shouldReceive('cashierReport')->once()->andThrow(new \RuntimeException('injected failure'));
        $this->app->instance(\Modules\Shift\Services\ShiftReportCacheInvalidator::class, $failing);
        try {
            app(ShiftNotWorkedService::class)->mark($other, $s['manager'], 'Closed');
            $this->fail('Injected failure was swallowed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected failure', $error->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('not_started', $other->fresh()->getRawOriginal('status'));
        $this->assertSame(0, $other->notWorkedHistory()->count());
    }
}
