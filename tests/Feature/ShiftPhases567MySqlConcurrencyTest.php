<?php

namespace Tests\Feature;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportCorrectionNotification;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportManagerReviewService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftReportRevisionSnapshotService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Opt-in MySQL Concurrency Gate for S1-11 Phases 5, 6, 7.
 * Requires an active local MySQL server and a dedicated test schema matching s111_phase567_test_*.
 * Never runs against production, SQLite, or unverified hosts.
 */
class ShiftPhases567MySqlConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('S111_PHASE567_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Opt-in MySQL concurrency gate is not enabled (set S111_PHASE567_MYSQL_TESTS=1).');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Separate-connection MySQL gate requires an owned disposable audit schema.');
        }

        if (! preg_match('/^s111_phase567_test_[a-z0-9_]+$/', DB::connection()->getDatabaseName())
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

    public function test_concurrent_accountant_corrections_with_same_expected_version_serialize_and_reject_stale(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift] = $this->fixture();

        $initialRevisions = $this->revisionCount($cashierShift);
        $initialCorrections = ShiftReportCorrection::where('actor_id', $accountant->id)->count();

        [$first, $second] = $this->race(
            [
                'command' => 'accountant_correct',
                'shift_id' => $cashierShift->id,
                'accountant_id' => $accountant->id,
                'payload' => ['card_payments' => '30.00'],
                'expected_revision' => 1,
            ],
            [
                'command' => 'accountant_correct',
                'shift_id' => $cashierShift->id,
                'accountant_id' => $accountant->id,
                'payload' => ['card_payments' => '40.00'],
                'expected_revision' => 1,
            ]
        );

        $this->assertSame('created', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('STALE_REPORT_REVISION', $second['code']);

        // Assert exact counts: exactly one new revision, one new correction, zero financial entries
        $this->assertSame($initialRevisions + 1, $this->revisionCount($cashierShift));
        $this->assertSame('30.00', (string) $cashierShift->fresh()->card_payments);
        $this->assertSame($initialCorrections + 1, ShiftReportCorrection::where('actor_id', $accountant->id)->count());
        $this->assertSame(0, ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->count());
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());

        $currentRev = $this->currentRevision($cashierShift);
        $this->assertSame(1, ShiftReportCorrectionNotification::where('report_revision_id', $currentRev->id)->count());
    }

    public function test_concurrent_liability_allocations_serialize_version_and_reject_stale_expected_allocation(): void
    {
        [, , , , $cashierShift, $cashier] = $this->fixture(7000);
        $shares = [['type' => 'cashier', 'id' => $cashier->id, 'amount' => 1000]];

        [$first, $second] = $this->race(
            [
                'command' => 'allocate_liability',
                'shift_id' => $cashierShift->id,
                'cashier_id' => $cashier->id,
                'shares' => $shares,
                'expected_version' => 0,
            ],
            [
                'command' => 'allocate_liability',
                'shift_id' => $cashierShift->id,
                'cashier_id' => $cashier->id,
                'shares' => $shares,
                'expected_version' => 0,
            ]
        );

        $this->assertSame('allocated', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('STALE_ALLOCATION', $second['code']);
        $allocations = ShiftLiabilityAllocation::where('cashier_shift_id', $cashierShift->id)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame(1, $allocations->sole()->version);
        $this->assertSame(-1000, $allocations->sole()->variance_halalas);
        $this->assertSame(1000, (int) $allocations->sole()->shares()->sum('amount_halalas'));
    }

    public function test_correction_vs_manager_approval_rejects_stale_revision(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier] = $this->fixture();

        // Capture stale revision expectation before the race
        $staleRevisionNumber = 1;

        $initialRevisions = $this->revisionCount($cashierShift);
        $initialPostings = ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->count();
        $initialCustody = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count();

        // Accountant submits a correction that introduces a real shortage of 10.00 SAR allocated to cashier
        // Competing manager approval races targeting the captured stale revision 1
        [$first, $second] = $this->race(
            [
                'command' => 'accountant_correct',
                'shift_id' => $cashierShift->id,
                'accountant_id' => $accountant->id,
                'payload' => ['card_payments' => '10.00'],
                'shares' => [
                    ['type' => 'cashier', 'id' => $cashier->id, 'amount' => 1000],
                ],
                'expected_revision' => $staleRevisionNumber,
                'reason' => 'Accountant corrected cards to 10.00 creating 10.00 shortage',
            ],
            [
                'command' => 'manager_approve',
                'shift_id' => $cashierShift->id,
                'manager_id' => $manager->id,
                'expected_revision' => $staleRevisionNumber,
                'expected_allocation_version' => 1,
                'reason' => 'Manager approving stale revision 1',
            ]
        );

        // Accountant must succeed creating revision 2
        $this->assertSame('created', $first['status']);
        $this->assertSame(2, $first['revision_number']);

        // Competing manager approval targeting stale revision 1 must be specifically rejected with STALE_REPORT_REVISION
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('STALE_REPORT_REVISION', $second['code']);

        // Assert exactly one new revision created, exactly zero liability postings, zero custody entries
        $this->assertSame($initialRevisions + 1, $this->revisionCount($cashierShift));
        $this->assertSame($initialPostings, ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->count());
        $this->assertSame($initialCustody, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());

        // Assert exactly 2 outbox events recorded (report_corrected + liability_share_changed)
        $currentRev = $this->currentRevision($cashierShift);
        $this->assertSame(2, ShiftReportCorrectionNotification::where('report_revision_id', $currentRev->id)->count());
    }

    public function test_concurrent_manager_approvals_with_shortage_are_idempotent_or_reject_duplicate(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier] = $this->fixture();

        // Setup real shortage of 10.00 SAR (1000 halalas) via accountant correction
        $shares = [
            ['type' => 'cashier', 'id' => $cashier->id, 'amount' => 1000],
        ];
        $rev2 = app(AccountantReportCorrectionService::class)->correct(
            $cashierShift,
            $accountant,
            ['card_payments' => '10.00'],
            $shares,
            1,
            null,
            'Real shortage setup of 10.00 SAR',
            (string) \Illuminate\Support\Str::uuid()
        );
        $this->assertSame(2, $rev2->revision_number);

        // Assert pending manager review exists
        $review = ShiftReportManagerReview::where('report_revision_id', $rev2->id)->sole();
        $this->assertSame('pending', $review->status);
        $this->assertTrue((bool) $review->has_shortage);

        // Two concurrent approvals race to approve Revision 2
        [$first, $second] = $this->race(
            [
                'command' => 'manager_approve',
                'shift_id' => $cashierShift->id,
                'manager_id' => $manager->id,
                'expected_revision' => 2,
                'expected_allocation_version' => 1,
                'reason' => 'Manager approval winner',
            ],
            [
                'command' => 'manager_approve',
                'shift_id' => $cashierShift->id,
                'manager_id' => $manager->id,
                'expected_revision' => 2,
                'expected_allocation_version' => 1,
                'reason' => 'Manager approval competing duplicate',
            ]
        );

        $this->assertSame('approved', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('NO_PENDING_MANAGER_REVIEW', $second['code']);

        // Assert exactly one posting row created with exact delta of 1000 halalas (10.00 SAR)
        $postings = ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->get();
        $this->assertCount(1, $postings);
        $this->assertSame(1000, (int) $postings->first()->delta_halalas);
        $this->assertSame('posted', $postings->first()->status);

        // Assert exactly one custody ledger row created with amount 10.00 SAR
        $custody = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->get();
        $this->assertCount(1, $custody);
        $this->assertEquals(10.00, (float) $custody->first()->amount);
        $this->assertSame('Variance', $custody->first()->transaction_type);
        $this->assertFalse((bool) $custody->first()->is_cash_in, 'Debit shortage charge must have is_cash_in=false');
    }

    public function test_redistribution_vs_posting_actual_race_and_union_consistency(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier1] = $this->fixture();
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);

        $service = app(AccountantReportCorrectionService::class);

        // Step 1: Initial shortage of 10.00 SAR allocated 100% to Cashier 1, creating Revision 2 with pending review
        $shares1 = [
            ['type' => 'cashier', 'id' => $cashier1->id, 'amount' => 1000],
        ];
        $rev2 = $service->correct(
            $cashierShift,
            $accountant,
            ['card_payments' => '10.00'],
            $shares1,
            1,
            null,
            'Initial 10.00 shortage on Cashier 1',
            (string) \Illuminate\Support\Str::uuid()
        );
        $this->assertSame(2, $rev2->revision_number);

        // Step 2: ACTUAL RACE between redistribution and manager approval:
        // Worker A (Accountant): Redistributes shortage (600 to Cashier 1, 400 to Cashier 2) targeting revision 2
        // Worker B (Manager): Approves initial revision 2
        [$first, $second] = $this->race(
            [
                'command' => 'accountant_correct',
                'shift_id' => $cashierShift->id,
                'accountant_id' => $accountant->id,
                'payload' => ['card_payments' => '10.00'],
                'shares' => [
                    ['type' => 'cashier', 'id' => $cashier1->id, 'amount' => 600],
                    ['type' => 'cashier', 'id' => $cashier2->id, 'amount' => 400],
                ],
                'expected_revision' => 2,
                'expected_allocation_version' => 1,
                'reason' => 'Redistribute shortage to 60/40 concurrently',
            ],
            [
                'command' => 'manager_approve',
                'shift_id' => $cashierShift->id,
                'manager_id' => $manager->id,
                'expected_revision' => 2,
                'expected_allocation_version' => 1,
                'reason' => 'Competing approval targeting rev 2',
            ]
        );

        // Redistribution won: created revision 3
        $this->assertSame('created', $first['status']);
        $this->assertSame(3, $first['revision_number']);

        // Competing approval of stale revision 2 rejected with STALE_REPORT_REVISION
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('STALE_REPORT_REVISION', $second['code']);

        // Partial posting prevented: zero postings and zero custody transactions exist
        $this->assertSame(0, ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->count());
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());

        // Step 3: Now manager approves the current replacement Revision 3
        $review3 = app(ShiftReportManagerReviewService::class)->approveCurrent(
            $cashierShift,
            $manager,
            3,
            2,
            'Manager approved redistributed Revision 3',
            (string) \Illuminate\Support\Str::uuid()
        );
        $this->assertSame('approved', $review3->status);

        // Prove final financial ledger records from actual rows:
        $custodyRows = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->get();
        $this->assertCount(2, $custodyRows);

        $c1Entry = $custodyRows->where('cashier_id', $cashier1->id)->first();
        $c2Entry = $custodyRows->where('cashier_id', $cashier2->id)->first();

        $this->assertNotNull($c1Entry);
        $this->assertNotNull($c2Entry);
        $this->assertEquals(6.00, (float) $c1Entry->amount);
        $this->assertSame('Variance', $c1Entry->transaction_type);
        $this->assertFalse((bool) $c1Entry->is_cash_in, 'Debit shortage charge must have is_cash_in=false');
        $this->assertEquals(4.00, (float) $c2Entry->amount);
        $this->assertSame('Variance', $c2Entry->transaction_type);
        $this->assertFalse((bool) $c2Entry->is_cash_in, 'Debit shortage charge must have is_cash_in=false');

        // Prove all postings are fully posted without partial failure
        $postings = ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->get();
        $this->assertCount(2, $postings);
        foreach ($postings as $p) {
            $this->assertSame('posted', $p->status);
        }
    }

    public function test_redistribution_after_posted_shortage_preserves_balance_and_applies_atomic_deltas(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier1] = $this->fixture();
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);

        $service = app(AccountantReportCorrectionService::class);

        // Initial 10.00 shortage on Cashier 1 approved and posted
        $service->correct(
            $cashierShift,
            $accountant,
            ['card_payments' => '10.00'],
            [['type' => 'cashier', 'id' => $cashier1->id, 'amount' => 1000]],
            1,
            null,
            'Initial 10.00 shortage on Cashier 1',
            (string) \Illuminate\Support\Str::uuid()
        );
        app(ShiftReportManagerReviewService::class)->approveCurrent(
            $cashierShift,
            $manager,
            2,
            1,
            'Initial approval',
            (string) \Illuminate\Support\Str::uuid()
        );
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());

        // Accountant redistributes: Cashier 1 = 6.00, Cashier 2 = 4.00
        $rev3 = $service->correct(
            $cashierShift,
            $accountant,
            ['card_payments' => '10.00'],
            [
                ['type' => 'cashier', 'id' => $cashier1->id, 'amount' => 600],
                ['type' => 'cashier', 'id' => $cashier2->id, 'amount' => 400],
            ],
            2,
            1,
            'Redistribute shortage: 60/40',
            (string) \Illuminate\Support\Str::uuid()
        );
        $this->assertSame(3, $rev3->revision_number);

        // Pending evidence does not change the previously approved financial balance.
        $this->assertSame(1000, $this->ledgerNetHalalas($cashierShift, $cashier1));
        $this->assertSame(0, $this->ledgerNetHalalas($cashierShift, $cashier2));

        // Concurrent approval race on Revision 3
        [$first, $second] = $this->race(
            [
                'command' => 'manager_approve',
                'shift_id' => $cashierShift->id,
                'manager_id' => $manager->id,
                'expected_revision' => 3,
                'expected_allocation_version' => 2,
            ],
            [
                'command' => 'manager_approve',
                'shift_id' => $cashierShift->id,
                'manager_id' => $manager->id,
                'expected_revision' => 3,
                'expected_allocation_version' => 2,
            ]
        );

        $this->assertSame('approved', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('NO_PENDING_MANAGER_REVIEW', $second['code']);

        // Verify union consistency: no partial reversal
        // Cashier 1 initial: 10.00 debit, delta: -4.00 credit => net balance = 6.00
        // Cashier 2 delta: +4.00 debit => net balance = 4.00
        // Total net liability across actors = exactly 10.00
        $this->assertSame(3, ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->count());
        $custodyRows = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->get();
        $this->assertCount(3, $custodyRows);
        $aDebit = $custodyRows->where('cashier_id', $cashier1->id)->where('is_cash_in', false)->sole();
        $aCredit = $custodyRows->where('cashier_id', $cashier1->id)->where('is_cash_in', true)->sole();
        $bDebit = $custodyRows->where('cashier_id', $cashier2->id)->sole();
        $this->assertSame(1000, ShiftFinancialCalculator::storedSarToHalalas($aDebit->amount));
        $this->assertSame(400, ShiftFinancialCalculator::storedSarToHalalas($aCredit->amount));
        $this->assertSame(400, ShiftFinancialCalculator::storedSarToHalalas($bDebit->amount));
        $this->assertFalse((bool) $bDebit->is_cash_in);
        $this->assertSame(600, $this->ledgerNetHalalas($cashierShift, $cashier1));
        $this->assertSame(400, $this->ledgerNetHalalas($cashierShift, $cashier2));

        $c1Net = (int) ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)
            ->where('responsible_id', $cashier1->id)->sum('delta_halalas');
        $c2Net = (int) ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)
            ->where('responsible_id', $cashier2->id)->sum('delta_halalas');

        $this->assertSame(600, $c1Net, 'Cashier 1 net liability must be 600 halalas (6.00 SAR).');
        $this->assertSame(400, $c2Net, 'Cashier 2 net liability must be 400 halalas (4.00 SAR).');
        $this->assertSame(1000, $c1Net + $c2Net, 'Total net liability must remain 1000 halalas (10.00 SAR).');
    }

    public function test_forced_deadlock_retry_does_not_duplicate_balance_history_outbox_or_notifications(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift, $cashier] = $this->fixture();
        // Fail after real evidence writes and outbox callback registration, before commit.
        $snapshots = new class extends ShiftReportRevisionSnapshotService
        {
            public int $attempts = 0;

            public int $committedCallbacks = 0;

            public array $rolledBackNotificationIds = [];

            public ?string $rolledBackRevisionId = null;

            public function createSnapshotIfMissing(
                ShiftReportRevision $revision,
                CashierShift|BranchManagerShift $shift
            ): ?\Modules\Shift\Models\ShiftReportRevisionSnapshot {
                $snapshot = parent::createSnapshotIfMissing($revision, $shift);
                if ($revision->revision_number === 2) {
                    $this->attempts++;
                    DB::afterCommit(function () {
                        $this->committedCallbacks++;
                    });
                    if ($this->attempts === 1) {
                        $this->rolledBackRevisionId = $revision->id;
                        $this->rolledBackNotificationIds = ShiftReportCorrectionNotification::where('report_revision_id', $revision->id)->pluck('id')->all();
                        throw new QueryException('mysql', 'fault after correction writes', [],
                            new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213));
                    }
                }

                return $snapshot;
            }
        };
        app()->instance(ShiftReportRevisionSnapshotService::class, $snapshots);
        $operationId = (string) \Illuminate\Support\Str::uuid();
        $rev2 = app(AccountantReportCorrectionService::class)->correct(
            $cashierShift, $accountant, ['card_payments' => '10.00'],
            [['type' => 'cashier', 'id' => $cashier->id, 'amount' => 1000]],
            1, null, 'Shortage correction with injected retry', $operationId
        );
        $this->assertSame(2, $snapshots->attempts);
        $this->assertSame(1, $snapshots->committedCallbacks, 'Discard failed-attempt afterCommit callbacks.');
        $this->assertCount(2, $snapshots->rolledBackNotificationIds);
        $this->assertFalse(ShiftReportRevision::whereKey($snapshots->rolledBackRevisionId)->exists());
        $this->assertSame(0, ShiftReportCorrectionNotification::whereIn('id', $snapshots->rolledBackNotificationIds)->count());
        $this->assertSame(0, DB::table('notifications')->whereIn('id', $snapshots->rolledBackNotificationIds)->count());
        $this->assertSame(2, $this->revisionCount($cashierShift));
        $this->assertSame(2, ShiftReportCorrection::where('operation_id', $operationId)->count());
        $outbox = ShiftReportCorrectionNotification::where('report_revision_id', $rev2->id)->get();
        $this->assertCount(2, $outbox);
        $this->assertSame(2, DB::table('notifications')->whereIn('id', $outbox->pluck('id'))->count());
        $this->assertSame(0, app(\Modules\Shift\Services\ShiftReportCorrectionNotificationService::class)->dispatchPending($rev2->id));
        $this->assertSame(2, DB::table('notifications')->whereIn('id', $outbox->pluck('id'))->count());

        // Fail separately after approval writes its actual ledger and posting.
        $postingAttempts = 0;
        $rolledBackPostingId = null;
        $rolledBackLedgerId = null;
        Event::listen('eloquent.created: '.ShiftLiabilityPosting::class,
            function (ShiftLiabilityPosting $posting) use (&$postingAttempts, &$rolledBackPostingId, &$rolledBackLedgerId, $cashierShift): void {
                if ($posting->cashier_shift_id !== $cashierShift->id) {
                    return;
                }
                $postingAttempts++;
                if ($postingAttempts === 1) {
                    $rolledBackPostingId = $posting->id;
                    $rolledBackLedgerId = $posting->ledger_id;
                    throw new QueryException('mysql', 'fault after approval ledger writes', [],
                        new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213));
                }
            });
        $review = app(ShiftReportManagerReviewService::class)->approveCurrent(
            $cashierShift, $manager, 2, 1, 'Approval committed on retry', (string) \Illuminate\Support\Str::uuid()
        );
        $this->assertSame(2, $postingAttempts);
        $this->assertFalse(ShiftLiabilityPosting::whereKey($rolledBackPostingId)->exists());
        $this->assertFalse(CashierCustodyTransaction::whereKey($rolledBackLedgerId)->exists());
        $this->assertSame('approved', $review->status);
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $cashierShift->id)->count());
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());
        $this->assertSame(1000, $this->ledgerNetHalalas($cashierShift, $cashier));
        $this->assertSame(2, $this->revisionCount($cashierShift));
        $this->assertSame(2, ShiftReportCorrection::where('operation_id', $operationId)->count());
        $this->assertSame(2, ShiftReportCorrectionNotification::where('report_revision_id', $rev2->id)->count());
        $this->assertSame(2, DB::table('notifications')->whereIn('id', $outbox->pluck('id'))->count());
    }

    public function test_public_accountant_command_retries_deadlock_and_replays_response(): void
    {
        [$branch, $manager, $accountant, $day, $shift, $cashier] = $this->fixture();
        $snapshots = new class extends ShiftReportRevisionSnapshotService
        {
            public int $attempts = 0;

            public function createSnapshotIfMissing(ShiftReportRevision $revision, CashierShift|BranchManagerShift $shift): ?\Modules\Shift\Models\ShiftReportRevisionSnapshot
            {
                $snapshot = parent::createSnapshotIfMissing($revision, $shift);
                if ($revision->revision_number === 2 && ++$this->attempts === 1) {
                    throw new QueryException('mysql', 'public correction retry fault', [],
                        new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213));
                }

                return $snapshot;
            }
        };
        app()->instance(ShiftReportRevisionSnapshotService::class, $snapshots);
        $key = (string) \Illuminate\Support\Str::uuid();
        $url = '/api/v1/accountant/shifts/'.$shift->id.'/corrections';
        $payload = ['expected_revision' => 1, 'reason' => 'Public correction retry audit',
            'changes' => ['card_payments' => '10.00'],
            'shortage_allocations' => [['type' => 'cashier', 'id' => $cashier->id, 'amount' => 1000]]];
        $first = $this->actingAs($accountant, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson($url, $payload)->assertOk();
        $this->assertSame(2, $snapshots->attempts);
        $retry = $this->postJson($url, $payload)->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(2, $snapshots->attempts, 'Replay must not rerun the correction.');
        $this->assertSame(2, $this->revisionCount($shift));
        $outbox = ShiftReportCorrectionNotification::where('report_revision_id', $this->currentRevision($shift)->id)->get();
        $this->assertCount(2, $outbox);
        $this->assertSame(2, DB::table('notifications')->whereIn('id', $outbox->pluck('id'))->count());
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $shift->id)->count());
    }

    public function test_correction_vs_daily_submitted_boundary_rejects_correction(): void
    {
        [$branch, $manager, $accountant, $day, $cashierShift] = $this->fixture();

        // Mark workday as completed so it is eligible for daily close submission
        $day->update(['status' => 'completed']);
        $handover = \Modules\Shift\Models\CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $manager->id,
            'handover_amount' => '80.00',
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        [$first, $second] = $this->race(
            [
                'command' => 'submit_boundary',
                'day_id' => $day->id,
            ],
            [
                'command' => 'accountant_correct',
                'shift_id' => $cashierShift->id,
                'accountant_id' => $accountant->id,
                'payload' => ['card_payments' => '30.00'],
                'expected_revision' => 1,
            ]
        );

        $this->assertSame('submitted', $first['status']);
        $this->assertSame(200, $first['http_status']);
        $this->assertTrue((bool) $day->fresh()->daily_report_submitted);
        $this->assertFalse((bool) $day->fresh()->can_reopen);
        $this->assertNotNull($handover->fresh()->daily_closed_at);
        $this->assertSame(1, \Modules\Admin\Models\Operation::where('module_key', 'sales')->where('payload->managerShiftId', $day->id)->count());
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('REPORT_REOPEN_REQUIRED', $second['code']);

        // Assert report remained unchanged: revision 1, card_payments 20.00, zero corrections
        $this->assertSame(1, $this->revisionCount($cashierShift));
        $this->assertSame('20.00', (string) $cashierShift->fresh()->card_payments);
        $this->assertSame(0, ShiftReportCorrection::where('report_aggregate_id', $this->aggregateFor($cashierShift)->id)->count());
    }

    public function test_bulk_correction_canonical_lock_order_prevents_deadlock(): void
    {
        [$branch, $manager, $accountant, $day, $shiftA, $cashierA] = $this->fixture();

        // Create second cashier shift on same day
        $template = Shift::where('branch_id', $branch->id)->first();
        $cashierB = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shiftB = CashierShift::factory()->create([
            'cashier_id' => $cashierB->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => 'completed',
            'actual_end_time' => now(),
            'total_sales' => 100.00,
            'cash_collected' => 80.00,
            'card_payments' => 20.00,
            'net_sales' => 86.96,
            'vat_amount' => 13.04,
        ]);

        DB::transaction(function () use ($shiftB) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($shiftB, 'cashier', $shiftB->cashier_id, 0);
            app(ShiftCashCountService::class)->record($shiftB, $revision, 10000, 2000, 0, 8000);
        });

        // Worker 1 submits in order [B, A]
        // Worker 2 submits in order [A, B]
        [$first, $second] = $this->race(
            [
                'command' => 'bulk_correct',
                'day_id' => $day->id,
                'manager_id' => $manager->id,
                'shifts' => [
                    [
                        'cashier_shift_id' => $shiftB->id,
                        'card_payments' => '25.00',
                        'expected_revision' => 1,
                        'reason' => 'Bulk audit B',
                    ],
                    [
                        'cashier_shift_id' => $shiftA->id,
                        'card_payments' => '25.00',
                        'expected_revision' => 1,
                        'reason' => 'Bulk audit A',
                    ],
                ],
            ],
            [
                'command' => 'bulk_correct',
                'day_id' => $day->id,
                'manager_id' => $manager->id,
                'shifts' => [
                    [
                        'cashier_shift_id' => $shiftA->id,
                        'card_payments' => '35.00',
                        'expected_revision' => 1,
                        'reason' => 'Bulk audit A-2',
                    ],
                    [
                        'cashier_shift_id' => $shiftB->id,
                        'card_payments' => '35.00',
                        'expected_revision' => 1,
                        'reason' => 'Bulk audit B-2',
                    ],
                ],
            ]
        );

        // One must succeed, the other conflicts on stale revision without deadlock
        $this->assertSame('bulk_completed', $first['status']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('STALE_REPORT_REVISION', $second['code']);
    }

    private function ledgerNetHalalas(CashierShift $shift, Cashier $cashier): int
    {
        return CashierCustodyTransaction::where('related_shift_id', $shift->id)
            ->where('cashier_id', $cashier->id)->get()
            ->sum(fn ($row) => ($row->is_cash_in ? -1 : 1) * ShiftFinancialCalculator::storedSarToHalalas($row->amount));
    }

    private function revisionCount(CashierShift $shift): int
    {
        $aggregate = $this->aggregateFor($shift);

        return $aggregate ? ShiftReportRevision::where('report_aggregate_id', $aggregate->id)->count() : 0;
    }

    private function currentRevision(CashierShift $shift): ?ShiftReportRevision
    {
        $aggregate = $this->aggregateFor($shift);

        return $aggregate ? ShiftReportRevision::where('report_aggregate_id', $aggregate->id)
            ->where('revision_number', $aggregate->current_revision_number)->first() : null;
    }

    private function aggregateFor(CashierShift $shift): ?ShiftReportAggregate
    {
        return ShiftReportAggregate::where('source_type', 'cashier_shift')
            ->where('source_id', $shift->id)->first();
    }

    private function race(array $firstInput, array $secondInput): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 's111_p567_race_');
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

        return [
            json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR),
            json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function worker(array $input): Process
    {
        $php = 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe';
        if (! is_file($php)) {
            $php = PHP_BINARY;
        }
        $worker = new Process([$php, base_path('tests/Support/ShiftPhases567ConcurrencyWorker.php'), json_encode($input, JSON_THROW_ON_ERROR)], base_path());
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

    private function fixture(int $countedHalalas = 8000): array
    {
        $company = AsabCompany::create([
            'name' => 'MySQL Test Company '.uniqid(),
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'MySQL Test Brand '.uniqid(),
            'abbr' => 'MTB',
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
            'email' => 'accountant_'.uniqid().'@example.com',
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
            'status' => 'in_progress',
            'daily_report_submitted' => false,
        ]);

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

        DB::transaction(function () use ($cashierShift, $cashier, $countedHalalas) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($cashierShift, 'cashier', $cashier->id, 0);
            app(ShiftCashCountService::class)->record($cashierShift, $revision, 10000, 2000, 0, $countedHalalas);
        });

        return [$branch, $manager, $accountant, $day, $cashierShift, $cashier];
    }
}
