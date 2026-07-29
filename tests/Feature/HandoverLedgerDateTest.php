<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\HandoverService;
use Tests\TestCase;

/**
 * A cashier hands cash to the branch manager; the manager approves it the NEXT
 * day (yesterday's shift was still open). The manager's personal ledger entry
 * must be stamped at APPROVAL time — it used to carry the handover's own date,
 * so the approved cash never appeared on the daily account statement
 * (whereDate transaction_date = today) even though the approval succeeded.
 */
class HandoverLedgerDateTest extends TestCase
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
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $template = Shift::create([
            'name' => 'الأول', 'branch_id' => $this->branch->id,
            'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true,
        ]);

        // Yesterday's shift, handed over yesterday, still awaiting approval.
        $this->shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->subDay(),
            'status' => ShiftStatus::IN_PROGRESS,
            'cash_collected' => 5000.00,
            'total_sales' => 5000.00,
        ]);

        $this->handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => 5000.00,
            'handover_date' => today()->subDay()->toDateString(),
            'handover_time' => now()->subDay(),
            'status' => 'pending',
        ]);
    }

    private function approve(): void
    {
        app(HandoverService::class)->approveHandover(
            $this->shift,
            $this->manager->id,
            get_class($this->manager),
            null,
        );
    }

    public function test_approval_posts_the_cash_to_the_managers_ledger_dated_today(): void
    {
        $this->approve();

        $txn = PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->first();
        $this->assertNotNull($txn, 'approving a handover must post to the manager ledger');
        $this->assertSame('Total Sales', $txn->transaction_type);
        $this->assertTrue((bool) $txn->is_cash_in);
        $this->assertSame('5000.00', (string) $txn->amount);
        $this->assertTrue(
            $txn->transaction_date->isToday(),
            'the entry belongs to the day custody actually changed hands (approval), not the handover date',
        );
    }

    public function test_the_daily_account_statement_shows_it(): void
    {
        $this->approve();

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/ledger/transactions?view=daily');

        $res->assertOk();
        $types = collect($res->json('data.transactions'))->pluck('transactionType')->all();
        $this->assertContains('Total Sales', $types);
    }

    public function test_the_balance_reflects_the_approved_handover(): void
    {
        $this->approve();

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/ledger/personal-custody-balance');

        $res->assertOk();
        $this->assertSame(5000.0, (float) $res->json('data.totalCashIn'));
        $this->assertSame(5000.0, (float) $res->json('data.currentBalance'));
    }

    public function test_approving_twice_does_not_double_post(): void
    {
        $this->approve();
        $this->shift->refresh();
        $this->approve();

        $this->assertSame(1, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count());
    }
}
