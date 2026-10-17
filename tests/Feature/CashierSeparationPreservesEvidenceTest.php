<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierService;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * BRC v3.4 F-06 — MEM-01…MEM-06 (AC-62, AC-63, AC-64, AC-68, AC-69 in part).
 *
 * Approved separation semantics (M05): separating an employee stops his access at once, archives his
 * membership, and changes NO financial fact — his custody balance, his pending handover/transfer and the
 * evidence stay under his name until a REAL settlement; separation is neither repayment nor receipt; nothing
 * is zeroed, reassigned or confirmed on his behalf.
 *
 * Evidence class: CHARACTERIZATION through the repository's real separation entry points —
 * `Cashier` status → 'deactivated' (access stop) and `CashierService::deleteCashier` (archive/soft-delete) —
 * on SQLite. The repository has NO dedicated membership-termination workflow, NO follow-up-assignment
 * workflow (MEM-04) and NO "notify the senior manager" step (MEM-05): those are NOT IMPLEMENTED and are not
 * asserted here.
 */
class CashierSeparationPreservesEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $shift;

    private CashierShiftHandover $handover;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id, 'created_by' => $this->manager->id, 'status' => 'active',
        ]);

        $template = Shift::create([
            'name' => 'الأول', 'branch_id' => $this->branch->id,
            'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true,
        ]);

        $this->shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->subDay(), 'status' => ShiftStatus::COMPLETED,
            'actual_end_time' => now()->subDay(),
            'cash_collected' => 300.00, 'total_sales' => 300.00, 'card_payments' => 0, 'closing_balance' => 300.00,
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->shift, 'cashier', $this->cashier->id, 0);
        DB::transaction(fn () => app(ShiftCashCountService::class)->record($this->shift, $revision, 30000, 0, 0, 30000));

        BranchManagerShift::query()->firstOrNew([
            'branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id, 'shift_date' => today()->toDateString(),
        ])->fill(['status' => 'active', 'cash_collected' => '300.00'])->save();

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $this->shift->id, 'status' => HandoverStatus::PENDING, 'manager_approval_status' => 'pending',
        ]);
        $this->handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id, 'handover_to_id' => $this->manager->id, 'handover_to_type' => 'branch_manager',
            'handover_amount' => 300.00, 'handover_date' => today()->subDay()->toDateString(),
            'handover_time' => now()->subDay(), 'status' => 'pending', 'report_revision_id' => $revision->id,
        ]);

        // The employee's own custody position: he holds 100 (in 130, out 30) and owes a prior debt of 20.
        foreach ([['Cash Collected', 130.00, true], ['Handover Sent', 30.00, false], ['Variance', 20.00, false]] as [$type, $amount, $in]) {
            CashierCustodyTransaction::create([
                'cashier_id' => $this->cashier->id, 'transaction_type' => $type, 'amount' => $amount,
                'is_cash_in' => $in, 'transaction_date' => now(),
            ]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, string> a fingerprint of every table a separation must NOT change */
    private function evidence(): array
    {
        $out = [];
        $tables = [
            'cashier_custody_transactions', 'custody_transactions', 'personal_ledger_transactions',
            'cashier_shift_handovers', 'cashier_shift_handover_receipts', 'shift_handover_status', 'cashier_shifts',
        ];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy('id')->values()->toJson();
            $out[$table] = md5($rows).':'.DB::table($table)->count();
        }

        return $out;
    }

    private function confirmReceipt(): void
    {
        app(HandoverService::class)->approveHandover($this->shift, $this->manager->id, get_class($this->manager), null, '300.00');
    }

    private function balance(): float
    {
        return round((float) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)
            ->selectRaw('SUM(CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END) as b')->value('b'), 2);
    }

    // ── MEM-01 / MEM-02 / MEM-04 / MEM-05 (access stop, nothing moved) ────────

    public function test_deactivation_stops_access_at_once_and_changes_no_financial_fact(): void
    {
        $plain = $this->cashier->createToken('mobile')->plainTextToken;
        $this->withToken($plain)->getJson('/api/cashier/my-info')->assertOk();
        $before = $this->evidence();
        $balanceBefore = $this->balance();

        $this->cashier->update(['status' => 'deactivated']);

        $this->assertSame(0, $this->cashier->tokens()->count(), 'every token is revoked');
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/cashier/my-info')->assertUnauthorized();

        $this->assertSame($before, $this->evidence(), 'separation changed a financial or handover fact');
        $this->assertSame($balanceBefore, $this->balance());
        $this->assertSame(80.0, $this->balance(), 'custody 100 and debt 20 stay under his name, nothing zeroed');
        $this->assertSame('pending', $this->handover->fresh()->status, 'his pending handover keeps its state and owner');
        $this->assertSame($this->cashier->id, $this->handover->fresh()->cashierShift->cashier_id);
    }

    // ── MEM-03 / AC-63 / AC-69: the pending operation is still resolved by the authorised person ─────────

    public function test_a_separated_cashiers_pending_handover_is_confirmed_by_the_manager_once_and_never_in_his_name(): void
    {
        $this->cashier->update(['status' => 'deactivated']);

        $this->confirmReceipt();
        $this->shift->refresh();
        try {
            $this->confirmReceipt(); // a retry must be refused, not duplicated
            $this->fail('an already-confirmed handover must not be confirmed again');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            // expected: HANDOVER_NOT_PENDING
        }

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $this->assertSame($this->manager->id, $receipt->receiving_branch_manager_id, 'the confirmation is the manager\'s, not the former employee\'s');
        $this->assertSame($this->handover->id, $receipt->cashier_shift_handover_id);
        $this->assertSame(1, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count(), 'one manager credit');
        $this->assertSame('300.00', (string) PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->sole()->amount);
    }

    // ── MEM-02 / MEM-06 / AC-67 / AC-68: archive keeps the record ─────────────

    public function test_archiving_a_cashier_keeps_balance_responsibility_and_history(): void
    {
        $before = $this->evidence();

        app(CashierService::class)->deleteCashier($this->cashier);

        $this->assertNotNull(Cashier::withTrashed()->find($this->cashier->id)->deleted_at, 'membership archived');
        $this->assertSame($before, $this->evidence(), 'archiving changed a financial or handover fact');
        $this->assertSame(80.0, $this->balance(), 'the balance is not erased and not treated as repaid');
        $this->assertSame('pending', $this->handover->fresh()->status);

        // The cash he handed over is still receivable and the manager's credit is posted exactly once.
        $this->confirmReceipt();
        $this->assertSame(1, CashierShiftHandoverReceipt::query()->count());
        $this->assertSame(1, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count());
    }

    // ── MEM-03 / AC-63: rejected handover, cash not yet returned, then the employee is separated ─────────

    public function test_a_rejected_handover_stays_pending_return_when_its_owner_is_then_archived(): void
    {
        app(HandoverService::class)->rejectHandover($this->shift->fresh(), $this->manager->id, get_class($this->manager), 'العدّ غير مطابق');
        $afterRejection = $this->evidence();
        $this->assertNotSame('approved', $this->handover->fresh()->status);

        app(CashierService::class)->deleteCashier($this->cashier); // separation is allowed

        $this->assertSame($afterRejection, $this->evidence(), 'separation must not "recover", settle or move anything');
        $this->assertSame(0, CashierShiftHandoverReceipt::query()->count(), 'no receipt = no cash recovered');
        $this->assertSame(0, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count());
        $this->assertSame(80.0, $this->balance(), 'his position is untouched until a real action');
    }

    /** MEM-04: the authorised person can also REJECT the pending handover of an already archived employee. */
    public function test_the_manager_can_reject_the_pending_handover_of_an_archived_cashier(): void
    {
        app(CashierService::class)->deleteCashier($this->cashier);

        app(HandoverService::class)->rejectHandover($this->shift->fresh(), $this->manager->id, get_class($this->manager), 'العدّ غير مطابق');

        $this->assertSame(0, CashierShiftHandoverReceipt::query()->count());
        $this->assertSame(0, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count());
        $this->assertSame(80.0, $this->balance());
    }

    // ── MEM-01 / AC-64: an open shift does not stop the access stop ───────────

    public function test_deactivating_a_cashier_with_a_running_shift_stops_access_and_keeps_the_shift_state(): void
    {
        $open = CashierShift::factory()->create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $this->shift->shift_id,
            'shift_date' => today(), 'status' => ShiftStatus::IN_PROGRESS,
        ]);
        $plain = $this->cashier->createToken('mobile')->plainTextToken;

        $this->cashier->update(['status' => 'deactivated']);

        $this->assertSame(ShiftStatus::IN_PROGRESS, $open->fresh()->status, 'the running till is a financial record, left in its state');
        $this->assertSame($this->cashier->id, $open->fresh()->cashier_id, 'the owner is preserved');
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/cashier/my-info')->assertUnauthorized();
    }
}
