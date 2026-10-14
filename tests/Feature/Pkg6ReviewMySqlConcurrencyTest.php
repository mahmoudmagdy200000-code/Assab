<?php

namespace Tests\Feature;

// The worker lives in this approved test path; no additional support path is needed.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--pkg6-worker') {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql' || ! preg_match('/\Apkg6_disposable_[a-z0-9_]+\z/', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new \LogicException('Worker refuses any shared/non-disposable database.');
    }
    $input = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    try {
        $result = $db->transaction(function () use ($input) {
            $source = \Modules\Shift\Models\CashierShift::withoutEagerLoads()->findOrFail($input['source']);
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->lockCashier($source);
            file_put_contents($input['barrier'].'.locked', 'locked');
            $deadline = microtime(true) + 15;
            while (! file_exists($input['barrier'].'.release')) {
                if (microtime(true) > $deadline) throw new \RuntimeException('Barrier timed out');
                usleep(10000);
            }
            $operation = \Modules\Admin\Models\Operation::findOrFail($input['operation']);
            $actor = \Modules\Admin\Models\AsabUser::findOrFail($input['actor']);
            $service = app(\Modules\Admin\Services\OperationService::class);
            switch ($input['command']) {
                case 'correct':
                case 'rollback':
                    $revision = app(\Modules\Shift\Services\AccountantReportCorrectionService::class)->correct($source, $actor,
                        ['counted_cash' => '101.00'], null, 1, null, 'Two-connection audit', $input['barrier']);
                    if ($input['command'] === 'rollback') throw new \RuntimeException('INJECTED_ROLLBACK');
                    return ['status' => 'corrected', 'revision' => $revision->revision_number];
                case 'approve': return ['status' => $service->approve($operation, $actor)->status];
                case 'final': return ['status' => $service->finalApprove($operation, $actor)->status];
                case 'return': return ['status' => $service->returnForReview($operation, $actor, 'Two-connection return')->status];
                case 'resubmit':
                    $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($input['manager']);
                    return ['status' => app(\Modules\Shift\Services\ShiftReportReviewService::class)->resubmit($source,
                        $manager, 1, null, $input['cycle'], 'Two-connection resubmit', $input['barrier'])->status];
                default: throw new \LogicException('Unsupported concurrency command');
            }
        });
    } catch (\Throwable $error) {
        $result = ['status' => 'conflict', 'code' => $error instanceof \Modules\Admin\Exceptions\AsabException
            ? $error->errorCode : $error->getMessage()];
    }
    file_put_contents($input['barrier'].'.result', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Support\Facades\DB;
use Modules\Admin\Services\OperationService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Opt-in, two independent PHP/PDO connections. SQLite must produce NOT_RUN, never PASS. */
class Pkg6ReviewMySqlConcurrencyTest extends TestCase
{
    use Pkg6ReviewFixture { setUp as private initializeFixture; }
    private array $workers = [];
    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (env('PKG6_MARIADB_TESTS') !== '1' || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT_RUN: isolated MariaDB unavailable. SQLite cannot establish financial lock safety.');
        }
        if (! preg_match('/\Apkg6_disposable_[a-z0-9_]+\z/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
            throw new \LogicException('Refusing non-disposable MariaDB schema');
        }
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS v')->v);
        // Existing approved migrations only, on the explicitly opted-in disposable schema.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::transaction(fn () => $this->initializeFixture());
        $this->assertSame(0, DB::transactionLevel()); // Workers must see committed fixtures.
    }

    protected function tearDown(): void
    {
        foreach ($this->barriers as $path) @file_put_contents($path.'.release', 'release');
        foreach ($this->workers as $worker) if ($worker->isRunning()) $worker->stop(1);
        parent::tearDown();
    }

    private function race(string $firstCommand, string $secondCommand, array $extra = []): array
    {
        $config = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'],
            'DB_PORT' => (string) ($config['port'] ?? 3306), 'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
        $start = function ($command) use ($environment, $extra) {
            $path = storage_path('framework/testing/pkg6-'.\Illuminate\Support\Str::uuid());
            if (! is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
            $this->barriers[] = $path;
            $input = array_merge(['source' => $this->source->id, 'operation' => $this->operation->id,
                'actor' => in_array($command, ['final', 'return']) ? $this->head->id : $this->accountant->id,
                'command' => $command, 'barrier' => $path], $extra);
            $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--pkg6-worker', json_encode($input)], base_path(), $environment);
            $process->setTimeout(20);
            $process->start();
            $this->workers[] = $process;
            return [$process, $path];
        };
        [$first, $one] = $start($firstCommand);
        $deadline = microtime(true) + 8;
        while (! file_exists($one.'.locked') && microtime(true) < $deadline && $first->isRunning()) usleep(10000);
        $this->assertFileExists($one.'.locked', $first->getErrorOutput());
        [$second, $two] = $start($secondCommand);
        usleep(200000);
        $this->assertTrue($second->isRunning());
        $this->assertFileDoesNotExist($two.'.locked', 'Second connection bypassed the root financial lock.');
        file_put_contents($one.'.release', 'release');
        file_put_contents($two.'.release', 'release');
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        return [json_decode(file_get_contents($one.'.result'), true), json_decode(file_get_contents($two.'.result'), true)];
    }

    public function test_correction_versus_approval_reads_new_count_after_waiting(): void
    {
        [$one, $two] = $this->race('correct', 'approve');
        $this->assertSame('corrected', $one['status']);
        $this->assertSame('approved', $two['status']);
        $current = $this->operation->fresh();
        $this->assertSame(10100, $current->payload['cashActualHalalas']);
        $this->assertSame(2, DB::table('shift_report_cash_counts')->where('cashier_shift_id', $this->source->id)->count());
    }

    public function test_return_wins_against_final_approval_without_reopening_cash(): void
    {
        app(OperationService::class)->approve($this->operation, $this->accountant);
        [$one, $two] = $this->race('return', 'final');
        $this->assertSame('pending', $one['status']);
        $this->assertSame('OP_NOT_APPROVED', $two['code']);
        $this->assertSame('head_returned', $this->operation->fresh()->payload['pkg6Review']['phase']);
        $this->assertNull($this->membership->fresh()->released_at);
    }

    public function test_two_manager_resubmissions_have_one_winner(): void
    {
        $service = app(OperationService::class);
        $approved = $service->approve($this->operation, $this->accountant);
        $returned = $service->returnForReview($approved, $this->head, 'Return');
        $returned = $service->reject($returned, $this->accountant,
            array_key_first(\Modules\Admin\Support\OperationEnums::rejectionReasons('shifts')), 'Manager review');
        [$one, $two] = $this->race('resubmit', 'resubmit', ['cycle' => $returned->payload['pkg6Review']['return_cycle'],
            'manager' => $this->day->branch_manager_id]);
        $this->assertSame('pending', $one['status']);
        $this->assertSame('REPORT_RETURN_CYCLE_REQUIRED', $two['code']);
        $this->assertSame(1, $returned->steps()->where('stage_id', 'submit')->count());
    }

    public function test_duplicate_corrections_and_rollback_have_no_duplicate_effect(): void
    {
        [$one, $two] = $this->race('rollback', 'correct');
        $this->assertSame('INJECTED_ROLLBACK', $one['code']);
        $this->assertSame('corrected', $two['status']);
        [$one, $two] = $this->race('correct', 'correct');
        $this->assertSame('STALE_REPORT_REVISION', $one['code']);
        $this->assertSame('STALE_REPORT_REVISION', $two['code']);
        $this->assertSame(2, DB::table('shift_report_cash_counts')->where('cashier_shift_id', $this->source->id)->count());
        $this->assertSame(0, DB::table('shift_liability_postings')->count());
    }
}
