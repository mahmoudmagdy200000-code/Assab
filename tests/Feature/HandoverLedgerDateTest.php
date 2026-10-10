<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/** Actual manager receipt is ledgered on confirmation time and bound to the receipt. */
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

        // Cashier shift and request are submitted yesterday; receipt is confirmed today.
        $this->shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->subDay(),
            'status' => ShiftStatus::IN_PROGRESS,
            'cash_collected' => 5000.00,
            'total_sales' => 5000.00,
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->shift, 'cashier', $this->cashier->id, 0);
        $managerWorkday = BranchManagerShift::query()
            ->where('branch_manager_id', $this->manager->id)
            ->first();
        if (! $managerWorkday) {
            $managerWorkday = new BranchManagerShift([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today()->toDateString(),
            ]);
        }
        $managerWorkday->fill([
            'branch_id' => $this->branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'active',
            'cash_collected' => '5000.00',
        ])->save();

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $this->shift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);

        $this->handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => 5000.00,
            'handover_date' => today()->subDay()->toDateString(),
            'handover_time' => now()->subDay(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);
    }

    private function approve(): void
    {
        app(HandoverService::class)->approveHandover(
            $this->shift,
            $this->manager->id,
            get_class($this->manager),
            null,
            '5000.00',
        );
    }

    public function test_actual_manager_confirmation_posts_receipt_linked_total_sales_credit(): void
    {
        $this->assertSame(today()->subDay()->toDateString(), $this->shift->shift_date->toDateString());
        $this->approve();

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $ledger = PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->sole();
        $this->assertSame('Total Sales', $ledger->transaction_type);
        $this->assertSame('5000.00', $ledger->amount);
        $this->assertSame($receipt->id, $ledger->receipt_id);
        $this->assertSame($receipt->confirmed_at->toDateString(), $ledger->transaction_date->toDateString());
        $this->assertSame(today()->toDateString(), $receipt->confirmed_at->toDateString());
        $this->assertSame($this->manager->id, $receipt->receiving_branch_manager_id);
        $this->assertSame(1, CashierShiftHistory::where('cashier_shift_id', $this->shift->id)->where('action', 'handover_confirmed')->count());
    }

    public function test_the_daily_account_statement_uses_actual_confirmation_date(): void
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
        try {
            $this->approve();
            $this->fail('An already-approved handover must not be approved again.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertSame(1, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count());
        }

        $this->assertSame(1, PersonalLedgerTransaction::where('related_handover_id', $this->handover->id)->count());
    }
}
