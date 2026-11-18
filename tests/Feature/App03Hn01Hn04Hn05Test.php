<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

class App03Hn01Hn04Hn05Test extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private BranchManager $managerA;

    private BranchManager $managerAOther;

    private BranchManager $managerB;

    private Cashier $cashierA;

    private Employee $empCashierA;

    private Employee $empManagerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Assab APP03 Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'APP03 Brand',
            'abbr' => 'AP3',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branchA = Branch::factory()->create([
            'name' => 'Branch A',
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->branchB = Branch::factory()->create([
            'name' => 'Branch B',
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->accountant = $this->createAccountant('acc-app03@assab.test');

        $this->managerA = BranchManager::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Manager A',
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->managerAOther = BranchManager::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Manager A Former',
            'is_active' => false,
            'status' => 'inactive',
        ]);

        $this->managerB = BranchManager::factory()->create([
            'branch_id' => $this->branchB->id,
            'name' => 'Manager B',
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Cashier A',
            'created_by' => $this->managerA->id,
        ]);

        $this->empCashierA = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1007',
            'name' => 'Cashier A',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);
        $this->empCashierA->forceFill(['legacy_cashier_id' => $this->cashierA->id])->save();

        $this->empManagerA = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '2007',
            'name' => 'Manager A',
            'role' => 'branch_manager',
            'monthly_salary' => 200000,
            'status' => 'active',
        ]);

        AsabIdentityMap::create([
            'company_id' => $this->company->id,
            'entity_type' => AsabIdentityMap::ENTITY_CASHIER,
            'dashboard_type' => 'asab_employee',
            'dashboard_id' => $this->empCashierA->id,
            'legacy_type' => 'cashier',
            'legacy_id' => $this->cashierA->id,
            'match_method' => 'id',
        ]);

        AsabIdentityMap::create([
            'company_id' => $this->company->id,
            'entity_type' => AsabIdentityMap::ENTITY_BRANCH_MANAGER,
            'dashboard_type' => 'asab_employee',
            'dashboard_id' => $this->empManagerA->id,
            'legacy_type' => 'branch_manager',
            'legacy_id' => $this->managerA->id,
            'match_method' => 'id',
        ]);
    }

    private function createAccountant(string $email): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant',
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'all',
            'branch_ids' => [],
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createHandoverToManager(
        float $cashCollected = 90.00,
        float $handoverAmount = 90.00,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00'
    ): CashierShift {
        $tpl = Shift::firstOrCreate(
            [
                'branch_id' => $this->branchA->id,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ],
            [
                'name' => "Shift {$startTime}",
                'is_active' => true,
            ]
        );

        BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->managerA->id, 'shift_date' => today()],
            ['branch_id' => $this->branchA->id, 'status' => 'in_progress']
        );

        BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->managerAOther->id, 'shift_date' => today()],
            ['branch_id' => $this->branchA->id, 'status' => 'in_progress']
        );

        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $tpl->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'opening_balance' => 0.00,
            'next_cashier_id' => null,
        ]);

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
                'total_sales' => '200.00',
                'card_payments' => '110.00',
                'cash_collected' => number_format($cashCollected, 2, '.', ''),
                'counted_cash' => number_format($cashCollected, 2, '.', ''),
                'handover_to_type' => 'branch_manager',
                'branch_manager_id' => $this->managerA->id,
                'handover_amount' => number_format($handoverAmount, 2, '.', ''),
            ])
            ->assertOk();

        return $shift->fresh(['handover', 'handoverStatus']);
    }

    public function test_hn01_pending_and_start_enforce_turn_order_and_window_rules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Riyadh'));
        try {
            // Shift 1: eligible right now (09:00-17:00)
            $tplEligible = Shift::factory()->create([
                'branch_id' => $this->branchA->id,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ]);
            // Shift 2: overlaps window (11:00-19:00 at 10:00 is inside 09:00..21:00),
            // but turn has NOT come yet because Shift 1 is earlier
            $tplLaterTurn = Shift::factory()->create([
                'branch_id' => $this->branchA->id,
                'start_time' => '11:00:00',
                'end_time' => '19:00:00',
            ]);
            // Shift 3: tomorrow (outside start window)
            $tplFutureWindow = Shift::factory()->create([
                'branch_id' => $this->branchA->id,
                'start_time' => '18:00:00',
                'end_time' => '23:00:00',
            ]);

            $s1 = CashierShift::factory()->create([
                'cashier_id' => $this->cashierA->id,
                'shift_id' => $tplEligible->id,
                'shift_date' => '2026-11-18',
                'status' => ShiftStatus::NOT_STARTED,
                'opening_balance' => 0.00,
            ]);
            $s2 = CashierShift::factory()->create([
                'cashier_id' => $this->cashierA->id,
                'shift_id' => $tplLaterTurn->id,
                'shift_date' => '2026-11-18',
                'status' => ShiftStatus::NOT_STARTED,
                'opening_balance' => 0.00,
            ]);
            $s3 = CashierShift::factory()->create([
                'cashier_id' => $this->cashierA->id,
                'shift_id' => $tplFutureWindow->id,
                'shift_date' => '2026-11-18',
                'status' => ShiftStatus::NOT_STARTED,
                'opening_balance' => 0.00,
            ]);

            $pending = $this->actingAs($this->cashierA, 'sanctum')
                ->getJson('/api/v1/cashier/my-shifts/pending')
                ->assertOk()
                ->json('data');

            $this->assertCount(3, $pending);
            $this->assertSame($s1->id, $pending[0]['id']);
            $this->assertTrue($pending[0]['is_next_shift']);
            $this->assertTrue($pending[0]['can_start']);
            $this->assertNull($pending[0]['start_disabled_reason']);

            // Later turn shift blocked by turn order even though window is open
            $this->assertSame($s2->id, $pending[1]['id']);
            $this->assertFalse($pending[1]['is_next_shift']);
            $this->assertFalse($pending[1]['can_start']);
            $this->assertNotEmpty($pending[1]['start_disabled_reason']);

            $this->postJson("/api/v1/cashier/shifts/{$s2->id}/start")->assertStatus(400);

            // Start eligible shift -> moves to in-progress, next shift becomes s2
            $this->postJson("/api/v1/cashier/shifts/{$s1->id}/start")->assertOk();
            $this->assertSame(ShiftStatus::IN_PROGRESS, $s1->fresh()->status);

            $inProgress = $this->getJson('/api/v1/cashier/my-shifts/in-progress')
                ->assertOk()
                ->json('data.shifts');
            $this->assertSame($s1->id, $inProgress[0]['id']);

            // Advance s1 & complete it, start s2, leaving s3 whose window is not yet open (16:00 > 10:00)
            $this->postJson("/api/v1/cashier/shifts/{$s2->id}/start")->assertOk();
            $pendingAfter = $this->getJson('/api/v1/cashier/my-shifts/pending')->assertOk()->json('data');
            $this->assertSame($s3->id, $pendingAfter[0]['id']);
            $this->assertTrue($pendingAfter[0]['is_next_shift']);
            $this->assertFalse($pendingAfter[0]['can_start']);
            $this->assertStringContainsString('16:00', (string) $pendingAfter[0]['start_disabled_reason']);
            $this->postJson("/api/v1/cashier/shifts/{$s3->id}/start")->assertStatus(400);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_hn04_requests_full_90_approval_balances_receipt_and_duplicate_block(): void
    {
        $shift = $this->createHandoverToManager(90.00, 90.00);

        // Addressed manager sees accept/reject actions
        $requestsA = $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/v1/branch-manager/requests/handovers')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $requestsA);
        $this->assertTrue($requestsA[0]['is_incoming']);
        $this->assertTrue($requestsA[0]['can_be_accepted']);
        $this->assertSame($this->managerA->id, $requestsA[0]['handover_to_id']);

        // Inactive / non-addressed manager is forbidden from Requests & Approve/Reject
        $this->actingAs($this->managerAOther, 'sanctum')
            ->getJson('/api/v1/branch-manager/requests/handovers')
            ->assertStatus(403);

        // Other active branch manager sees no incoming/actionable handover for Branch A and gets 403 on approve/reject
        $requestsOther = $this->actingAs($this->managerB, 'sanctum')
            ->getJson('/api/v1/branch-manager/requests/handovers')
            ->assertOk()
            ->json('data');
        $this->assertSame([], $requestsOther);

        $this->actingAs($this->managerAOther, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/approve", [
                'confirmed_amount' => '90.00',
            ])
            ->assertStatus(403);

        $this->actingAs($this->managerAOther, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", [
                'rejection_reason' => 'Not yours',
            ])
            ->assertStatus(403);

        // Foreign branch manager denied on both approve and reject
        $this->actingAs($this->managerB, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/approve", [
                'confirmed_amount' => '90.00',
            ])
            ->assertStatus(403);

        $this->actingAs($this->managerB, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", [
                'rejection_reason' => 'Foreign branch',
            ])
            ->assertStatus(403);

        // Addressed manager approves 90 from Requests endpoint
        $approveRes = $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/approve", [
                'confirmed_amount' => '90.00',
                'manager_comment' => 'Approved in Requests tab',
            ])
            ->assertOk()
            ->json();

        $receiptId = $approveRes['data']['approval_details']['receipt_id'] ?? null;
        $this->assertNotEmpty($receiptId);

        // Verify shared receipt & exact balances: cashier 0, manager +90
        $cashierStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashierA->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $managerStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManagerA->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $sentEntry = collect($cashierStmt['entries'])->firstWhere('classification', 'handover_sent');
        $recvEntry = collect($managerStmt['entries'])->firstWhere('classification', 'handover_received');
        $this->assertNotNull($sentEntry);
        $this->assertNotNull($recvEntry);
        $this->assertSame(0, $cashierStmt['balances']['closing_balance']);
        $this->assertSame(9000, $managerStmt['balances']['closing_balance']);
        $this->assertSame($receiptId, $sentEntry['receipt_id']);
        $this->assertSame($receiptId, $recvEntry['receipt_id']);

        // Duplicate approve from Requests OR Workday Management must return 409 and NOT duplicate ledger entries
        $ledgerCountBefore = DB::table('cashier_custody_transactions')->count();
        $receiptCountBefore = DB::table('cashier_shift_handover_receipts')->count();

        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/approve", [
                'confirmed_amount' => '90.00',
            ])
            ->assertStatus(409);

        $this->actingAs($this->managerA, 'sanctum')
            ->postJson('/api/v1/branch-manager/workday/handoffs/approve', [
                'handover_id' => $shift->handover->id,
                'confirmed_amount' => '90.00',
            ])
            ->assertStatus(409);

        $this->assertSame($ledgerCountBefore, DB::table('cashier_custody_transactions')->count());
        $this->assertSame($receiptCountBefore, DB::table('cashier_shift_handover_receipts')->count());
    }

    public function test_hn04_requests_partial_70_of_90_and_reject_preserve_accounting_invariants(): void
    {
        // Case A: Partial handover 70 of 90 -> cashier keeps 20, manager gets +70
        $shiftPartial = $this->createHandoverToManager(90.00, 70.00);
        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shiftPartial->id}/handover/approve", [
                'confirmed_amount' => '70.00',
            ])
            ->assertOk();

        $cashierAfterPartial = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashierA->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $managerAfterPartial = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManagerA->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(2000, $cashierAfterPartial['balances']['closing_balance']);
        $this->assertSame(7000, $managerAfterPartial['balances']['closing_balance']);

        // Case B: Reject another pending handover 90 -> status rejected, balances unchanged (cashier still 20+90=110 from sales, manager still 70)
        $shiftReject = $this->createHandoverToManager(90.00, 90.00, '16:00:00', '23:59:00');
        $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shiftReject->id}/handover/reject", [
                'rejection_reason' => 'Short cash count mismatch',
            ])
            ->assertOk();

        $requestsAfterReject = $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/v1/branch-manager/requests/handovers')
            ->assertOk()
            ->json('data');
        $rejectedItem = collect($requestsAfterReject)->firstWhere('id', $shiftReject->id);
        $this->assertSame('Rejected', $rejectedItem['status']);
        $this->assertFalse($rejectedItem['can_be_accepted']);

        $cashierAfterReject = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashierA->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $managerAfterReject = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManagerA->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(11000, $cashierAfterReject['balances']['closing_balance']);
        $this->assertSame(7000, $managerAfterReject['balances']['closing_balance']);
    }

    public function test_hn05_historical_final_handover_scoped_to_branch_readonly(): void
    {
        $knownDate = '2026-11-10';
        $emptyDate = '2026-11-05';

        $shiftA = BranchManagerShift::create([
            'branch_manager_id' => $this->managerAOther->id,
            'branch_id' => $this->branchA->id,
            'shift_date' => $knownDate,
            'status' => 'completed',
            'total_sales' => 500.00,
            'net_sales' => 434.78,
            'vat_amount' => 65.22,
            'cash_collected' => 200.00,
            'card_payments' => 300.00,
            'handover_amount' => 200.00,
            'closing_balance' => 200.00,
            'handover_status' => 'accepted',
            'handover_notes' => 'Historical final handover A',
        ]);

        $shiftOtherBranch = BranchManagerShift::create([
            'branch_manager_id' => $this->managerB->id,
            'branch_id' => $this->branchB->id,
            'shift_date' => $knownDate,
            'status' => 'completed',
            'total_sales' => 900.00,
            'cash_collected' => 900.00,
            'handover_amount' => 900.00,
            'closing_balance' => 900.00,
            'handover_status' => 'accepted',
        ]);

        // Known date returns branch shift (including prior manager of the same branch)
        $historyKnown = $this->actingAs($this->managerA, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/history?date={$knownDate}")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $historyKnown);
        $this->assertSame($shiftA->id, $historyKnown[0]['id']);

        // Active branch manager can view their branch historical final handover read-only
        $finalRes = $this->actingAs($this->managerA, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/final-handover/{$shiftA->id}")
            ->assertOk()
            ->json('data');
        $this->assertSame($shiftA->id, $finalRes['shift']['id']);
        $this->assertEquals(200.00, $finalRes['handover']['handover_amount']);
        $this->assertEquals(500.00, $finalRes['financial_summary']['total_sales']);

        // Date with no handover returns empty list
        $historyEmpty = $this->actingAs($this->managerA, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/history?date={$emptyDate}")
            ->assertOk()
            ->json('data');
        $this->assertSame([], $historyEmpty);

        // Accessing another branch's final handover is forbidden (403)
        $this->actingAs($this->managerA, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/final-handover/{$shiftOtherBranch->id}")
            ->assertStatus(403);
    }
}
