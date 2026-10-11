<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Opt-in MySQL Concurrency Gate for S1-11 Phases 8, 9, 10.
 * Requires an active local MySQL server and a dedicated test schema matching s111_phase8910_test_*.
 * Proves lock serialization, stale state rejection, and race safety across independent processes.
 */
class ShiftPhases8910MySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('S111_PHASE8910_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Opt-in MySQL concurrency gate is not enabled (set S111_PHASE8910_MYSQL_TESTS=1).');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Separate-connection MySQL gate requires an owned disposable audit schema.');
        }

        if (! preg_match('/^s111_phase(567_phase)?8910_test_[a-z0-9_]+$/', DB::connection()->getDatabaseName())
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

    public function test_concurrent_settlements_with_same_expected_version_serialize_and_reject_stale(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier, $handover, $receipt] = $this->fixtureWithReceipt();

        // Two concurrent settlements race with expected_settlement_version = 0
        [$first, $second] = $this->race(
            [
                'command' => 'settle_receipt',
                'receipt_id' => $receipt->id,
                'actor_id' => $manager->id,
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1000,
                'expected_settlement_version' => 0,
                'reason' => 'First concurrent settlement winner',
            ],
            [
                'command' => 'settle_receipt',
                'receipt_id' => $receipt->id,
                'actor_id' => $manager->id,
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1500,
                'expected_settlement_version' => 0,
                'reason' => 'Second concurrent settlement loser',
            ]
        );

        $this->assertSame('settled', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('STALE_SETTLEMENT_VERSION', $second['code']);

        // Assert exact counts: exactly 1 settlement record created
        $this->assertSame(1, ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)->count());
        $this->assertSame('100.00', (string) $receipt->fresh()->confirmed_amount);

        // Assert exactly 2 counterpart ledger rows with net sum zero
        $settlement = ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)->sole();
        $this->assertSame(-1000, $settlement->signed_delta_halalas);
        $this->assertSame(1, CashierCustodyTransaction::where('settlement_id', $settlement->id)->count());
        $this->assertSame(1, PersonalLedgerTransaction::where('settlement_id', $settlement->id)->count());
    }

    public function test_concurrent_same_key_daily_submits_are_idempotent(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier, $handover, $receipt] = $this->fixtureWithReceipt();

        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

        [$first, $second] = $this->race(
            [
                'command' => 'submit_daily',
                'day_id' => $day->id,
                'idempotency_key' => $idempotencyKey,
                'final_notes' => 'Concurrent submit race identical',
                'expected_revision' => 1,
            ],
            [
                'command' => 'submit_daily',
                'day_id' => $day->id,
                'idempotency_key' => $idempotencyKey,
                'final_notes' => 'Concurrent submit race identical',
                'expected_revision' => 1,
            ]
        );

        // Both must succeed (first performs, second replays cached response)
        $this->assertSame('submitted', $first['status']);
        $this->assertSame('submitted', $second['status']);
        $this->assertSame($first['body'], $second['body']);

        // Exactly one submission recorded
        $this->assertTrue((bool) $day->fresh()->daily_report_submitted);
        $this->assertSame(1, ShiftLiabilityDailyLock::where('branch_manager_shift_id', $day->id)->count());
    }

    public function test_concurrent_same_key_different_payload_daily_submits_reject_mismatch(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier, $handover, $receipt] = $this->fixtureWithReceipt();

        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

        [$first, $second] = $this->race(
            [
                'command' => 'submit_daily',
                'day_id' => $day->id,
                'idempotency_key' => $idempotencyKey,
                'final_notes' => 'Payload version A',
                'expected_revision' => 1,
            ],
            [
                'command' => 'submit_daily',
                'day_id' => $day->id,
                'idempotency_key' => $idempotencyKey,
                'final_notes' => 'Payload version B',
                'expected_revision' => 1,
            ]
        );

        $this->assertSame('submitted', $first['status']);
        $this->assertSame('rejected', $second['status']);
        $this->assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $second['code']);

        $this->assertTrue((bool) $day->fresh()->daily_report_submitted);
        $this->assertSame(1, ShiftLiabilityDailyLock::where('branch_manager_shift_id', $day->id)->count());
    }

    public function test_concurrent_correction_vs_daily_submit_maintains_consistent_boundary(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier, $handover, $receipt] = $this->fixtureWithReceipt();

        [$first, $second] = $this->race(
            [
                'command' => 'submit_daily',
                'day_id' => $day->id,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'final_notes' => 'Daily close in boundary race',
            ],
            [
                'command' => 'accountant_correct',
                'shift_id' => $cashierShift->id,
                'accountant_id' => $accountant->id,
                'payload' => ['card_payments' => '40.00'],
                'expected_revision' => 1,
                'reason' => 'Accountant correction racing daily close',
            ]
        );

        // Either submit won and correction is rejected with REPORT_REOPEN_REQUIRED,
        // or correction won and submit is rejected with STALE_DAILY_REPORT / LIABILITY_APPROVAL_REQUIRED
        if ($first['status'] === 'submitted') {
            $this->assertContains($second['code'], ['REPORT_REOPEN_REQUIRED', 'STALE_REPORT_REVISION', 'DAILY_REPORT_LOCKED']);
            $this->assertTrue((bool) $day->fresh()->daily_report_submitted);
        } else {
            $this->assertSame('created', $second['status']);
            $this->assertFalse((bool) $day->fresh()->daily_report_submitted);
        }
    }

    public function test_f12_concurrent_returns_across_receipts_cannot_spend_same_manager_cash(): void
    {
        [, $manager, , $day, $source, , $request, $receipt] = $this->fixtureWithReceipt();
        $other = CashierShiftHandover::create(array_merge($request->getAttributes(), ['id' => (string) \Illuminate\Support\Str::uuid()]));
        $secondReceipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $other->id, 'receiving_branch_manager_id' => $manager->id,
            'receiving_branch_manager_shift_id' => $day->id, 'report_revision_id' => $receipt->report_revision_id,
            'confirmed_amount' => '100.00', 'confirmed_at' => now(),
        ]);
        [$first, $second] = $this->race(
            ['command' => 'settle_receipt', 'receipt_id' => $receipt->id, 'actor_id' => $manager->id,
                'settlement_type' => 'overpayment_return', 'amount_halalas' => 8000, 'expected_settlement_version' => 0],
            ['command' => 'settle_receipt', 'receipt_id' => $secondReceipt->id, 'actor_id' => $manager->id,
                'settlement_type' => 'overpayment_return', 'amount_halalas' => 8000, 'expected_settlement_version' => 0]
        );
        $this->assertSame('settled', $first['status']);
        $this->assertSame('INSUFFICIENT_RECORDED_SALES_CASH', $second['code']);
        $this->assertSame(1, ShiftHandoverSettlement::whereIn('original_receipt_id', [$receipt->id, $secondReceipt->id])->count());
    }

    public function test_f12_reservation_and_settlement_share_manager_cash_mutex(): void
    {
        [$branch, $manager, , $day, , , , $receipt] = $this->fixtureWithReceipt();
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::where('branch_id', $branch->id)->firstOrFail();
        $destination = CashierShift::factory()->create(['cashier_id' => $cashier->id,
            'shift_id' => $template->id, 'shift_date' => today(), 'status' => 'not_started']);
        [$first, $second] = $this->race(
            ['command' => 'reserve_manager', 'day_id' => $day->id, 'destination_id' => $destination->id, 'amount' => '80.00'],
            ['command' => 'settle_receipt', 'receipt_id' => $receipt->id, 'actor_id' => $manager->id,
                'settlement_type' => 'overpayment_return', 'amount_halalas' => 3000, 'expected_settlement_version' => 0]
        );
        $this->assertSame('reserved', $first['status']);
        $this->assertSame('INSUFFICIENT_RECORDED_SALES_CASH', $second['code']);
        $this->assertSame(0, ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)->count());
    }

    public function test_f12_ordinary_approval_serializes_before_daily_submit(): void
    {
        [, $manager, , $day, $source, $cashier] = $this->fixtureWithReceipt();
        DB::transaction(function () use ($source, $cashier) {
            // An independent fresh count creates a real new revision, not edits to immutable evidence.
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id, 1);
            app(ShiftCashCountService::class)->record($source, $revision, 10000, 2000, 0, 7000);
            app(\Modules\Shift\Liability\ShiftLiabilityService::class)->allocate($source->id, $cashier,
                [['type' => 'cashier', 'id' => $cashier->id, 'amount' => 1000]], 0, true);
        });
        // The worker takes the explicit current revision from this input.
        [$first, $second] = $this->race(
            ['command' => 'approve_liability', 'shift_id' => $source->id, 'manager_id' => $manager->id, 'expected_revision' => 2],
            ['command' => 'submit_daily', 'day_id' => $day->id, 'expected_revision' => 1,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid()]
        );
        $this->assertSame('approved', $first['status']);
        $this->assertSame('submitted', $second['status']);
        $this->assertSame(1, \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $source->id)->count());
    }

    public function test_f12_new_financial_member_cannot_cross_completed_daily_boundary(): void
    {
        [$branch, , , $day] = $this->fixtureWithReceipt();
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::where('branch_id', $branch->id)->firstOrFail();
        $source = CashierShift::factory()->create(['cashier_id' => $cashier->id,
            'shift_id' => $template->id, 'shift_date' => today(), 'status' => 'not_started']);
        [$first, $second] = $this->race(
            ['command' => 'submit_daily', 'day_id' => $day->id, 'expected_revision' => 1,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid()],
            ['command' => 'create_report', 'shift_id' => $source->id]
        );
        $this->assertSame('submitted', $first['status']);
        $this->assertSame('REPORT_REOPEN_REQUIRED', $second['code']);
        $this->assertSame(0, \Modules\Shift\Models\ShiftReportAggregate::where('source_type', 'cashier_shift')->where('source_id', $source->id)->count());
    }

    public function test_f12_receipt_and_settlement_serialize_recorded_manager_cash(): void
    {
        [$branch, $manager, , , , , , $original] = $this->fixtureWithReceipt();
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::where('branch_id', $branch->id)->firstOrFail();
        $source = CashierShift::factory()->completed()->create(['cashier_id' => $cashier->id,
            'shift_id' => $template->id, 'shift_date' => today(), 'total_sales' => '50.00',
            'card_payments' => '0.00', 'cash_collected' => '50.00']);
        $request = DB::transaction(function () use ($source, $cashier, $manager) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id, 0);
            app(ShiftCashCountService::class)->record($source, $revision, 5000, 0, 0, 5000);
            \Modules\Shift\Models\ShiftHandoverStatus::create([
                'cashier_shift_id' => $source->id, 'status' => 'pending',
            ]);

            return CashierShiftHandover::create(['cashier_shift_id' => $source->id,
                'handover_to_type' => 'branch_manager', 'handover_to_id' => $manager->id,
                'handover_amount' => '50.00', 'handover_date' => today(), 'handover_time' => now(),
                'status' => 'pending', 'report_revision_id' => $revision->id]);
        });
        [$first, $second] = $this->race(
            ['command' => 'confirm_manager_receipt', 'request_id' => $request->id, 'manager_id' => $manager->id, 'amount' => '50.00'],
            ['command' => 'settle_receipt', 'receipt_id' => $original->id, 'actor_id' => $manager->id,
                'settlement_type' => 'overpayment_return', 'amount_halalas' => 9000, 'expected_settlement_version' => 0]
        );
        $this->assertSame('received', $first['status']);
        $this->assertSame('settled', $second['status']);
        $this->assertSame(1, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $request->id)->count());
        DB::transaction(function () use ($manager) {
            $this->assertSame(6000, app(\Modules\Shift\Services\ShiftTransferReceiptService::class)->availableManagerCashMinor($manager->id));
        });
    }

    private function race(array $firstInput, array $secondInput): array
    {
        $id = uniqid('barrier_', true);
        $signal1 = sys_get_temp_dir().'/s111_p8910_'.$id.'_w1';
        $signal2 = sys_get_temp_dir().'/s111_p8910_'.$id.'_w2';
        $release = sys_get_temp_dir().'/s111_p8910_'.$id.'_rel';
        $this->barriers[] = $release;

        $firstInput['signal'] = $signal1;
        $firstInput['release'] = $release;
        $firstInput['hold'] = true;

        $secondInput['signal'] = $signal2;
        $secondInput['release'] = $release;
        $secondInput['hold'] = false;

        $worker1 = $this->worker($firstInput);
        $this->awaitSignal($signal1.'.held', $worker1);

        $worker2 = $this->worker($secondInput);
        $this->awaitSignal($signal2.'.started', $worker2);

        file_put_contents($release, 'release');

        $worker1->wait();
        $worker2->wait();
        $this->assertSame(0, $worker1->getExitCode(), $worker1->getErrorOutput());
        $this->assertSame(0, $worker2->getExitCode(), $worker2->getErrorOutput());

        $out1 = json_decode(trim($worker1->getOutput()), true);
        $out2 = json_decode(trim($worker2->getOutput()), true);

        if (! is_array($out1) || ! is_array($out2)) {
            $this->fail("Malformed worker JSON output:\nWorker 1: ".$worker1->getOutput().' Err: '.$worker1->getErrorOutput()."\nWorker 2: ".$worker2->getOutput().' Err: '.$worker2->getErrorOutput());
        }

        return [$out1, $out2];
    }

    private function worker(array $input): Process
    {
        $php = 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe';
        if (! is_file($php)) {
            $php = PHP_BINARY;
        }
        $worker = new Process([$php, base_path('tests/Support/ShiftPhases8910ConcurrencyWorker.php'), json_encode($input, JSON_THROW_ON_ERROR)], base_path());
        $worker->setTimeout(30);
        $port = DB::connection()->getConfig('port') ?: env('DB_PORT', '3310');
        $worker->setEnv([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) DB::connection()->getConfig('host'),
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'DB_USERNAME' => DB::connection()->getConfig('username'),
            'DB_PASSWORD' => DB::connection()->getConfig('password'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function awaitSignal(string $path, Process $worker): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('Concurrency barrier not reached: '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
        }
    }

    public function test_review_cashier_reservation_and_settlement_cannot_overdraw_in_either_order(): void
    {
        foreach ([true, false] as $settlementFirst) {
            [$branch, $manager, , $day] = $this->fixtureWithReceipt();
            $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
            $template = Shift::where('branch_id', $branch->id)->firstOrFail();
            $source = CashierShift::create(['cashier_id' => $cashier->id, 'shift_id' => $template->id,
                'shift_date' => today(), 'status' => 'completed', 'total_sales' => '100.00', 'cash_collected' => '100.00']);
            $reserveSource = CashierShift::create(['cashier_id' => $cashier->id, 'shift_id' => $template->id,
                'shift_date' => today()->subDay(), 'status' => 'completed', 'total_sales' => '0.00']);
            $receipt = DB::transaction(function () use ($source, $reserveSource, $cashier, $manager, $day) {
                $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id, 0);
                app(ShiftCashCountService::class)->record($source, $revision, 10000, 0, 0, 10000);
                $reserveRevision = app(ShiftReportRevisionService::class)->recordCashierRevision($reserveSource, 'cashier', $cashier->id, 0);
                app(ShiftCashCountService::class)->record($reserveSource, $reserveRevision, 0, 0, 0, 0);
                $original = CashierShiftHandover::create(['cashier_shift_id' => $source->id,
                    'handover_to_type' => 'branch_manager', 'handover_to_id' => $manager->id, 'handover_amount' => '0.00',
                    'status' => 'approved', 'handover_date' => today(), 'handover_time' => now(), 'report_revision_id' => $revision->id]);

                return CashierShiftHandoverReceipt::create(['cashier_shift_handover_id' => $original->id,
                    'receiving_branch_manager_id' => $manager->id, 'receiving_branch_manager_shift_id' => $day->id,
                    'confirmed_amount' => '0.00', 'confirmed_at' => now(), 'report_revision_id' => $revision->id]);
            });
            $settlement = ['command' => 'settle_receipt', 'receipt_id' => $receipt->id, 'actor_id' => $manager->id,
                'settlement_type' => 'underpayment_supplement', 'amount_halalas' => 8000, 'expected_settlement_version' => 0];
            $reservation = ['command' => 'reserve_cashier', 'shift_id' => $reserveSource->id,
                'manager_id' => $manager->id, 'amount' => '80.00'];
            [$first, $second] = $this->race($settlementFirst ? $settlement : $reservation, $settlementFirst ? $reservation : $settlement);
            $this->assertSame($settlementFirst ? 'settled' : 'reserved', $first['status'], json_encode($first));
            $this->assertSame('INSUFFICIENT_CASHIER_CUSTODY_CASH', $second['code'], json_encode($second));
            $this->assertSame($settlementFirst ? 1 : 0, ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)->count());
            $this->assertSame($settlementFirst ? 0 : 1, CashierShiftHandover::where('cashier_shift_id', $reserveSource->id)->count());
        }
    }

    private function fixtureWithReceipt(): array
    {
        $company = AsabCompany::create([
            'name' => 'MySQL S111 Company '.uniqid(),
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'MySQL S111 Brand '.uniqid(),
            'abbr' => 'MSB',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $branch = Branch::factory()->create([
            'asab_company_id' => $company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);

        $accountant = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Accountant '.uniqid(),
            'email' => 'accountant_s111_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role_key' => 'accountant',
            'status' => 'active',
            'is_active' => true,
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
            'branch_ids' => [$branch->id],
            'brand_ids' => [$brand->id],
        ]);

        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $day = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'daily_report_submitted' => false,
        ]);
        DB::transaction(function () use ($day, $manager) {
            app(ShiftReportRevisionService::class)->recordManagerRevision($day, 'branch_manager', $manager->id, 0);
        });

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'cash_collected' => '80.00',
            'card_payments' => '20.00',
            'net_sales' => '86.96',
            'vat_amount' => 13.04,
            'closing_balance' => '80.00',
        ]);

        $revision = DB::transaction(function () use ($cashierShift, $cashier) {
            $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($cashierShift, 'cashier', $cashier->id, 0);
            app(ShiftCashCountService::class)->record($cashierShift, $rev, 10000, 2000, 0, 8000);

            return $rev;
        });

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'shift_id' => $template->id,
            'branch_id' => $branch->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $manager->id,
            'handover_amount' => '80.00',
            'declared_amount' => '80.00',
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
            'report_revision_id' => $revision->id,
        ]);

        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_shift_id' => $day->id,
            'receiving_branch_manager_id' => $manager->id,
            'confirmed_by_branch_manager_id' => $manager->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => '100.00',
            'confirmed_at' => now(),
        ]);

        PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '100.00',
            'is_cash_in' => true,
            'related_shift_id' => $day->id,
            'transaction_date' => now(),
        ]);

        return [$branch, $manager, $accountant, $day, $cashierShift, $cashier, $handover, $receipt];
    }
}
