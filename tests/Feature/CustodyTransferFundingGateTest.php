<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Tests\TestCase;

/**
 * BRC v3.4 F-04 — FUND-01 / FUND-02 on POST /api/custody/transfer (sales cash -> branch expense custody).
 *
 *  FUND-01  the SOURCE DAY must be fully approved: accountant AND THEN head of accounts. The accountant alone
 *           is not enough. (AC-53, AC-54)
 *  FUND-02  the transfer is bounded by the cash the manager actually holds: executed movements are already in
 *           the ledger, open reservations are deducted once, uncollected shortages are never cash. (AC-54..56)
 *
 * "Source day fully approved" is implemented as: the manager workday has active daily-lock members and EVERY
 * member's report operation is `final-approved` (ASSUMPTION flagged in the F-04 note for Mohamed).
 * Money is SAR with two decimals on the wire and in the legacy ledgers; every comparison is in whole halalas.
 */
class CustodyTransferFundingGateTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private AsabUser $head;

    private Cashier $cashier;

    private Employee $mirror;

    private Shift $template;

    private int $templateSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Funding Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'Branch', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);

        $this->accountant = AsabUser::create(['company_id' => $this->company->id, 'name' => 'Accountant', 'email' => 'acc@fund.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $this->head = AsabUser::create(['company_id' => $this->company->id, 'name' => 'Head', 'email' => 'head@fund.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);

        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->template = $this->newTemplate();
        $this->mirror = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => '3001', 'name' => 'Cashier', 'role' => 'cashier', 'status' => 'active']);
        $this->mirror->forceFill(['legacy_cashier_id' => $this->cashier->id])->save();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** A shift template with its own time window: (branch, start, end) is unique. */
    private function newTemplate(): Shift
    {
        $hour = 1 + $this->templateSeq++;

        return Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'start_time' => sprintf('%02d:00:00', $hour),
            'end_time' => sprintf('%02d:30:00', $hour),
        ]);
    }

    private function ops(): OperationService
    {
        return app(OperationService::class);
    }

    private function day(?string $date = null, ?BranchManager $manager = null, ?Branch $branch = null): BranchManagerShift
    {
        return BranchManagerShift::create([
            'branch_manager_id' => ($manager ?? $this->manager)->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'shift_date' => $date ?? today()->toDateString(),
            'status' => 'completed',
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);
    }

    /** A bridged, day-locked cashier report whose operation sits at `pending` (nobody approved it yet). */
    private function memberReport(BranchManagerShift $day): Operation
    {
        // A fresh shift template per report: (cashier, template, date) is unique.
        $template = $this->newTemplate();
        $legacy = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $template->id, 'shift_date' => $day->shift_date,
            'total_sales' => '500.00', 'card_payments' => '0.00', 'cash_collected' => 500.00, 'opening_balance' => '0.00',
        ]));
        $count = DB::transaction(function () use ($legacy) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($legacy, 'cashier', $this->cashier->id);

            return app(ShiftCashCountService::class)->record($legacy, $revision, 50000, 0, 0, 50000);
        });
        $legacy->update(['variance' => '0.00']);

        event(new ShiftEndedEvent($legacy, false));

        $admin = \Modules\Admin\Models\Shift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $admin->id)->firstOrFail();
        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $legacy->id, 'branch_manager_shift_id' => $day->id,
            'report_revision' => $count->evidence_revision_id, 'locked_by_id' => $this->manager->id, 'locked_at' => now(),
        ]);

        return $op;
    }

    private function accountantApprove(Operation $op): Operation
    {
        return $this->ops()->approve($op, $this->accountant);
    }

    private function headFinalApprove(Operation $op): Operation
    {
        return $this->ops()->finalApprove($op->fresh(), $this->head);
    }

    /** A day whose only member report is at the requested stage: 'pending' | 'accountant' | 'final'. */
    private function dayAt(string $stage, ?string $date = null, ?BranchManager $manager = null, ?Branch $branch = null): BranchManagerShift
    {
        $day = $this->day($date, $manager, $branch);
        $op = $this->memberReport($day);
        if ($stage !== 'pending') {
            $op = $this->accountantApprove($op);
        }
        if ($stage === 'final') {
            $this->headFinalApprove($op);
        }

        return $day;
    }

    private function salesCash(BranchManagerShift $day, string $amount, ?BranchManager $manager = null): PersonalLedgerTransaction
    {
        return PersonalLedgerTransaction::create([
            'branch_manager_id' => ($manager ?? $this->manager)->id,
            'transaction_type' => 'Total Sales',
            'amount' => $amount,
            'is_cash_in' => true,
            'related_shift_id' => $day->id,
            'transaction_date' => now(),
        ]);
    }

    /** Manager cash that was already paid out (an executed movement: cash handed to a cashier). */
    private function executedCashOut(string $amount): PersonalLedgerTransaction
    {
        return PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Handover to Cashier',
            'amount' => $amount,
            'is_cash_in' => false,
            'transaction_date' => now(),
        ]);
    }

    private function transfer(array $body, array $headers = [], ?BranchManager $as = null, string $prefix = '/api')
    {
        return $this->withHeaders($headers)->actingAs($as ?? $this->manager, 'sanctum')->postJson("$prefix/custody/transfer", $body);
    }

    private function writes(): array
    {
        return [
            'ledger' => PersonalLedgerTransaction::count(),
            'custody' => CustodyTransaction::count(),
        ];
    }

    /** Sum of the manager's ledger rows in halalas (cash-in minus cash-out). */
    private function ledgerHalalas(?BranchManager $manager = null): int
    {
        $rows = PersonalLedgerTransaction::where('branch_manager_id', ($manager ?? $this->manager)->id)->get();

        return (int) $rows->sum(fn ($r) => ($r->is_cash_in ? 1 : -1) * (int) round(((float) $r->amount) * 100));
    }

    // ── AC-53 / FUND-01 ─────────────────────────────────────────────────────

    public function test_ac53_accountant_approval_alone_does_not_release_the_source_day_cash(): void
    {
        $day = $this->dayAt('accountant');
        $this->salesCash($day, '100.00');
        $before = $this->writes();

        $res = $this->transfer(['transferAmount' => 50])->assertStatus(400);

        $res->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'INVALID_AMOUNT')
            ->assertJsonPath('errors.reason', 'SOURCE_DAY_NOT_FULLY_APPROVED');
        $this->assertEquals(0, $res->json('errors.maxAmount'));
        $this->assertSame($before, $this->writes(), 'a refused transfer writes nothing');
    }

    public function test_ac53_a_day_nobody_approved_yet_cannot_be_transferred_and_full_transfer_is_not_a_zero_posting(): void
    {
        $day = $this->dayAt('pending');
        $this->salesCash($day, '100.00');
        $before = $this->writes();

        $this->transfer(['transferAmount' => 1])->assertStatus(400)->assertJsonPath('errors.reason', 'SOURCE_DAY_NOT_FULLY_APPROVED');
        $this->transfer(['isFullTransfer' => true])->assertStatus(400)->assertJsonPath('errors.code', 'INVALID_AMOUNT');

        $this->assertSame($before, $this->writes(), 'a "full transfer" of nothing must not create zero-amount rows');
    }

    public function test_ac53_sales_cash_without_any_verifiable_source_day_is_not_transferable(): void
    {
        // A cash-in with no source-day link cannot prove FUND-01, so it must not fund the custody.
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id, 'transaction_type' => 'Total Sales', 'amount' => '100.00',
            'is_cash_in' => true, 'transaction_date' => now(),
        ]);
        $before = $this->writes();

        $this->transfer(['transferAmount' => 10])->assertStatus(400)->assertJsonPath('errors.reason', 'SOURCE_DAY_NOT_FULLY_APPROVED');
        $this->assertSame($before, $this->writes());
    }

    public function test_ac53_a_day_with_one_unapproved_member_report_is_not_fully_approved(): void
    {
        $day = $this->day();
        $this->headFinalApprove($this->accountantApprove($this->memberReport($day)));
        $this->accountantApprove($this->memberReport($day)); // second member stops at the accountant
        $this->salesCash($day, '100.00');

        $this->transfer(['transferAmount' => 10])->assertStatus(400)->assertJsonPath('errors.reason', 'SOURCE_DAY_NOT_FULLY_APPROVED');
    }

    // ── AC-54 / FUND-01 + FUND-02 ───────────────────────────────────────────

    public function test_ac54_fully_approved_day_allows_the_transfer_within_available_cash_and_posts_both_sides_once(): void
    {
        $day = $this->dayAt('final');
        $this->salesCash($day, '100.00');

        $res = $this->transfer(['transferAmount' => 60])->assertOk();

        $res->assertJsonPath('success', true);
        $this->assertEquals(40.0, $res->json('data.newPersonalBalance'));
        $out = PersonalLedgerTransaction::where('branch_manager_id', $this->manager->id)->where('transaction_type', 'Transfer to Custody')->sole();
        $this->assertFalse((bool) $out->is_cash_in);
        $this->assertSame('60.00', $out->amount);
        $in = CustodyTransaction::where('branch_manager_id', $this->manager->id)->where('type', 'Cash Transfer')->sole();
        $this->assertTrue((bool) $in->is_cash_in);
        $this->assertSame('60.00', (string) $in->amount);
        $this->assertSame($this->branch->id, $in->branch_id);

        // The remaining 40 is the ceiling; one halala more is refused, exactly 40.00 is accepted.
        $this->transfer(['transferAmount' => 40.01])->assertStatus(400)
            ->assertJsonPath('errors.code', 'INVALID_AMOUNT')->assertJsonPath('errors.reason', 'EXCEEDS_AVAILABLE_CASH');
        $this->transfer(['transferAmount' => 40])->assertOk();
        $this->assertSame(0, $this->ledgerHalalas());
    }

    public function test_ac54_only_final_approved_days_fund_the_transfer_other_days_stay_held_back(): void
    {
        $this->salesCash($this->dayAt('final', today()->subDay()->toDateString()), '100.00');
        $this->salesCash($this->dayAt('accountant'), '50.00');

        $over = $this->transfer(['transferAmount' => 120])->assertStatus(400);
        $this->assertEquals(100.0, $over->json('errors.maxAmount'));
        $this->assertEquals(50.0, $over->json('errors.unapprovedAmount'));

        $this->transfer(['isFullTransfer' => true])->assertOk();
        $this->assertSame(5000, $this->ledgerHalalas(), 'the accountant-only day (50.00) is untouched');
    }

    public function test_ac54_both_api_aliases_apply_the_same_gate(): void
    {
        $this->salesCash($this->dayAt('accountant'), '100.00');

        foreach (['/api', '/api/v1'] as $prefix) {
            $this->transfer(['transferAmount' => 5], [], null, $prefix)->assertStatus(400)
                ->assertJsonPath('errors.reason', 'SOURCE_DAY_NOT_FULLY_APPROVED');
        }
    }

    // ── AC-55 / FUND-02 uncollected shortage ───────────────────────────────

    public function test_ac55_an_uncollected_shortage_claim_never_funds_the_transfer(): void
    {
        $this->salesCash($this->dayAt('final'), '100.00');
        // A shortage the manager has NOT collected is a claim on the cashier, not cash in hand.
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id, 'transaction_type' => 'Variance from Cashier', 'amount' => '50.00',
            'is_cash_in' => true, 'category' => 'cash_variance', 'transaction_date' => now(),
        ]);

        $over = $this->transfer(['transferAmount' => 120])->assertStatus(400);
        $this->assertEquals(100.0, $over->json('errors.maxAmount'), 'the 50.00 claim is excluded');

        $this->transfer(['isFullTransfer' => true])->assertOk();
        $moved = PersonalLedgerTransaction::where('transaction_type', 'Transfer to Custody')->sole();
        $this->assertSame('100.00', $moved->amount, 'full transfer moves the cash (100.00), never cash + claim (150.00)');
    }

    // ── AC-56 / FUND-02 no double deduction ────────────────────────────────

    public function test_ac56_an_executed_movement_is_deducted_once_and_an_open_reservation_once(): void
    {
        $day = $this->dayAt('final');
        $this->salesCash($day, '100.00');
        $this->executedCashOut('30.00'); // already counted in the ledger balance: 70.00 left

        $over = $this->transfer(['transferAmount' => 71])->assertStatus(400);
        $this->assertEquals(70.0, $over->json('errors.maxAmount'), 'the executed 30.00 is deducted once, not twice');

        // An open manager->cashier request reserves 20.00 without moving any cash yet.
        $destination = CashierShift::factory()->create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $this->newTemplate()->id,
            'shift_date' => today(), 'status' => ShiftStatus::NOT_STARTED,
        ]);
        $service = app(ShiftTransferReceiptService::class);
        $pending = $service->requestManagerCashTransfer($day, $this->cashier, $destination, '20.00', $this->manager);

        $over = $this->transfer(['transferAmount' => 51])->assertStatus(400);
        $this->assertEquals(50.0, $over->json('errors.maxAmount'), 'reservation 20.00 + executed 30.00 are each deducted once');

        // Executing the reservation turns it into a ledger movement: the ceiling must NOT drop to 30.00.
        $service->confirmManagerCashTransfer($pending->id, $this->cashier, '20.00');
        $over = $this->transfer(['transferAmount' => 51])->assertStatus(400);
        $this->assertEquals(50.0, $over->json('errors.maxAmount'), 'the reservation is released at execution, never counted again');

        $this->transfer(['transferAmount' => 50])->assertOk();
        $this->assertSame(0, $this->ledgerHalalas());
    }

    // ── idempotency (project-standard middleware) ──────────────────────────

    public function test_retrying_the_same_command_replays_the_first_answer_and_never_transfers_twice(): void
    {
        $this->salesCash($this->dayAt('final'), '100.00');
        $key = (string) Str::uuid();

        $first = $this->transfer(['transferAmount' => 30], ['Idempotency-Key' => $key])->assertOk();
        $afterFirst = $this->writes();
        $replay = $this->transfer(['transferAmount' => 30], ['Idempotency-Key' => $key])->assertOk();

        $this->assertSame($afterFirst, $this->writes(), 'a retry creates no second financial effect');
        $this->assertEquals($first->json('data.newPersonalBalance'), $replay->json('data.newPersonalBalance'));
        $this->assertEquals(1, PersonalLedgerTransaction::where('transaction_type', 'Transfer to Custody')->count());
        $this->assertSame(7000, $this->ledgerHalalas());
    }

    public function test_the_same_key_with_a_different_amount_is_refused_without_effect(): void
    {
        $this->salesCash($this->dayAt('final'), '100.00');
        $key = (string) Str::uuid();

        $this->transfer(['transferAmount' => 30], ['Idempotency-Key' => $key])->assertOk();
        $afterFirst = $this->writes();

        $this->transfer(['transferAmount' => 31], ['Idempotency-Key' => $key])->assertStatus(409);

        $this->assertSame($afterFirst, $this->writes());
        $this->assertSame(7000, $this->ledgerHalalas());
    }

    public function test_without_a_key_the_legacy_clients_keep_working(): void
    {
        $this->salesCash($this->dayAt('final'), '100.00');

        $this->transfer(['transferAmount' => 10])->assertOk();
        $this->transfer(['transferAmount' => 10])->assertOk();

        $this->assertSame(8000, $this->ledgerHalalas());
    }

    // ── tenant / actor isolation, input hygiene ────────────────────────────

    public function test_another_managers_approved_cash_cannot_fund_this_managers_transfer(): void
    {
        $otherBranch = Branch::factory()->create(['asab_brand_id' => $this->branch->asab_brand_id, 'asab_company_id' => $this->company->id]);
        $other = BranchManager::factory()->create(['branch_id' => $otherBranch->id, 'is_active' => true, 'status' => 'active']);
        // The approved day is this branch's; the 500.00 of cash on it sits in the OTHER manager's ledger.
        $approved = $this->dayAt('final');
        $this->salesCash($approved, '500.00', $other);
        $before = $this->writes();

        $this->transfer(['transferAmount' => 10])->assertStatus(400)->assertJsonPath('errors.code', 'INVALID_AMOUNT');

        $this->assertSame($before, $this->writes());
        $this->assertSame(50000, $this->ledgerHalalas($other), 'the other manager is untouched');
    }

    public function test_only_a_branch_manager_may_call_the_transfer_and_it_writes_nothing_otherwise(): void
    {
        $this->salesCash($this->dayAt('final'), '100.00');
        $before = $this->writes();

        $this->actingAs($this->cashier, 'sanctum')->postJson('/api/custody/transfer', ['transferAmount' => 10])->assertStatus(403);

        $this->assertSame($before, $this->writes());
    }

    public function test_an_amount_finer_than_one_halala_is_refused_not_silently_rounded(): void
    {
        $this->salesCash($this->dayAt('final'), '100.00');
        $before = $this->writes();

        $this->transfer(['transferAmount' => 10.005])->assertStatus(422);
        $this->transfer(['transferAmount' => '10.123'])->assertStatus(422);

        $this->assertSame($before, $this->writes());
        $this->transfer(['transferAmount' => 10.5])->assertOk();
        $this->assertSame(8950, $this->ledgerHalalas());
    }
}
