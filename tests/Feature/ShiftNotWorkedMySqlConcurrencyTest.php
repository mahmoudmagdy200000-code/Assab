<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Executable independent-process gate; not run without separately approved MariaDB environment. */
class ShiftNotWorkedMySqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PKG7_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('MariaDB/RG-01 NOT_RUN: requires explicit approved PKG7_MYSQL_TESTS=1.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! preg_match('/^pkg7_test_[a-z0-9_]+$/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
            throw new \LogicException('Refusing non-disposable/non-local MariaDB target.');
        }
    }

    public function test_two_marks_commit_one_audit_fact(): void
    {
        $this->race('mark');
    }

    public function test_mark_vs_start_has_one_authoritative_outcome(): void
    {
        $this->race('start');
    }

    public function test_mark_vs_reassignment_cannot_revive_a_marked_row(): void
    {
        $this->race('reassign');
    }

    private function race(string $secondAction): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id]);
        $shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $slot->id, 'shift_date' => today()]);
        $replacement = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $barrier = tempnam(sys_get_temp_dir(), 'pkg7_');
        unlink($barrier);
        $workers = [];
        try {
            foreach (['mark', $secondAction] as $i => $action) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/ShiftNotWorkedConcurrencyWorker.php'),
                    $shift->id, $manager->id, $action, $barrier, (string) $i, $replacement->id], base_path());
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.0');
            $this->assertFileExists($barrier.'.1');
            file_put_contents($barrier, 'release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(1, count(array_filter($results, fn ($result) => $result['ok'])));
            $shift = $shift->fresh();
            $this->assertContains($shift->status->value, ['canceled', 'in_progress', 'reassigned']);
            $this->assertSame($shift->status->value === 'canceled' ? 1 : 0, $shift->notWorkedHistory()->count());
            $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $shift->id)->count());
        } finally {
            file_put_contents($barrier, 'release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$barrier, $barrier.'.0', $barrier.'.1'] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }
}
