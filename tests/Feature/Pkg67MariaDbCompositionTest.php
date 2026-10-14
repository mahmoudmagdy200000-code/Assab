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
        if (! in_array($in['command'], ['stale_correct', 'stale_scope', 'stale_manager_correct', 'stale_submit', 'stale_recount'], true)) {
            $wait('go');
        }
        $started = microtime(true);
        $hold = (bool) ($in['hold'] ?? false);
        if ($hold) {
            // Own the outer transaction like the transactional idempotency wrapper does, and commit only on release.
            \Illuminate\Support\Facades\DB::beginTransaction();
        }
        $submit = function () use ($manager, $in): array {
            $token = $manager()->createToken('pkg67 submit')->plainTextToken;
            $request = \Illuminate\Http\Request::create('/api/v1/branch-manager/workday/daily-close/submit', 'POST', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $in['key'] ?? (string) \Illuminate\Support\Str::uuid(),
            ], json_encode(['expected_revision' => (int) $in['revision'], 'final_notes' => 'Two-connection daily close'], JSON_THROW_ON_ERROR));
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            $body = json_decode($response->getContent(), true);

            return ['status' => $response->getStatusCode() === 200 ? 'ok' : 'rejected', 'http' => $response->getStatusCode(),
                'code' => $body['code'] ?? ($body['message'] ?? null), 'body' => $body];
        };
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
            case 'manager_correct':
                $revision = app(\Modules\Shift\Services\ShiftReportCorrectionService::class)->correctCashierReport(
                    $shift($in['shift']), $manager(), ['handover_notes' => 'Verified document'], (int) ($in['revision'] ?? 1),
                    'Two-connection manager correction', (string) \Illuminate\Support\Str::uuid(), null, true);
                $result = ['status' => 'ok', 'revision' => $revision->revision_number];
                break;
            case 'recount':
                $count = app(\Modules\Shift\Services\ShiftTransferAttemptService::class)->recount(
                    $shift($in['shift']), \Modules\Cashier\Models\Cashier::findOrFail($in['cashier']), 10000);
                $result = ['status' => 'ok', 'counted' => $count->counted_halalas];
                break;
            case 'submit':
                $result = $submit();
                break;
            case 'stale_submit':
                // The real daily-close request inside a transaction whose snapshot predates a committed no-work mark.
                \Illuminate\Support\Facades\DB::beginTransaction();
                \Illuminate\Support\Facades\DB::table('cashier_shifts')->where('id', $in['shift'])->first();
                $touch('snapshot');
                $wait('marked');
                try {
                    $result = $submit();
                    $result['lock_ids'] = \Illuminate\Support\Facades\DB::table('shift_liability_daily_locks')->where('branch_manager_shift_id', $in['day'])
                        ->whereNull('released_at')->whereNull('superseded_at')->orderBy('cashier_shift_id')->pluck('cashier_shift_id')->all();
                } finally {
                    \Illuminate\Support\Facades\DB::rollBack();
                }
                break;
            case 'stale_recount':
                \Illuminate\Support\Facades\DB::beginTransaction();
                \Illuminate\Support\Facades\DB::table('cashier_shifts')->where('id', $in['shift'])->first();
                $touch('snapshot');
                $wait('marked');
                try {
                    $count = app(\Modules\Shift\Services\ShiftTransferAttemptService::class)->recount(
                        $shift($in['shift']), \Modules\Cashier\Models\Cashier::findOrFail($in['cashier']), 10000);
                    $result = ['status' => 'PASSED_FENCE', 'counted' => $count->counted_halalas];
                } finally {
                    \Illuminate\Support\Facades\DB::rollBack();
                }
                break;
            case 'stale_manager_correct':
                // The real returned-report manager correction inside a transaction whose snapshot predates a commit.
                \Illuminate\Support\Facades\DB::beginTransaction();
                \Illuminate\Support\Facades\DB::table('cashier_shifts')->where('id', $in['shift'])->first();
                $touch('snapshot');
                $wait('marked');
                try {
                    $revision = app(\Modules\Shift\Services\ShiftReportCorrectionService::class)->correctCashierReport(
                        $shift($in['shift']), $manager(), ['handover_notes' => 'Verified document'], (int) ($in['revision'] ?? 1),
                        'Stale-snapshot manager correction', (string) \Illuminate\Support\Str::uuid(), null, true);
                    $result = ['status' => 'PASSED_FENCE', 'revision' => $revision->revision_number];
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
        if ($hold) {
            $touch('held');
            $wait('release', 45.0);
            \Illuminate\Support\Facades\DB::commit();
        }
        $result['ms'] = (int) ((microtime(true) - $started) * 1000);
    } catch (\Throwable $error) {
        while (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }
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

    /** A scope whose report A is officially returned to the manager (BRC-07 manager_returned), same SHF identity. */
    private function returnedScope(): array
    {
        $s = $this->scope(approved: true);
        $service = app(OperationService::class);
        $returned = $service->returnForReview($s['operation']->fresh(), $s['head'], 'Verify report evidence');
        $s['operation'] = $service->reject($returned, $s['accountant'],
            array_key_first(\Modules\Admin\Support\OperationEnums::rejectionReasons('shifts')), 'Manager must verify');
        $this->assertSame('manager_returned', $s['operation']->payload['pkg6Review']['phase']);

        return $s;
    }

    /** An open workday with one finished, balanced report (S1) and one untouched assignment (U), ready for submission. */
    private function dayFixture(): array
    {
        $s = $this->openDay();
        // The sales bridge attributes the day statement to a company accountant (NO_ASAB_ACTOR otherwise).
        $tag = Str::lower(Str::random(8));
        $accountant = AsabUser::create(['company_id' => $s['company']->id, 'name' => 'Accountant', 'email' => "dayacc-$tag@test.invalid", 'password' => 'test', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $template = Shift::factory()->create(['branch_id' => $s['branch']->id, 'start_time' => '06:00:00', 'end_time' => '10:00:00']);
        $a = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $s['cashier']->id, 'shift_id' => $template->id, 'shift_date' => today(),
            'total_sales' => '100.00', 'card_payments' => '0.00', 'cash_collected' => '100.00']));
        DB::transaction(function () use ($a, $s) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($a, 'cashier', $s['cashier']->id);
            app(ShiftCashCountService::class)->record($a, $revision, 10000, 0, 0, 10000);
        });
        $dayRevision = DB::transaction(fn () => app(ShiftReportRevisionService::class)->recordManagerRevision($s['day'], 'branch_manager', (string) $s['manager']->id, null));
        $s['a'] = $a;
        $s['u'] = $this->untouched($s, today());
        $s['dayRevision'] = $dayRevision->revision_number;

        return $s;
    }

    /** Everything the submitted day must keep immutable, as one comparable value. */
    private function daySnapshot(array $s): array
    {
        $day = DB::table('branch_manager_shifts')->where('id', $s['day']->id)->first();

        return [
            'submitted' => (bool) $day->daily_report_submitted,
            'locks' => DB::table('shift_liability_daily_locks')->where('branch_manager_shift_id', $s['day']->id)->whereNull('released_at')->whereNull('superseded_at')
                ->orderBy('cashier_shift_id')->get(['cashier_shift_id', 'report_revision', 'allocation_version'])->map(fn ($row) => (array) $row)->all(),
            'sales_operations' => Operation::where('module_key', 'sales')->where('payload->managerShiftId', $s['day']->id)->count(),
            'u_locks' => DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $s['u']->id)->count(),
            'u_aggregates' => DB::table('shift_report_aggregates')->where('source_id', $s['u']->id)->count(),
            'allocations' => DB::table('shift_liability_allocations')->whereIn('cashier_shift_id', [$s['a']->id, $s['u']->id])->count(),
            'postings' => DB::table('shift_liability_postings')->whereIn('cashier_shift_id', [$s['a']->id, $s['u']->id])->count(),
            'custody' => DB::table('cashier_custody_transactions')->whereIn('related_shift_id', [$s['a']->id, $s['u']->id])->count(),
            'u_status' => DB::table('cashier_shifts')->where('id', $s['u']->id)->value('status'),
            'u_history' => DB::table('cashier_shift_history')->where('cashier_shift_id', $s['u']->id)->where('action', 'marked_not_worked')->count(),
            'a_revisions' => DB::table('shift_report_revisions as r')->join('shift_report_aggregates as g', 'g.id', '=', 'r.report_aggregate_id')
                ->where('g.source_type', 'cashier_shift')->where('g.source_id', $s['a']->id)->count(),
            'a_counts' => DB::table('shift_report_cash_counts')->where('cashier_shift_id', $s['a']->id)->count(),
            'day_revisions' => DB::table('shift_report_revisions as r')->join('shift_report_aggregates as g', 'g.id', '=', 'r.report_aggregate_id')
                ->where('g.source_type', 'branch_manager_shift')->where('g.source_id', $s['day']->id)->count(),
        ];
    }

    private function submitInProcess(array $s, ?string $key = null)
    {
        return $this->actingAs($s['manager'], 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit',
            ['expected_revision' => $s['dayRevision'], 'final_notes' => 'control'], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** Start $holder with its transaction held open after its work, then start $other against it, then release the holder. */
    private function holdThen(array $holder, array $other): array
    {
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$p1, $b1] = $this->launch($holder + ['hold' => true, 'barrier' => $barrier.'.1']);
        $this->waitFor($b1.'.ready', $p1);
        file_put_contents($b1.'.go', 'go');
        $this->waitFor($b1.'.held', $p1, 40.0);
        [$p2, $b2] = $this->launch($other + ['barrier' => $barrier.'.2']);
        $this->waitFor($b2.'.ready', $p2);
        file_put_contents($b2.'.go', 'go');
        usleep(2_000_000);
        $blocked = ! file_exists($b2.'.result');
        file_put_contents($b1.'.release', 'x');
        $p1->wait();
        $p2->wait();
        $this->assertSame(0, $p1->getExitCode(), $p1->getErrorOutput());
        $this->assertSame(0, $p2->getExitCode(), $p2->getErrorOutput());

        return [json_decode(file_get_contents($b1.'.json'), true), json_decode(file_get_contents($b2.'.json'), true), $blocked];
    }

    private function submitCommand(array $s, array $extra = []): array
    {
        return $extra + ['command' => 'submit', 'manager' => $s['manager']->id, 'revision' => $s['dayRevision']];
    }

    private function markCommand(array $s, $target): array
    {
        return ['command' => 'mark', 'manager' => $s['manager']->id, 'shift' => $target->id];
    }

    public function test_control_the_day_fixture_submits_through_the_real_route(): void
    {
        $s = $this->dayFixture();
        $response = $this->submitInProcess($s);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $snapshot = $this->daySnapshot($s);
        $this->assertTrue($snapshot['submitted']);
        $this->assertCount(1, $snapshot['locks']);
        $this->assertSame($s['a']->id, $snapshot['locks'][0]['cashier_shift_id']);
        $this->assertSame(1, $snapshot['sales_operations']);
        $this->assertSame(0, $snapshot['u_locks']);
    }

    // ======================= R-01 A: no-work vs the real manager correction ================================

    public function test_a1_stale_transaction_cannot_manager_correct_a_marked_not_worked_shift(): void
    {
        // Control: the very same stale worker passes the fence on a shift that was never marked, so the fence
        // assertion below cannot be vacuous. The BRC-07 manager early-return path is live (manager_returned).
        $s = $this->returnedScope();
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$control, $controlBarrier] = $this->launch(['command' => 'stale_manager_correct', 'manager' => $s['manager']->id,
            'shift' => $s['a']->id, 'revision' => 1, 'barrier' => $barrier.'.control']);
        $this->waitFor($controlBarrier.'.snapshot', $control);
        file_put_contents($controlBarrier.'.marked', 'x');
        $control->wait();
        $controlResult = json_decode(file_get_contents($controlBarrier.'.json'), true);
        $this->assertSame('PASSED_FENCE', $controlResult['status'], 'Control must reach the correction through the early-return path: '.json_encode($controlResult));

        // Treatment: the mark commits after the stale snapshot and before the correction runs.
        $target = $this->untouched($s);
        [$stale, $staleBarrier] = $this->launch(['command' => 'stale_manager_correct', 'manager' => $s['manager']->id,
            'shift' => $target->id, 'revision' => 1, 'barrier' => $barrier.'.stale']);
        $this->waitFor($staleBarrier.'.snapshot', $stale);
        app(ShiftNotWorkedService::class)->mark($target, $s['manager'], 'Closed after the manager correction took its snapshot');
        file_put_contents($staleBarrier.'.marked', 'x');
        $stale->wait();
        $result = json_decode(file_get_contents($staleBarrier.'.json'), true);
        $this->assertNotSame('INFRA_LOCK_ERROR', $result['status'], json_encode($result));
        $this->assertSame(['status' => 'conflict', 'code' => 'SHIFT_MARKED_NOT_WORKED'], array_intersect_key($result, array_flip(['status', 'code'])), json_encode($result));
        $this->assertSame(1, $target->notWorkedHistory()->count());
        $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $target->id)->count());
    }

    public function test_a2_no_work_vs_manager_correction_of_the_returned_report_never_deadlocks_and_keeps_history(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $s = $this->returnedScope();
            $target = $this->untouched($s);
            $revisionsBefore = DB::table('shift_report_revisions')->count();
            // The returned report A is correctable; the untouched sibling is marked in parallel on the same cashier.
            [$mark, $correct] = $this->race(
                $this->markCommand($s, $target),
                ['command' => 'manager_correct', 'manager' => $s['manager']->id, 'shift' => $s['a']->id, 'revision' => 1]);
            $this->assertNoInfraLockErrors($mark, $correct);
            $this->assertSame('ok', $mark['status'], json_encode($mark));
            $this->assertSame('ok', $correct['status'], json_encode($correct));
            $this->assertSame(2, $correct['revision']);
            $this->assertSame(1, $target->notWorkedHistory()->count());
            $this->assertSame(0, $s['a']->notWorkedHistory()->count());
            $this->assertSame(2, DB::table('shift_report_revisions as r')->join('shift_report_aggregates as g', 'g.id', '=', 'r.report_aggregate_id')
                ->where('g.source_id', $s['a']->id)->count(), 'Revision 1 must be preserved next to revision 2.');
            $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $target->id)->count());
        }
    }

    public function test_a3_marking_a_shift_that_has_a_report_is_rejected_while_the_manager_corrects_it(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $s = $this->returnedScope();
            [$mark, $correct] = $this->race(
                $this->markCommand($s, $s['a']),
                ['command' => 'manager_correct', 'manager' => $s['manager']->id, 'shift' => $s['a']->id, 'revision' => 1]);
            $this->assertNoInfraLockErrors($mark, $correct);
            $this->assertSame('conflict', $mark['status'], 'A reported shift is ineligible for no-work: '.json_encode($mark));
            $this->assertContains($mark['code'], ['SHIFT_NOT_WORKED_NOT_ELIGIBLE', 'SHIFT_NOT_WORKED_HAS_EVIDENCE', 'REPORT_REOPEN_REQUIRED']);
            $this->assertSame('ok', $correct['status'], json_encode($correct));
            $this->assertSame(0, $s['a']->notWorkedHistory()->count());
            $this->assertNotSame('canceled', DB::table('cashier_shifts')->where('id', $s['a']->id)->value('status'));
        }
    }

    public function test_a4_a_sibling_branch_manager_cannot_correct_through_the_early_return_path(): void
    {
        $s = $this->returnedScope();
        $sibling = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $target = $this->untouched($s);
        [$mark, $correct] = $this->race(
            $this->markCommand($s, $target),
            ['command' => 'manager_correct', 'manager' => $sibling->id, 'shift' => $s['a']->id, 'revision' => 1]);
        $this->assertNoInfraLockErrors($mark, $correct);
        $this->assertSame('ok', $mark['status'], json_encode($mark));
        $this->assertSame('conflict', $correct['status'], 'A manager of another branch must never correct this report: '.json_encode($correct));
        $this->assertSame(1, DB::table('shift_report_revisions as r')->join('shift_report_aggregates as g', 'g.id', '=', 'r.report_aggregate_id')
            ->where('g.source_id', $s['a']->id)->count());
    }

    // ======================= R-01 B: no-work vs the real physical recount ==================================

    public function test_b1_no_work_vs_recount_rejects_marking_a_counted_shift_and_preserves_history(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $s = $this->dayFixture();
            DB::table('shift_report_aggregates')->where('source_type', 'cashier_shift')->where('source_id', $s['a']->id)->update(['fresh_count_required' => true]);
            $before = $this->daySnapshot($s);
            [$mark, $recount] = $this->race(
                $this->markCommand($s, $s['a']),
                ['command' => 'recount', 'cashier' => $s['cashier']->id, 'shift' => $s['a']->id]);
            $this->assertNoInfraLockErrors($mark, $recount);
            $this->assertSame('conflict', $mark['status'], json_encode($mark));
            $this->assertSame('ok', $recount['status'], json_encode($recount));
            $after = $this->daySnapshot($s);
            $this->assertSame($before['a_counts'] + 1, $after['a_counts'], 'The earlier count must stay; the recount adds a new one.');
            $this->assertSame($before['a_revisions'] + 1, $after['a_revisions']);
            foreach (['allocations', 'postings', 'custody', 'locks', 'sales_operations'] as $unchanged) {
                $this->assertSame($before[$unchanged], $after[$unchanged], "$unchanged must not move on a recount");
            }
            $this->assertSame(0, $s['a']->notWorkedHistory()->count());
            $this->assertNotSame('canceled', DB::table('cashier_shifts')->where('id', $s['a']->id)->value('status'));
        }
    }

    public function test_b2_no_work_vs_recount_on_an_untouched_shift_has_one_winner_and_no_evidence(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $s = $this->dayFixture();
            [$mark, $recount] = $this->race(
                $this->markCommand($s, $s['u']),
                ['command' => 'recount', 'cashier' => $s['cashier']->id, 'shift' => $s['u']->id]);
            $this->assertNoInfraLockErrors($mark, $recount);
            $this->assertSame('ok', $mark['status'], json_encode($mark));
            $this->assertNotSame('ok', $recount['status'], 'A shift with no report can never be recounted: '.json_encode($recount));
            $snapshot = $this->daySnapshot($s);
            $this->assertSame(1, $snapshot['u_history']);
            $this->assertSame(0, $snapshot['u_aggregates']);
            $this->assertSame(0, DB::table('shift_report_cash_counts')->where('cashier_shift_id', $s['u']->id)->count());
        }
    }

    public function test_b3_stale_transaction_cannot_recount_a_marked_not_worked_shift(): void
    {
        $s = $this->dayFixture();
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$stale, $staleBarrier] = $this->launch(['command' => 'stale_recount', 'cashier' => $s['cashier']->id, 'shift' => $s['u']->id, 'barrier' => $barrier.'.r']);
        $this->waitFor($staleBarrier.'.snapshot', $stale);
        app(ShiftNotWorkedService::class)->mark($s['u'], $s['manager'], 'Closed after the recount took its snapshot');
        file_put_contents($staleBarrier.'.marked', 'x');
        $stale->wait();
        $result = json_decode(file_get_contents($staleBarrier.'.json'), true);
        $this->assertNotSame('INFRA_LOCK_ERROR', $result['status'], json_encode($result));
        $this->assertSame('conflict', $result['status'], json_encode($result));
        $this->assertSame(0, DB::table('shift_report_cash_counts')->where('cashier_shift_id', $s['u']->id)->count());
        $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $s['u']->id)->count());
    }

    // ======================= R-01 C: no-work vs the real daily submission ==================================

    private function assertSubmittedDayIsAuthoritative(array $s, bool $uMarkedBeforeSubmit): void
    {
        $snapshot = $this->daySnapshot($s);
        $this->assertTrue($snapshot['submitted']);
        $this->assertCount(1, $snapshot['locks'], 'Exactly the reported shift is a member: '.json_encode($snapshot['locks']));
        $this->assertSame($s['a']->id, $snapshot['locks'][0]['cashier_shift_id']);
        $this->assertSame(0, $snapshot['u_locks'], 'A not-worked shift carries no liability membership.');
        $this->assertSame(1, $snapshot['sales_operations'], 'One sales statement per submitted day.');
        $this->assertSame(0, $snapshot['u_aggregates']);
        $this->assertSame($uMarkedBeforeSubmit ? 'canceled' : 'not_started', $snapshot['u_status']);
        $this->assertSame($uMarkedBeforeSubmit ? 1 : 0, $snapshot['u_history']);
    }

    public function test_c1_mark_commits_first_then_the_real_submit_excludes_the_marked_shift(): void
    {
        $s = $this->dayFixture();
        $reference = $this->dayFixture();
        $this->assertSame(200, $this->submitInProcess($reference)->getStatusCode());
        $referenceSnapshot = $this->daySnapshot($reference);

        app(ShiftNotWorkedService::class)->mark($s['u'], $s['manager'], 'Closed before the day close');
        $response = $this->submitInProcess($s);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSubmittedDayIsAuthoritative($s, true);
        $snapshot = $this->daySnapshot($s);
        foreach (['allocations', 'postings', 'custody'] as $effect) {
            $this->assertSame($referenceSnapshot[$effect], $snapshot[$effect], "$effect must equal the unmarked reference day: marking never adds financial effects");
        }
    }

    public function test_c2_submit_holds_its_transaction_and_the_mark_waits_then_is_rejected_on_the_submitted_day(): void
    {
        $s = $this->dayFixture();
        [$submit, $mark, $blocked] = $this->holdThen($this->submitCommand($s), $this->markCommand($s, $s['u']));
        $this->assertNoInfraLockErrors($submit, $mark);
        $this->assertSame('ok', $submit['status'], json_encode($submit));
        $this->assertTrue($blocked, 'The competing mark must wait on the submit locks, not run past them.');
        $this->assertSame('conflict', $mark['status'], 'A not-worked fact must not be added to an already submitted day: '.json_encode($mark));
        $this->assertSubmittedDayIsAuthoritative($s, false);
    }

    public function test_c3_mark_holds_its_transaction_and_the_real_submit_waits_then_excludes_it(): void
    {
        $s = $this->dayFixture();
        [$mark, $submit, $blocked] = $this->holdThen($this->markCommand($s, $s['u']), $this->submitCommand($s));
        $this->assertNoInfraLockErrors($mark, $submit);
        $this->assertSame('ok', $mark['status'], json_encode($mark));
        $this->assertTrue($blocked, 'The daily submit must wait on the mark locks, not run past them.');
        $this->assertSame('ok', $submit['status'], json_encode($submit));
        $this->assertSubmittedDayIsAuthoritative($s, true);
    }

    public function test_c4_free_race_between_mark_and_submit_has_only_two_consistent_outcomes(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 8; $i++) {
            $s = $this->dayFixture();
            [$mark, $submit] = $this->race($this->markCommand($s, $s['u']), $this->submitCommand($s));
            $this->assertNoInfraLockErrors($mark, $submit);
            $this->assertSame('ok', $submit['status'], 'The day always closes: '.json_encode([$mark, $submit]));
            $this->assertContains($mark['status'], ['ok', 'conflict']);
            $this->assertSubmittedDayIsAuthoritative($s, $mark['status'] === 'ok');
            $outcomes[$mark['status']] = ($outcomes[$mark['status']] ?? 0) + 1;
        }
        fwrite(STDERR, "\n[c4] free-race outcome distribution: ".json_encode($outcomes)."\n");
    }

    public function test_c5_stale_snapshot_submit_excludes_a_mark_committed_after_the_snapshot(): void
    {
        $s = $this->dayFixture();
        $barrier = storage_path('framework/testing/pkg67-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $this->barriers[] = $barrier;
        [$stale, $staleBarrier] = $this->launch($this->submitCommand($s, ['command' => 'stale_submit', 'shift' => $s['u']->id, 'day' => $s['day']->id, 'barrier' => $barrier.'.s']));
        $this->waitFor($staleBarrier.'.snapshot', $stale);
        app(ShiftNotWorkedService::class)->mark($s['u'], $s['manager'], 'Closed after the day-close snapshot');
        file_put_contents($staleBarrier.'.marked', 'x');
        $stale->wait();
        $result = json_decode(file_get_contents($staleBarrier.'.json'), true);
        $this->assertNotSame('INFRA_LOCK_ERROR', $result['status'], json_encode($result));
        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame([$s['a']->id], $result['lock_ids'], 'The marked shift must not enter the submitted membership: '.json_encode($result));
        // The stale transaction was rolled back, so the real submission is still possible and still excludes it.
        $this->assertFalse((bool) DB::table('branch_manager_shifts')->where('id', $s['day']->id)->value('daily_report_submitted'));
        $this->assertSame(200, $this->submitInProcess($s)->getStatusCode());
        $this->assertSubmittedDayIsAuthoritative($s, true);
    }

    public function test_c6_a_corrupt_no_work_fact_on_a_reported_shift_fails_the_submit_closed(): void
    {
        $s = $this->dayFixture();
        $before = $this->daySnapshot($s);
        DB::table('cashier_shift_history')->insert(['id' => (string) Str::uuid(), 'cashier_shift_id' => $s['a']->id, 'action' => 'marked_not_worked',
            'old_value' => null, 'new_value' => json_encode(['planted' => true]), 'performed_by_type' => 'branch_manager', 'performed_by' => $s['manager']->id, 'created_at' => now()]);
        $response = $this->submitInProcess($s);
        $this->assertNotSame(200, $response->getStatusCode(), 'A no-work fact on a shift with report evidence must never be silently dropped from a submitted day.');
        $after = $this->daySnapshot($s);
        $this->assertFalse($after['submitted']);
        foreach (['locks', 'sales_operations', 'allocations', 'postings', 'custody'] as $unchanged) {
            $this->assertSame($before[$unchanged], $after[$unchanged], $unchanged);
        }
        fwrite(STDERR, "\n[c6] fail-closed response: ".$response->getStatusCode().' '.substr($response->getContent(), 0, 160)."\n");
    }

    public function test_c7_idempotent_replay_of_the_submit_creates_no_second_effect(): void
    {
        $s = $this->dayFixture();
        app(ShiftNotWorkedService::class)->mark($s['u'], $s['manager'], 'Closed before the day close');
        $key = (string) Str::uuid();
        $first = $this->submitInProcess($s, $key);
        $this->assertSame(200, $first->getStatusCode(), $first->getContent());
        $afterFirst = $this->daySnapshot($s);
        $replay = $this->submitInProcess($s, $key);
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame($afterFirst, $this->daySnapshot($s), 'A replay is not a second financial effect.');
        $second = $this->submitInProcess($s, (string) Str::uuid());
        $this->assertNotSame(200, $second->getStatusCode(), 'A new key must not submit the same day twice.');
        $this->assertSame($afterFirst, $this->daySnapshot($s));
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
