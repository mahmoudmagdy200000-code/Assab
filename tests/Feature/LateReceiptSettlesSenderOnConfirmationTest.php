<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
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
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * BRC v3.4 F-06 — CASH-15 / AC-33 (the late-receipt reading).
 *
 * CASH-15: cash that the sender still owes after the day was submitted stays HIS responsibility until the
 * receiver confirms; the later confirmation (a) reduces the sender's outstanding position by the confirmed
 * amount, (b) increases the receiver's balance at the ACTUAL confirmation time, (c) creates no new sales and
 * (d) does not reopen the submitted report. Before confirmation nothing moves.
 *
 * Money: decimal SAR (2 dp) in `cashier_custody_transactions` / `personal_ledger_transactions`, compared as
 * exact decimal strings; the 300.00 SAR figure matches the approved AC-33 example (30000 halalas).
 *
 * Evidence class: CHARACTERIZATION (SQLite, REVIEWER_EXECUTED) of the existing receipt writer. A distinct
 * "repayment of a debt that is not shift cash" operation (CASH-16, AC-40/41) is NOT modelled by the
 * repository and is NOT covered here.
 */
class LateReceiptSettlesSenderOnConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $shift;

    private CashierShiftHandover $handover;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::create(['name' => 'الأول', 'branch_id' => $branch->id, 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);

        $this->shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $template->id, 'shift_date' => today()->subDay(),
            'status' => ShiftStatus::COMPLETED, 'actual_end_time' => now()->subDay(),
            'cash_collected' => 300.00, 'total_sales' => 300.00, 'card_payments' => 0, 'closing_balance' => 300.00,
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->shift, 'cashier', $this->cashier->id, 0);
        DB::transaction(fn () => app(ShiftCashCountService::class)->record($this->shift, $revision, 30000, 0, 0, 30000));

        BranchManagerShift::query()->firstOrNew([
            'branch_manager_id' => $this->manager->id, 'branch_id' => $branch->id, 'shift_date' => today()->toDateString(),
        ])->fill(['status' => 'active', 'cash_collected' => '300.00'])->save();

        ShiftHandoverStatus::create(['cashier_shift_id' => $this->shift->id, 'status' => HandoverStatus::PENDING, 'manager_approval_status' => 'pending']);
        $this->handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id, 'handover_to_id' => $this->manager->id, 'handover_to_type' => 'branch_manager',
            'handover_amount' => 300.00, 'handover_date' => today()->subDay()->toDateString(), 'handover_time' => now()->subDay(),
            'status' => 'pending', 'report_revision_id' => $revision->id,
        ]);

        // The cash the cashier collected is his responsibility (what the shift-end writer records).
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id, 'transaction_type' => 'Total Sales', 'amount' => 300.00, 'is_cash_in' => true,
            'related_shift_id' => $this->shift->id, 'transaction_date' => now()->subDay(),
        ]);
    }

    private function senderBalance(): string
    {
        $in = (string) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->where('is_cash_in', true)->sum('amount');
        $out = (string) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->where('is_cash_in', false)->sum('amount');

        return number_format((float) $in - (float) $out, 2, '.', '');
    }

    private function managerBalance(): string
    {
        $q = PersonalLedgerTransaction::where('branch_manager_id', $this->manager->id);
        $in = (float) (clone $q)->where('is_cash_in', true)->sum('amount');
        $out = (float) (clone $q)->where('is_cash_in', false)->sum('amount');

        return number_format($in - $out, 2, '.', '');
    }

    private function confirm(): void
    {
        app(HandoverService::class)->approveHandover($this->shift, $this->manager->id, get_class($this->manager), null, '300.00');
    }

    public function test_nothing_moves_before_confirmation_and_both_balances_move_by_the_confirmed_amount_at_confirmation(): void
    {
        // Before: the sender is responsible for 300.00, the receiver holds nothing from this shift.
        $this->assertSame('300.00', $this->senderBalance());
        $this->assertSame('0.00', $this->managerBalance());
        $this->assertSame(0, CashierShiftHandoverReceipt::query()->count());

        $this->confirm();

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $this->assertSame('0.00', $this->senderBalance(), 'sender responsibility reduced by exactly the confirmed 300.00');
        $this->assertSame('300.00', $this->managerBalance(), 'receiver increased by exactly the confirmed 300.00');

        $out = CashierCustodyTransaction::where('receipt_id', $receipt->id)->sole();
        $this->assertSame('Handover Sent', $out->transaction_type);
        $this->assertFalse((bool) $out->is_cash_in);
        $this->assertSame('300.00', (string) $out->amount);

        $in = PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole();
        $this->assertTrue((bool) $in->is_cash_in);
        $this->assertSame('300.00', (string) $in->amount);
        $this->assertSame(today()->toDateString(), $in->transaction_date->toDateString(), 'dated at the ACTUAL confirmation, not the shift day');
        $this->assertSame(today()->subDay()->toDateString(), $this->shift->fresh()->shift_date->toDateString());
    }

    public function test_late_confirmation_creates_no_sales_and_does_not_reopen_the_submitted_report(): void
    {
        $revisions = ShiftReportRevision::query()->count();
        $aggregate = DB::table('shift_report_aggregates')->where('source_id', $this->shift->id)->value('current_revision_number');
        $sales = $this->shift->fresh()->only(['total_sales', 'cash_collected', 'card_payments', 'closing_balance', 'status']);

        $this->confirm();

        $this->assertSame($revisions, ShiftReportRevision::query()->count(), 'no new report revision = report not reopened');
        $this->assertSame($aggregate, DB::table('shift_report_aggregates')->where('source_id', $this->shift->id)->value('current_revision_number'));
        $this->assertEquals($sales, $this->shift->fresh()->only(['total_sales', 'cash_collected', 'card_payments', 'closing_balance', 'status']), 'sales figures and shift status are untouched');
        $this->assertSame(1, CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->where('transaction_type', 'Total Sales')->count(), 'no second "sales" row was created for the sender');
    }
}
