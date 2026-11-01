<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Admin\Events\AsabRealtimeEvent;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Liability\LiabilityEvidenceSource;
use Modules\Shift\Liability\ShiftLiabilityService;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportReviewService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * F1 / C1-C12. Real commits, not RefreshDatabase's enclosing transaction.
 * These SQLite cases do not establish MariaDB concurrency or browser coverage.
 * The production change that T1 detects is passing a physical-evidence UUID
 * (cast to int) to reconcile's aggregate-revision optimistic concurrency fence.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ShiftHeadFinalShortageHandoverTest extends TestCase
{
    use DatabaseTruncation;

    private array $emissions = [];

    private array $closedCalls = [];

    public function createApplication()
    {
        $app = parent::createApplication();
        $name = $app['config']->get('database.default');
        $connection = $app['db']->connection($name);
        if ($app->environment() !== 'testing' || $name !== 'sqlite'
            || $connection->getConfig('database') !== ':memory:'
            || $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \RuntimeException('F1 requires process-local SQLite :memory: before database setup.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel(), 'afterCommit must actually run.');
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        config(['broadcasting.default' => 'null', 'queue.default' => 'sync']);
        $realRealtime = app(\Modules\Admin\Services\RealtimeBroadcaster::class);
        $targetedSpy = \Mockery::mock(\Modules\Admin\Services\RealtimeBroadcaster::class)->makePartial();
        $targetedSpy->shouldReceive('shiftChanged')->andReturnUsing(function (AdminShift $shift, string $action) use ($realRealtime): void {
            if ($action === 'closed') {
                $this->closedCalls[] = ['shift_id' => $shift->id, 'action' => $action,
                    'transaction_level' => DB::transactionLevel()];
            }
            // Observe the real method; do not fake dispatch or disable listeners.
            $realRealtime->shiftChanged($shift, $action);
        });
        $this->app->instance(\Modules\Admin\Services\RealtimeBroadcaster::class, $targetedSpy);
        Event::listen(AsabRealtimeEvent::class, function (AsabRealtimeEvent $event): void {
            $this->emissions[] = ['kind' => $event->eventName, 'payload' => $event->payload,
                'transaction_level' => DB::transactionLevel()];
        });
        Event::listen(OperationFinalApproved::class, function (): void {
            $this->emissions[] = ['kind' => 'OperationFinalApproved', 'transaction_level' => DB::transactionLevel()];
        });
    }

    /** T1: UUID-producing cashier end, physical presentation/receipt, BM posting, day submit, real approvals. */
    public function test_t1_real_handover_shortage_head_final_approval(): void
    {
        $f = $this->fixture(handover: true);
        $this->managerApprove($f);
        $this->submitDay($f);
        $this->accountantApprove($f);
        $allocation = $this->allocation($f);
        $evidence = app(LiabilityEvidenceSource::class)->report($f['source']->id);
        $this->assertTrue(Str::isUuid($allocation->report_revision));
        $this->assertSame($evidence->revision, $allocation->report_revision);
        $this->assertNotNull($allocation->manager_approved_at);
        $this->assertNotNull($allocation->cashier_confirmed_at);
        $this->assertTrue((bool) $f['day']->fresh()->daily_report_submitted);
        $this->assertSame(1, DB::table('shift_liability_daily_locks')->where('cashier_shift_id', $f['source']->id)
            ->whereNull('released_at')->whereNull('superseded_at')->count());
        $this->record('T1.fixture_preparation_events', ['events' => $this->emissions,
            'reservation' => 'RT-OPCREATE-PRECOMMIT-DISPATCH: OUT_OF_SCOPE']);
        $this->emissions = [];
        $this->closedCalls = [];
        $this->assertSame(0, DB::transactionLevel());
        $before = $this->checkpoint($f, 'T1.before_head');
        $this->assertLiability($f, 5000, 1);
        DB::beginTransaction();
        try {
            $response = $this->finalRequest($f, (string) Str::uuid());
            $this->record('T1.head_response', ['status' => $response->status(), 'body' => $response->json(),
                'allocation_report_revision' => $allocation->report_revision,
                'current_revision_number' => $this->revision($f), 'evidence_uuid' => $evidence->revision]);
            $this->assertNotSame('LIABILITY_LOCKED_BY_DAILY_SUBMIT', $this->code($response), 'C9: readiness must not allocate.');
            $response->assertOk(); // RED must fail here with STALE_REPORT_REVISION, not a fixture failure.
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame([], $this->closedCalls, 'No shiftChanged(closed) invocation before outer commit.');
            $this->assertSame([], $this->emissions, 'No Head event or notification before outer commit.');
            $this->record('T1.before_real_commit', ['closed_calls' => $this->closedCalls,
                'events' => $this->emissions, 'transaction_level' => DB::transactionLevel()]);
            DB::commit(); // Positive control: this transaction is really committed, never a test rollback.
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([['shift_id' => $f['admin']->id, 'action' => 'closed', 'transaction_level' => 0]], $this->closedCalls);
        $this->record('T1.after_real_commit', ['closed_calls' => $this->closedCalls, 'events' => $this->emissions]);
        $after = $this->checkpoint($f, 'T1.after_head');
        $this->assertSame($before['financial'], $after['financial']);
        $this->assertSame('final-approved', $f['op']->fresh()->status);
        $this->assertSame('closed', $f['admin']->fresh()->status);
        $this->assertSame(1, $f['op']->steps()->where('stage_id', 'final')->count());
        $this->assertSame('100.00', $f['source']->fresh()->total_sales);
        $this->assertTrue(collect($this->emissions)->contains(fn ($e) => $e['kind'] === 'OperationFinalApproved'));
        $this->assertTrue(collect($this->emissions)->contains(fn ($e) => $e['kind'] === 'shift.closed'));
        foreach ($this->emissions as $event) {
            $this->assertSame(0, $event['transaction_level'], 'No external emission before outer commit.');
        }
    }

    /** Registered Head callbacks must be discarded by a real outer rollback. */
    public function test_head_closed_callback_does_not_run_before_commit_or_after_rollback(): void
    {
        $f = $this->ready(handover: false);
        $this->record('rollback.fixture_preparation_events', ['events' => $this->emissions]);
        $this->emissions = [];
        $this->closedCalls = [];
        $this->assertSame(0, DB::transactionLevel());
        $before = $this->checkpoint($f, 'rollback.before_head');
        DB::beginTransaction();
        try {
            $this->finalRequest($f, (string) Str::uuid())->assertOk();
            $this->assertSame('final-approved', $f['op']->fresh()->status);
            $this->assertSame('closed', $f['admin']->fresh()->status);
            $this->assertSame([], $this->closedCalls);
            $this->assertSame([], $this->emissions);
            $this->record('rollback.before_outer_rollback', ['transaction_level' => DB::transactionLevel(),
                'closed_calls' => $this->closedCalls, 'events' => $this->emissions]);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->checkpoint($f, 'rollback.after_outer_rollback'));
        $this->assertSame([], $this->closedCalls);
        $this->assertSame([], $this->emissions);
        // A later successful unrelated commit must not resurrect discarded callbacks.
        DB::transaction(fn () => DB::select('SELECT 1'));
        $this->assertSame([], $this->closedCalls);
        $this->assertSame([], $this->emissions);
        $this->record('rollback.after_unrelated_commit', ['closed_calls' => $this->closedCalls, 'events' => $this->emissions]);
    }

    /** T2a/b/c: existing endpoint/middleware semantics; no contract changes. */
    public function test_t2_same_key_new_key_and_payload_mismatch_are_financially_inert(): void
    {
        $f = $this->ready();
        $key = (string) Str::uuid();
        $first = $this->finalRequest($f, $key)->assertOk();
        $before = $this->checkpoint($f, 'T2.after_first');
        $events = $this->emissions;
        $replay = $this->finalRequest($f, $key);
        $replay->assertOk(); // No clock travel: snapshot TTL cannot have expired.
        $this->assertSame($first->getContent(), $replay->getContent(), 'T2a byte-identical response replay.');
        $this->assertSame($before, $this->checkpoint($f, 'T2a.replay'));
        $new = $this->finalRequest($f, (string) Str::uuid());
        $new->assertStatus(409)->assertJsonPath('error.code', 'OP_NOT_APPROVED');
        $this->assertSame($before, $this->checkpoint($f, 'T2b.new_key'));
        $changed = $this->finalRequest($f, $key, ['isConditional' => true, 'conditionalNote' => 'Different intent']);
        $changed->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame($before, $this->checkpoint($f, 'T2c.changed_payload'));
        $this->assertSame($events, $this->emissions);
        $this->record('T2.responses', ['replay' => $replay->status(), 'new_key' => $new->json(), 'changed_payload' => $changed->json()]);
    }

    /** T3: numeric report_revision is written by the actual accountant correction service. */
    public function test_t3_numeric_correction_writer_remains_compatible(): void
    {
        $f = $this->fixture(handover: false);
        $this->managerApprove($f);
        $revision = app(AccountantReportCorrectionService::class)->correct($f['source'], $f['accountant'],
            ['counted_cash' => '80.00'], [['type' => 'cashier', 'id' => $f['cashier']->id, 'amount' => 2000]],
            $this->revision($f), $this->allocation($f)->version, 'Correct count transcription; immutable gross', (string) Str::uuid());
        $this->assertSame((string) $revision->revision_number, $this->allocation($f)->report_revision);
        $this->assertSame('accountant', $this->allocation($f)->created_by_type);
        $this->assertSame('100.00', $f['source']->fresh()->total_sales);
        $this->submitDay($f);
        $this->accountantApprove($f);
        $before = $this->checkpoint($f, 'T3.before_head');
        $this->assertLiability($f, 2000, 2);
        $this->finalRequest($f, (string) Str::uuid())->assertOk();
        $this->assertSame($before['financial'], $this->checkpoint($f, 'T3.after_head')['financial']);
    }

    /** T4a: reachable pre-accountant correction. Probe the real final financial gate, not fabricated approved status. */
    public function test_t4a_pre_accountant_correction_replaces_approval_and_refuses_financial_gate(): void
    {
        $f = $this->fixture(handover: false);
        $this->managerApprove($f);
        $old = $this->allocation($f);
        app(ShiftReportCorrectionService::class)->correctCashierReport($f['source'], $f['manager'],
            ['card_payments' => '10.00', 'cash_collected' => '90.00'], $this->revision($f),
            'Verified channel correction before accountant approval', (string) Str::uuid(),
            [['type' => 'cashier', 'id' => $f['cashier']->id, 'amount' => 4000]]);
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame('pending', $this->allocation($f)->manager_approval_status);
        $before = $this->checkpoint($f, 'T4a.before_gate');
        $this->refused('BRANCH_LIABILITY_APPROVAL_PENDING', fn () => DB::transaction(
            fn () => app(ShiftReportReviewService::class)->approvalSnapshot($f['op']->fresh(), final: true)));
        $this->assertSame($before, $this->checkpoint($f, 'T4a.after_gate'));
        // At the HTTP endpoint the operation is still pending, so the state fence correctly wins first.
        $this->finalRequest($f, (string) Str::uuid())->assertStatus(409)->assertJsonPath('error.code', 'OP_NOT_APPROVED');
    }

    /** T4b: DEFENSIVE_DB_STATE, not a claimed API lifecycle. Only evidence token is corrupted. */
    public function test_t4b_retained_active_approved_stale_evidence_cannot_post(): void
    {
        $f = $this->ready();
        $allocation = $this->allocation($f);
        $allocation->update(['report_revision' => (string) Str::uuid()]);
        $this->assertSame('approved', $allocation->fresh()->manager_approval_status);
        $this->assertNull($allocation->fresh()->superseded_at);
        $before = $this->checkpoint($f, 'T4b.stale_before');
        $response = $this->finalRequest($f, (string) Str::uuid())->assertStatus(409);
        $this->assertSame('STALE_OR_MISSING_LIABILITY_ALLOCATION', $this->code($response));
        $this->assertSame($before, $this->checkpoint($f, 'T4b.stale_after'));
    }

    /** T5: same genuine UUID writer, no transfer and no fabricated opening receipt. */
    public function test_t5_valid_shortage_without_handover_is_exactly_once(): void
    {
        $f = $this->ready(handover: false);
        $this->assertSame(0, CashierShiftHandover::where('cashier_shift_id', $f['source']->id)->count());
        $this->assertSame(0, app(ShiftCashCountService::class)->confirmedOpeningHalalas($f['source']->id));
        $before = $this->checkpoint($f, 'T5.before');
        $this->refused('REPORT_IN_HEAD_REVIEW', fn () => app(ShiftReportCorrectionService::class)
            ->correctCashierReport($f['source'], $f['manager'], ['handover_notes' => 'Late correction'],
                $this->revision($f), 'Must not edit approved report', (string) Str::uuid()));
        $this->finalRequest($f, (string) Str::uuid())->assertOk();
        $this->assertLiability($f, 5000, 1);
        $this->assertSame($before['financial'], $this->checkpoint($f, 'T5.after')['financial']);
    }

    /** T6: domain manager approval deliberately has not called controller reconciliation; no deleted ledger rows. */
    public function test_t6_real_posting_then_test_local_failure_rolls_back_outer_final_transaction(): void
    {
        $f = $this->fixture(handover: false);
        app(ShiftLiabilityService::class)->approve($f['source']->id, $f['manager'], $this->allocation($f)->version);
        $this->assertLiability($f, 0, 0);
        $this->submitDay($f);
        $this->accountantApprove($f);
        $closedBefore = $this->closedCalls;
        $before = $this->checkpoint($f, 'T6.before');
        $emissions = $this->emissions;
        $real = app(ShortagePostingService::class);
        $injected = false;
        $proxy = \Mockery::mock(ShortagePostingService::class)->makePartial();
        $proxy->shouldReceive('reconcile')->once()->andReturnUsing(function (...$args) use ($real, $f, &$injected): never {
            $result = $real->reconcile(...$args);
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame(1, $result['total_deltas']); // Posting row count; halalas are asserted below from delta_halalas.
            $this->assertLiability($f, 5000, 1); // Actual nonzero posting/custody write exists inside outer transaction.
            $this->checkpoint($f, 'T6.inside_before_failure');
            $injected = true;
            throw new \RuntimeException('F1_TEST_ONLY_BEFORE_OUTER_COMMIT');
        });
        $this->app->instance(ShortagePostingService::class, $proxy);
        try {
            app(OperationService::class)->finalApprove($f['op']->fresh(), $f['head']);
            $this->fail('Failure injection did not run.');
        } catch (\RuntimeException $error) {
            $this->assertSame('F1_TEST_ONLY_BEFORE_OUTER_COMMIT', $error->getMessage());
        } finally {
            $this->app->instance(ShortagePostingService::class, $real);
        }
        $this->assertTrue($injected);
        $this->assertSame($before, $this->checkpoint($f, 'T6.after_rollback'));
        $this->assertSame($emissions, $this->emissions);
        $this->assertSame($closedBefore, $this->closedCalls);
        $this->assertSame('approved', $f['op']->fresh()->status);
        $this->assertSame('pending_review', $f['admin']->fresh()->status);
        $this->assertSame(0, $f['op']->steps()->where('stage_id', 'final')->count());
        // Successful control: same real service must commit and emit, rather than suppressing afterCommit globally.
        $this->finalRequest($f, (string) Str::uuid())->assertOk();
        $this->assertLiability($f, 5000, 1);
        $this->assertGreaterThan(count($emissions), count($this->emissions));
    }

    /** T7: physical return really sets recount flag, without injecting the flag in SQL. */
    public function test_t7_real_physical_return_and_defensive_head_http_refuse_without_mutation(): void
    {
        // No Admin mirror yet: pending_review itself forbids physical returns; record this reachability boundary.
        $f = $this->fixture(handover: false, end: false, mirror: false);
        $sender = Cashier::factory()->create(['branch_id' => $f['branch']->id, 'created_by' => $f['manager']->id]);
        $source = CashierShift::factory()->inProgress()->create(['cashier_id' => $sender->id,
            'shift_id' => $f['template']->id, 'shift_date' => today()->subDay()]);
        $this->command($sender, "/api/v1/cashier/shifts/{$source->id}/end-with-handover", [
            'total_sales' => '50.00', 'cash_collected' => '50.00', 'counted_cash' => '50.00',
            'next_cashier_id' => $f['cashier']->id, 'handover_amount' => '50.00',
        ])->assertOk();
        $handover = CashierShiftHandover::where('cashier_shift_id', $source->id)->sole();
        $attempt = $this->command($sender, "/api/shift-transfers/handover/{$handover->id}/present", [
            'presented_halalas' => 5000, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json('data.id');
        $this->command($f['cashier'], "/api/shift-transfer-attempts/{$attempt}/reject", [
            'physical_halalas' => 4000, 'reason' => 'Physical cash short', 'correction_reason' => 'actual_shortage',
        ])->assertOk();
        // The return primitive is tested on an unmirrored receiver, as in the repository's physical-return suite.
        $this->command($f['cashier'], "/api/v1/cashier/shifts/{$f['source']->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '90.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $f['cashier']->id, 'amount' => '50.00']],
        ])->assertOk();
        $this->managerApprove($f);
        $oldCount = app(ShiftCashCountService::class)->currentFor($f['source']->id);
        $return = $this->command($f['cashier'], "/api/shift-transfer-attempts/{$attempt}/returns", [
            'returned_halalas' => 4000, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Return physical cash',
        ])->assertOk()->json('data.id');
        $this->command($sender, "/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->assertTrue((bool) DB::table('shift_report_aggregates')->where('source_id', $f['source']->id)->value('fresh_count_required'));
        $this->assertSame(1, DB::table('cashier_shift_history')->where('cashier_shift_id', $f['source']->id)
            ->where('action', 'physical_return_requires_recount')->count());
        $this->assertNull(app(ShiftCashCountService::class)->currentFor($f['source']->id));

        // DEFENSIVE_DB_STATE: the actual return above has invalidated the count.
        // The ordinary API cannot approve/submit this report before recounting,
        // and forbids this return once the Admin mirror is pending_review.
        // Construct only stale review/membership metadata, never a recount flag,
        // allocation, financial posting, or fictitious successful API approval.
        // This exercises the real authenticated Head endpoint against corrupt
        // persisted review state, not a claimed normal end-to-end lifecycle.
        $f['admin'] = AdminShift::create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
            'legacy_shift_id' => $f['source']->id, 'cashier_name' => 'Synthetic returned cashier',
            'status' => 'pending_review', 'sales_amount' => $oldCount->gross_halalas,
            'cash_actual' => $oldCount->counted_halalas, 'cash_expected' => $oldCount->expected_halalas,
            'variance' => $oldCount->variance_halalas, 'cash_count_state' => 'counted', 'shift_type' => 'defensive']);
        $f['day']->refresh()->update(['status' => 'completed', 'daily_report_submitted' => true,
            'daily_report_submitted_at' => now()]);
        \Modules\Shift\Models\ShiftLiabilityDailyLock::create(['cashier_shift_id' => $f['source']->id,
            'branch_manager_shift_id' => $f['day']->id, 'report_revision' => $oldCount->evidence_revision_id,
            'allocation_version' => $this->allocation($f)->version, 'locked_by_id' => $f['manager']->id, 'locked_at' => now()]);
        $f['op'] = Operation::create(['public_id' => 'SHF-'.strtoupper(Str::random(10)),
            'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'module_key' => 'shifts',
            'source_id' => $f['admin']->id, 'origin' => 'mobile', 'status' => 'approved',
            'approved_by_id' => $f['accountant']->id, 'approved_at' => now(), 'operation_date' => now(),
            'payload' => ['shiftId' => $f['admin']->id, 'legacyShiftId' => $f['source']->id,
                'salesHalalas' => $oldCount->gross_halalas, 'cardTotalHalalas' => $oldCount->cards_halalas,
                'aggregatorTotalsHalalas' => $oldCount->apps_halalas, 'cashActualHalalas' => $oldCount->counted_halalas,
                'cashExpectedHalalas' => $oldCount->expected_halalas, 'varianceHalalas' => $oldCount->variance_halalas,
                'pkg6Review' => ['phase' => 'head_review']]]);
        $before = $this->checkpoint($f, 'T7.defensive_before_http');
        $response = $this->finalRequest($f, (string) Str::uuid());
        $this->record('T7.head_http_response', ['status' => $response->status(), 'body' => $response->json()]);
        $response->assertStatus(409);
        $this->assertSame('PHYSICAL_RECOUNT_REQUIRED', $this->code($response));
        $this->assertSame($before, $this->checkpoint($f, 'T7.defensive_after_http'));
        $this->assertSame(0, $f['op']->steps()->where('stage_id', 'final')->count());
        $this->assertSame('approved', $f['op']->fresh()->status);
        $this->assertSame('pending_review', $f['admin']->fresh()->status);
        $this->record('T7.boundary', ['physical_return' => 'REAL_HTTP_LIFECYCLE',
            'head_review_metadata' => 'DEFENSIVE_DB_STATE, not reachable through normal approval APIs',
            'head_http' => ['status' => $response->status(), 'body' => $response->json()]]);
    }

    /** C10: DEFENSIVE_DB_STATE. Negative Admin projection cannot resurrect an old target with nonnegative evidence. */
    public function test_c10_projection_evidence_divergence_is_refused_without_posting(): void
    {
        $f = $this->ready();
        // Corrupt physical evidence only; keep negative Admin/payload projection and active approved old allocation.
        $count = app(ShiftCashCountService::class)->currentFor($f['source']->id);
        // Deliberately corrupt synthetic DB state only; the real model correctly forbids editing evidence.
        // This is a defensive HTTP-gate test, never a supported report-edit API path.
        DB::table('shift_report_cash_counts')->where('id', $count->id)
            ->update(['counted_halalas' => 10000, 'variance_halalas' => 0]);
        $before = $this->checkpoint($f, 'C10.before');
        $response = $this->finalRequest($f, (string) Str::uuid())->assertStatus(409);
        $this->assertSame('STALE_SHF_REPORT_SNAPSHOT', $this->code($response));
        $this->assertSame($before, $this->checkpoint($f, 'C10.after'));
    }

    private function fixture(bool $handover, bool $end = true, bool $mirror = true): array
    {
        $company = AsabCompany::create(['name' => 'F1 synthetic', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'F1', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id, 'status' => 'active']);
        $users = [];
        foreach (['accountant', 'head'] as $role) {
            $user = AsabUser::create(['company_id' => $company->id, 'name' => 'F1 '.$role,
                'email' => Str::uuid().'@example.invalid', 'password' => 'synthetic-test-only', 'status' => 'active']);
            AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all']);
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role_key' => $role,
                'status' => 'active',
            ]);
            $users[$role] = $user;
        }
        if ($mirror) {
            Employee::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'F1 cashier',
                'emp_number' => 'F1-001', 'role' => 'cashier', 'status' => 'active'])
                ->forceFill(['legacy_cashier_id' => $cashier->id])->save();
        }
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::create(['cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today(), 'status' => 'not_started', 'opening_balance' => '0.00']);
        app(CashierShiftStartService::class)->startShift($source);
        $day = BranchManagerShift::where('branch_id', $branch->id)->whereDate('shift_date', today())->sole();
        $f = compact('company', 'branch', 'manager', 'cashier', 'template', 'source', 'day') + $users;
        if (! $end) {
            return $f;
        }
        $body = ['total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00', 'counted_cash' => '50.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $cashier->id, 'amount' => '50.00']]];
        if ($handover) {
            $body += ['handover_to_type' => 'branch_manager', 'handover_to_id' => $manager->id, 'handover_amount' => '50.00'];
        }
        $this->command($cashier, '/api/v1/cashier/shifts/'.$source->id.($handover ? '/end-with-handover' : '/end'), $body)->assertOk();
        if ($handover) {
            $request = CashierShiftHandover::where('cashier_shift_id', $source->id)->sole();
            $attempt = $this->command($cashier, "/api/shift-transfers/handover/{$request->id}/present", [
                'presented_halalas' => 5000, 'idempotency_key' => (string) Str::uuid(),
            ])->assertOk()->json('data.id');
            $this->command($manager, "/api/shift-transfer-attempts/{$attempt}/confirm-receipt", ['confirmed_halalas' => 5000])->assertOk();
            $this->assertSame(1, DB::table('cashier_shift_handover_receipts')->where('cashier_shift_handover_id', $request->id)->count());
        }
        $f['admin'] = AdminShift::where('legacy_shift_id', $source->id)->where('role', 'cashier')->sole();
        $f['op'] = Operation::where('module_key', 'shifts')->where('payload->legacyShiftId', $source->id)->sole();
        $this->assertTrue(Str::isUuid($this->allocation($f)->report_revision));
        $this->assertSame(-5000, $this->allocation($f)->variance_halalas);
        $this->record('fixture.ready', ['source' => $source->id, 'operation' => $f['op']->id,
            'revision' => $this->revision($f), 'allocation' => $this->allocation($f)->only(['id', 'version', 'report_revision', 'manager_approval_status'])]);

        return $f;
    }

    private function managerApprove(array $f): void
    {
        $this->command($f['manager'], "/api/v1/branch-manager/shifts/{$f['source']->id}/liability/approve", [
            'version' => $this->allocation($f)->version, 'expected_revision' => $this->revision($f),
        ])->assertOk();
        $this->assertSame('approved', $this->allocation($f)->manager_approval_status);
    }

    private function submitDay(array $f): void
    {
        // Manager workday completion is a synthetic prerequisite, not a forged allocation or approval.
        $f['day']->refresh()->update(['status' => 'completed']);
        $revision = DB::transaction(fn () => app(ShiftReportRevisionService::class)
            ->recordManagerRevision($f['day'], 'branch_manager', $f['manager']->id));
        $this->command($f['manager'], '/api/v1/branch-manager/workday/daily-close/submit', [
            'branch_manager_shift_id' => $f['day']->id, 'expected_revision' => $revision->revision_number,
        ])->assertOk();
    }

    private function accountantApprove(array $f): void
    {
        $this->command($f['accountant'], '/api/v1/operations/'.$f['op']->id.'/approve')->assertOk();
        $this->assertSame('approved', $f['op']->fresh()->status);
    }

    private function ready(bool $handover = true): array
    {
        $f = $this->fixture($handover);
        $this->managerApprove($f);
        $this->submitDay($f);
        $this->accountantApprove($f);

        return $f;
    }

    private function command($actor, string $url, array $body = [])
    {
        return $this->actingAs($actor, 'sanctum')->postJson($url, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function finalRequest(array $f, string $key, array $body = [])
    {
        return $this->actingAs($f['head'], 'sanctum')->postJson('/api/v1/operations/'.$f['op']->id.'/final-approve', $body,
            ['Idempotency-Key' => $key]);
    }

    private function allocation(array $f): ShiftLiabilityAllocation
    {
        return ShiftLiabilityAllocation::where('cashier_shift_id', $f['source']->id)->whereNull('superseded_at')->sole();
    }

    private function revision(array $f): int
    {
        return app(ShiftReportRevisionService::class)->currentCashierRevision($f['source']->fresh())->revision_number;
    }

    private function code($response): ?string
    {
        // ConflictHttpException uses Laravel's message-only envelope; AsabException uses error.code.
        return $response->json('error.code') ?? $response->json('code') ?? $response->json('message');
    }

    private function refused(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$code);
        } catch (AsabException $exception) {
            $this->assertSame($code, $exception->errorCode);
        } catch (ConflictHttpException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }

    private function financial(): array
    {
        $result = [];
        foreach (['shift_liability_postings', 'shift_liability_posting_states', 'shift_liability_legacy_posting_links',
            'asab_employee_movements', 'personal_ledger_transactions', 'cashier_custody_transactions'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $result[$table] = ['count' => count($rows), 'rows' => $rows];
        }

        return $result;
    }

    private function checkpoint(array $f, string $label): array
    {
        $snapshot = ['financial' => $this->financial(), 'operation' => $f['op']->fresh()->getRawOriginal(),
            'admin_shift' => $f['admin']->fresh()->getRawOriginal(),
            'steps' => $f['op']->steps()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(),
            'source' => $f['source']->fresh()->getRawOriginal(), 'emissions' => $this->emissions];
        foreach (['asab_notifications', 'shift_report_corrections', 'cashier_shift_history'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $this->record($label, $snapshot);

        return $snapshot;
    }

    private function assertLiability(array $f, int $net, int $count): void
    {
        $postings = DB::table('shift_liability_postings')->where('cashier_shift_id', $f['source']->id)->get();
        $this->assertCount($count, $postings);
        $this->assertSame($net, (int) $postings->sum('delta_halalas'));
        foreach ($postings as $posting) {
            $this->assertSame('posted', $posting->status);
            $this->assertSame('cashier_custody_transactions', $posting->ledger_table);
            $row = DB::table($posting->ledger_table)->where('id', $posting->ledger_id)->sole();
            $this->assertSame($posting->id, $row->posting_id);
            $this->assertSame($f['cashier']->id, $row->cashier_id);
            $this->assertSame(abs((int) $posting->delta_halalas), \App\Support\ShiftFinancialCalculator::storedSarToHalalas($row->amount));
        }
    }

    private function record(string $label, array $data): void
    {
        $path = getenv('F1_EVIDENCE_JSONL');
        if ($path !== false && $path !== '') {
            file_put_contents($path, json_encode(['test' => $this->name(), 'label' => $label, 'data' => $data],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
