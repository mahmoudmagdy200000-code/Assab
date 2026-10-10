<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Opt-in: never migrates or truncates a database. Run only after migrations on an owned audit schema. */
class ShiftPhases234MySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Separate-connection MySQL gate requires an owned disposable audit schema.');
        }
        if (! preg_match('/^s111_phase234_audit_[a-z0-9_]+$/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
            throw new \LogicException('Refusing concurrency gate on a non-audit database.');
        }
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

    public function test_two_workdays_cannot_overreserve_same_manager_cash(): void
    {
        [$manager, $days, $destinations] = $this->fixture();
        [$first, $second] = $this->race(
            ['command' => 'reserve', 'day' => $days[0]->id, 'destination' => $destinations[0]->id, 'amount' => '150.00'],
            ['command' => 'reserve', 'day' => $days[1]->id, 'destination' => $destinations[1]->id, 'amount' => '80.00']
        );
        $this->assertSame('created', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('INSUFFICIENT_RECORDED_SALES_CASH', $second['code']);
        $this->assertEquals('150.00', BranchManagerCashTransfer::whereIn('branch_manager_shift_id', array_map(fn ($day) => $day->id, $days))->where('status', 'pending')->sum('requested_amount'));
        $this->assertSame(5000, app(ShiftTransferReceiptService::class)->availableManagerCashMinor($manager->id));
    }

    public function test_replacement_wins_over_confirmation_of_cancelled_predecessor(): void
    {
        [$manager, $days, $destinations] = $this->fixture();
        $request = app(ShiftTransferReceiptService::class)->requestManagerCashTransfer(
            $days[0], Cashier::findOrFail($destinations[0]->cashier_id), $destinations[0], '60.00', $manager
        );
        [$first, $second] = $this->race(
            ['command' => 'replace', 'day' => $days[0]->id, 'request' => $request->id, 'destination' => $destinations[1]->id],
            ['command' => 'confirm', 'day' => $days[0]->id, 'request' => $request->id, 'cashier' => $destinations[0]->cashier_id]
        );
        $this->assertSame('created', $first['status']);
        $this->assertSame('HANDOVER_CANCELLED', $second['code']);
        $this->assertNotNull($request->fresh()->cancelled_at);
        $this->assertSame(1, BranchManagerCashTransfer::where('supersedes_id', $request->id)->count());
        $this->assertSame(0, CashierShiftHandoverReceipt::where('branch_manager_cash_transfer_id', $request->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutationCommands')]
    public function test_submitted_boundary_serializes_before_report_mutation(string $command): void
    {
        [, $days, $destinations] = $this->fixture();
        $source = $destinations[0];
        $source->update(['status' => 'completed', 'actual_end_time' => now(), 'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00', 'net_sales' => '86.96', 'vat_amount' => '13.04']);
        DB::transaction(function () use ($source) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $source->cashier_id, 0);
            app(ShiftCashCountService::class)->record($source, $revision, 10000, 0, 0, 10000);
        });
        [$first, $second] = $this->race(
            ['command' => 'submit_boundary', 'day' => $days[0]->id],
            ['command' => $command, 'day' => $days[0]->id, 'source' => $source->id]
        );
        $this->assertSame('submitted', $first['status']);
        $this->assertSame('REPORT_REOPEN_REQUIRED', $second['code']);
        $this->assertSame(1, app(ShiftReportRevisionService::class)->currentCashierRevision($source)->revision_number);
        $this->assertSame('0.00', (string) $source->fresh()->card_payments);
    }

    public static function mutationCommands(): array
    {
        return [['correct'], ['reopen']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutationCommands')]
    public function test_mutation_after_waiting_reads_latest_revision_and_count(string $command): void
    {
        [, $days, $destinations] = $this->fixture();
        $source = $destinations[0];
        $source->update(['status' => 'completed', 'actual_end_time' => now(), 'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00', 'net_sales' => '86.96', 'vat_amount' => '13.04']);
        $original = DB::transaction(function () use ($source) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $source->cashier_id, 0);
            app(ShiftCashCountService::class)->record($source, $revision, 10000, 0, 0, 10000);

            return $revision;
        });
        [$first, $second] = $this->race(
            ['command' => 'correct', 'day' => $days[0]->id, 'source' => $source->id],
            ['command' => $command, 'day' => $days[0]->id, 'source' => $source->id, 'revision' => 2, 'cards' => '2.00']
        );
        $this->assertSame('created', $first['status']);
        $this->assertSame('created', $second['status']);
        $this->assertSame(3, app(ShiftReportRevisionService::class)->currentCashierRevision($source)->revision_number);
        $count = app(ShiftCashCountService::class)->currentFor($source->id);
        $this->assertNotNull($count, 'The newer count committed while waiting must be carried into revision 3.');
        $this->assertSame(10000, $count->counted_halalas);
        $this->assertSame($original->id, $count->counted_revision_id);
        $this->assertSame($command === 'correct' ? 200 : 100, $count->cards_halalas);
        $snapshot = \Modules\Shift\Models\ShiftReportRevisionSnapshot::where('report_revision_id', $first['id'])->sole();
        $this->assertNotNull($snapshot->snapshot_data['cash_count']);
    }

    public function test_confirmed_receipt_wins_over_recipient_replacement(): void
    {
        [$manager, $days, $destinations] = $this->fixture();
        $request = app(ShiftTransferReceiptService::class)->requestManagerCashTransfer(
            $days[0], Cashier::findOrFail($destinations[0]->cashier_id), $destinations[0], '60.00', $manager
        );
        [$first, $second] = $this->race(
            ['command' => 'confirm', 'day' => $days[0]->id, 'request' => $request->id, 'cashier' => $destinations[0]->cashier_id],
            ['command' => 'replace', 'day' => $days[0]->id, 'request' => $request->id, 'destination' => $destinations[1]->id]
        );
        $this->assertSame('created', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('CONFIRMED_RECEIPT_IMMUTABLE', $second['code']);
        $this->assertNull($request->fresh()->cancelled_at);
        $this->assertSame(0, BranchManagerCashTransfer::where('supersedes_id', $request->id)->count());
        $receipt = CashierShiftHandoverReceipt::where('branch_manager_cash_transfer_id', $request->id)->sole();
        $this->assertSame(1, PersonalLedgerTransaction::where('receipt_id', $receipt->id)->count());
        $this->assertSame(1, \Modules\Custody\Models\CashierCustodyTransaction::where('receipt_id', $receipt->id)->count());
    }

    public function test_manager_correction_and_reopen_fit_mysql_actor_columns(): void
    {
        [$manager, , $destinations] = $this->fixture();
        $source = $destinations[0];
        $source->update(['status' => 'completed', 'actual_end_time' => now(), 'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00', 'net_sales' => '86.96', 'vat_amount' => '13.04']);
        DB::transaction(function () use ($source) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $source->cashier_id, 0);
            app(ShiftCashCountService::class)->record($source, $revision, 10000, 0, 0, 10000);
        });
        $corrected = app(\Modules\Shift\Services\ShiftReportCorrectionService::class)->correctCashierReport(
            $source, $manager, ['card_payments' => '1.00'], 1, 'Manager correction', \Illuminate\Support\Str::uuid()
        );
        $reopened = app(\Modules\Shift\Services\ShiftReportReopenService::class)->reopenCashierReport(
            $source, $manager, 2, 'Manager in-flight reopen', \Illuminate\Support\Str::uuid()
        );
        $this->assertSame('branch_manager', $corrected->created_by_type);
        $this->assertSame('branch_manager', $reopened->created_by_type);
        $this->assertSame(3, $reopened->revision_number);
    }

    private function race(array $firstInput, array $secondInput): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 's111_race_');
        unlink($barrier);
        $this->barriers[] = $barrier;
        $first = $this->worker($firstInput + ['hold' => true, 'signal' => $barrier.'.a', 'release' => $barrier]);
        $this->awaitSignal($barrier.'.a.held', $first);
        $second = $this->worker($secondInput + ['signal' => $barrier.'.b']);
        $this->awaitSignal($barrier.'.b.started', $second);
        usleep(250000);
        $this->assertTrue($second->isRunning(), 'The competing transaction must wait while the first holds its locks.');
        file_put_contents($barrier, 'release');
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());

        return [json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR), json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function worker(array $input): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/ShiftPhases234ConcurrencyWorker.php'), json_encode($input, JSON_THROW_ON_ERROR)], base_path());
        $worker->setTimeout(20);
        $worker->setEnv(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => DB::connection()->getConfig('host'), 'DB_PORT' => (string) DB::connection()->getConfig('port'), 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => DB::connection()->getConfig('password'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array']);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function awaitSignal(string $path, Process $worker): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($path)) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('Concurrency barrier not reached: '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
        }
    }

    private function fixture(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $days = [BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today()->toDateString()],
            ['status' => 'completed']
        )];
        $days[] = BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today()->subDay(), 'status' => 'completed']);
        PersonalLedgerTransaction::create(['branch_manager_id' => $manager->id, 'transaction_type' => 'Total Sales', 'amount' => '200.00', 'is_cash_in' => true, 'related_shift_id' => $days[0]->id, 'transaction_date' => now()]);
        $destinations = [];
        foreach ([0, 1] as $index) {
            $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
            $destinations[] = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(), 'status' => 'not_started']);
        }

        return [$manager, $days, $destinations];
    }
}
