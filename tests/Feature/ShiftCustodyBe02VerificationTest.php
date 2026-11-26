<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\HandoverSettlementService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Tests\TestCase;

class ShiftCustodyBe02VerificationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $head;

    private BranchManager $manager;

    private Cashier $cashier1;

    private Cashier $cashier2;

    private Employee $empCashier1;

    private Employee $empCashier2;

    private Employee $empManager;

    private Shift $shiftTemplate1;

    private Shift $shiftTemplate2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Assab BE02 Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'BE02 Brand',
            'abbr' => 'BE2',
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

        $this->shiftTemplate1 = Shift::factory()->create([
            'branch_id' => $this->branchA->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->shiftTemplate2 = Shift::factory()->create([
            'branch_id' => $this->branchA->id,
            'start_time' => '16:00:00',
            'end_time' => '23:59:00',
        ]);

        $this->accountant = $this->createAsabUser('acc-be02@assab.test', 'accountant');
        $this->head = $this->createAsabUser('head-be02@assab.test', 'head');

        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Manager A',
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->cashier1 = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Cashier One',
            'created_by' => $this->manager->id,
        ]);

        $this->cashier2 = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Cashier Two',
            'created_by' => $this->manager->id,
        ]);

        $this->empCashier1 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1001',
            'name' => 'Cashier One',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);
        $this->empCashier1->forceFill(['legacy_cashier_id' => $this->cashier1->id])->save();

        $this->empCashier2 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1002',
            'name' => 'Cashier Two',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        $this->empManager = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '2001',
            'name' => 'Manager A',
            'role' => 'branch_manager',
            'monthly_salary' => 200000,
            'status' => 'active',
        ]);

        AsabIdentityMap::create([
            'company_id' => $this->company->id,
            'entity_type' => AsabIdentityMap::ENTITY_CASHIER,
            'dashboard_type' => 'asab_employee',
            'dashboard_id' => $this->empCashier1->id,
            'legacy_type' => 'cashier',
            'legacy_id' => $this->cashier1->id,
            'match_method' => 'id',
        ]);

        AsabIdentityMap::create([
            'company_id' => $this->company->id,
            'entity_type' => AsabIdentityMap::ENTITY_CASHIER,
            'dashboard_type' => 'asab_employee',
            'dashboard_id' => $this->empCashier2->id,
            'legacy_type' => 'cashier',
            'legacy_id' => $this->cashier2->id,
            'match_method' => 'id',
        ]);

        AsabIdentityMap::create([
            'company_id' => $this->company->id,
            'entity_type' => AsabIdentityMap::ENTITY_BRANCH_MANAGER,
            'dashboard_type' => 'asab_employee',
            'dashboard_id' => $this->empManager->id,
            'legacy_type' => 'branch_manager',
            'legacy_id' => $this->manager->id,
            'match_method' => 'id',
        ]);
    }

    private function createAsabUser(string $email, string $roleKey): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => ucfirst($roleKey),
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'scope' => 'all',
            'branch_ids' => [],
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_at05_sales_200_card_110_cash_90_posts_only_90_sar_in_custody_statement(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate1->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/end", [
                'total_sales' => '200.00',
                'card_payments' => '110.00',
                'cash_collected' => '90.00',
                'counted_cash' => '90.00',
            ])
            ->assertOk();

        $res = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(9000, $res['balances']['total_cash_in']);
        $this->assertSame(0, $res['balances']['total_cash_out']);
        $this->assertSame(9000, $res['balances']['closing_balance']);
        $this->assertCount(1, $res['entries']);
        $this->assertSame('sales_collection', $res['entries'][0]['classification']);
        $this->assertSame(9000, $res['entries'][0]['cash_in']);
    }

    public function test_at06_confirmed_handover_90_links_sender_and_recipient_with_same_receipt_id(): void
    {
        $shift1 = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate1->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        $shift2 = CashierShift::factory()->create([
            'cashier_id' => $this->cashier2->id,
            'shift_id' => $this->shiftTemplate2->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/end-with-handover", [
                'total_sales' => '200.00',
                'card_payments' => '110.00',
                'cash_collected' => '90.00',
                'counted_cash' => '90.00',
                'next_cashier_id' => $this->cashier2->id,
                'handover_amount' => '90.00',
            ])
            ->assertOk();

        // While pending: sender has +9000 only, pending has 9000, recipient balance is 0
        $pendingSender = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $this->assertSame(9000, $pendingSender['balances']['closing_balance']);
        $this->assertCount(1, $pendingSender['pending']);

        // Confirm by recipient cashier
        $this->actingAs($this->cashier2, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/handover/accept", [
                'confirmed_amount' => '90.00',
                'receiving_shift_id' => $shift2->id,
            ])
            ->assertOk();

        $senderStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $recipientStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(0, $senderStmt['balances']['closing_balance']);
        $this->assertCount(0, $senderStmt['pending']);
        $this->assertCount(2, $senderStmt['entries']);
        $this->assertSame(9000, $recipientStmt['balances']['closing_balance']);
        $this->assertCount(0, $recipientStmt['pending']);
        $this->assertCount(1, $recipientStmt['entries']);

        $sentEntry = collect($senderStmt['entries'])->firstWhere('classification', 'handover_sent');
        $recvEntry = collect($recipientStmt['entries'])->firstWhere('classification', 'handover_received');
        $this->assertNotNull($sentEntry);
        $this->assertNotNull($recvEntry);
        $this->assertNotNull($sentEntry['receipt_id']);
        $this->assertSame($sentEntry['receipt_id'], $recvEntry['receipt_id']);
        $this->assertSame(9000, $sentEntry['cash_out']);
        $this->assertSame(9000, $recvEntry['cash_in']);
    }

    public function test_at07_partial_handover_70_from_90_leaves_20_sar_balance(): void
    {
        $shift1 = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate1->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        $shift2 = CashierShift::factory()->create([
            'cashier_id' => $this->cashier2->id,
            'shift_id' => $this->shiftTemplate2->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        // Cashier reports 90.00 physical cash, hands over 70.00 (partial) to cashier2
        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/end-with-handover", [
                'total_sales' => '200.00',
                'card_payments' => '110.00',
                'cash_collected' => '90.00',
                'counted_cash' => '90.00',
                'next_cashier_id' => $this->cashier2->id,
                'handover_amount' => '70.00',
            ])
            ->assertOk();

        $this->actingAs($this->cashier2, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/handover/accept", [
                'confirmed_amount' => '70.00',
                'receiving_shift_id' => $shift2->id,
            ])
            ->assertOk();

        $senderStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $recipientStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(9000, $senderStmt['balances']['total_cash_in']);
        $this->assertSame(7000, $senderStmt['balances']['total_cash_out']);
        $this->assertSame(2000, $senderStmt['balances']['closing_balance']);
        $this->assertSame(7000, $recipientStmt['balances']['closing_balance']);
    }

    public function test_at08_repeated_handover_confirm_and_statement_refresh_never_duplicate(): void
    {
        $shift1 = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate1->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        $shift2 = CashierShift::factory()->create([
            'cashier_id' => $this->cashier2->id,
            'shift_id' => $this->shiftTemplate2->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/end-with-handover", [
                'total_sales' => '90.00',
                'card_payments' => '0.00',
                'cash_collected' => '90.00',
                'counted_cash' => '90.00',
                'next_cashier_id' => $this->cashier2->id,
                'handover_amount' => '90.00',
            ])
            ->assertOk();

        $idempKey = (string) Str::uuid();
        $this->actingAs($this->cashier2, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idempKey])
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/handover/accept", [
                'confirmed_amount' => '90.00',
                'receiving_shift_id' => $shift2->id,
            ])
            ->assertOk();

        // Retry same confirm with same key + second call without key
        $this->actingAs($this->cashier2, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idempKey])
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/handover/accept", [
                'confirmed_amount' => '90.00',
                'receiving_shift_id' => $shift2->id,
            ])
            ->assertOk();

        // Retry with a different key returns 409 HANDOVER_NOT_PENDING and writes nothing
        $this->actingAs($this->cashier2, 'sanctum')
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/cashier/shifts/{$shift1->id}/handover/accept", [
                'confirmed_amount' => '90.00',
                'receiving_shift_id' => $shift2->id,
            ])
            ->assertStatus(409);

        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame(1, CashierCustodyTransaction::where('cashier_id', $this->cashier1->id)->where('transaction_type', 'Handover Sent')->count());
        $this->assertSame(1, CashierCustodyTransaction::where('cashier_id', $this->cashier2->id)->where('transaction_type', 'Handover Received')->count());

        $stmt1 = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $stmt2 = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame($stmt1, $stmt2);
        $this->assertSame(9000, $stmt1['balances']['closing_balance']);
    }

    public function test_at09_rejected_handover_and_two_party_reversal_leave_no_ghost_balance(): void
    {
        // Part A: Rejected handover produces zero custody entries and clears pending
        $shiftA = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate1->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shiftA->id}/end-with-handover", [
                'total_sales' => '90.00',
                'card_payments' => '0.00',
                'cash_collected' => '90.00',
                'counted_cash' => '90.00',
                'next_cashier_id' => $this->cashier2->id,
                'handover_amount' => '90.00',
            ])
            ->assertOk();

        $this->actingAs($this->cashier2, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shiftA->id}/handover/reject", [
                'reason' => 'Wrong amount handed over',
            ])
            ->assertOk();

        $senderAfterReject = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $recipAfterReject = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(9000, $senderAfterReject['balances']['closing_balance']);
        $this->assertCount(0, $senderAfterReject['pending']);
        $this->assertSame(0, $recipAfterReject['balances']['closing_balance']);
        $this->assertCount(0, $recipAfterReject['entries']);
        $this->assertCount(0, $recipAfterReject['pending']);

        // Part B: Confirmed Cashier -> BranchManager handover then mutual two-party reversal
        $managerWorkday = BranchManagerShift::firstOrCreate(
            [
                'branch_manager_id' => $this->manager->id,
                'shift_date' => today(),
            ],
            [
                'branch_id' => $this->branchA->id,
                'status' => 'in_progress',
            ]
        );

        $shiftB = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate2->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shiftB->id}/end-with-handover", [
                'total_sales' => '50.00',
                'card_payments' => '0.00',
                'cash_collected' => '50.00',
                'counted_cash' => '50.00',
                'handover_to_type' => 'branch_manager',
                'branch_manager_id' => $this->manager->id,
                'handover_amount' => '50.00',
            ])
            ->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shiftB->id)->firstOrFail();

        app(ShiftTransferReceiptService::class)->confirmManagerHandover(
            $handover->id,
            $this->manager,
            '50.00'
        );

        // Recipient (manager) initiates reversal dispute -> pending, zero reversal ledger rows yet
        $reversalRes = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/{$handover->id}/reversal-disputes", [
                'reason' => 'Duplicate entry in handover',
            ])
            ->assertCreated()
            ->json();
        $reversalId = $reversalRes['reversal_id']
            ?? $reversalRes['data']['id']
            ?? $reversalRes['id']
            ?? \Modules\Shift\Models\ShiftHandoverReversal::where('original_handover_id', $handover->id)->value('id');

        $mgrBeforeConfirm = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManager->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $this->assertSame(5000, $mgrBeforeConfirm['balances']['closing_balance']);

        // Sender confirms reversal
        $this->actingAs($this->cashier1, 'sanctum')
            ->postJson("/api/v1/shifts/handovers/reversals/{$reversalId}/confirm")
            ->assertOk();

        $mgrAfterReverse = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManager->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $cashierAfterReverse = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        // Net balance on manager back to 0, cashier back to 9000 + 5000 = 14000
        $this->assertSame(0, $mgrAfterReverse['balances']['closing_balance']);
        $this->assertSame(14000, $cashierAfterReverse['balances']['closing_balance']);

        $mgrReversalEntry = collect($mgrAfterReverse['entries'])->firstWhere('reversal_id', $reversalId);
        $this->assertNotNull($mgrReversalEntry);
        $this->assertSame('reversal', $mgrReversalEntry['classification']);
        $this->assertSame('عكس تسليم', $mgrReversalEntry['label_ar']);

        // Part C: Cancelled / superseded pending requests must not appear as ghost pending items
        $cancelledHandover = CashierShiftHandover::create([
            'cashier_shift_id' => $shiftA->id,
            'handover_to_id' => $this->cashier2->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => '15.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'pending',
            'cancelled_at' => now(),
            'superseded_at' => now(),
        ]);

        \Modules\Shift\Models\BranchManagerCashTransfer::create([
            'branch_manager_shift_id' => $managerWorkday->id,
            'destination_cashier_id' => $this->cashier2->id,
            'destination_cashier_shift_id' => $shiftA->id,
            'requested_amount' => '25.00',
            'created_by_id' => $this->manager->id,
            'status' => 'pending',
            'cancelled_at' => now(),
            'superseded_at' => now(),
        ]);

        $c1Stmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $c2Stmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $mStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManager->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertCount(0, $c1Stmt['pending'], "Cancelled handover {$cancelledHandover->id} must not appear in sender pending.");
        $this->assertCount(0, $c2Stmt['pending']);
        $this->assertCount(0, $mStmt['pending']);
    }

    public function test_at10_second_custody_module_movements_appear_promptly_in_statement(): void
    {
        // Build an approved BranchManagerShift day with 100.00 confirmed sales cash via Shift + Custody modules
        $day = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branchA->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);

        $template3 = Shift::factory()->create([
            'branch_id' => $this->branchA->id,
            'start_time' => '01:00:00',
            'end_time' => '04:00:00',
        ]);
        $legacyShift = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $template3->id,
            'shift_date' => $day->shift_date,
            'total_sales' => '100.00',
            'card_payments' => '0.00',
            'cash_collected' => 100.00,
            'opening_balance' => '0.00',
        ]));
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($legacyShift, 'cashier', $this->cashier1->id);
        $count = app(ShiftCashCountService::class)->record($legacyShift, $revision, 10000, 0, 0, 10000);
        $legacyShift->update(['variance' => '0.00']);
        event(new ShiftEndedEvent($legacyShift, false));

        $adminShift = \Modules\Admin\Models\Shift::where('legacy_shift_id', $legacyShift->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $adminShift->id)->firstOrFail();
        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $legacyShift->id,
            'branch_manager_shift_id' => $day->id,
            'report_revision' => $count->evidence_revision_id,
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);
        $op = app(OperationService::class)->approve($op, $this->accountant);
        app(OperationService::class)->finalApprove($op->fresh(), $this->head);

        // FUND-01 requires the canonical manager-day SAL operation to have
        // both accountant and final-head approvals before its cash is transferable.
        Operation::create([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $day->branch_id,
            'module_key' => 'sales',
            'source_module' => 'shifts',
            'source_id' => $day->id,
            'payload' => [
                'managerShiftId' => (string) $day->id,
                'pkg6Review' => ['phase' => 'head_review'],
            ],
            'amount' => 10000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_FINAL,
            'submitted_by_id' => $this->accountant->id,
            'submitted_at' => now()->subMinutes(2),
            'approved_by_id' => $this->accountant->id,
            'approved_at' => now()->subMinute(),
            'final_approved_by_id' => $this->head->id,
            'final_approved_at' => now(),
            'operation_date' => now(),
        ]);

        // Manager receives 100.00 handover from cashier into personal ledger
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $legacyShift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '100.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
        ]);
        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_shift_id' => $day->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => '100.00',
            'confirmed_by_branch_manager_id' => $this->manager->id,
            'confirmed_at' => now(),
        ]);
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '100.00',
            'is_cash_in' => true,
            'cashier_name' => $this->cashier1->name,
            'related_shift_id' => $day->id,
            'receipt_id' => $receipt->id,
            'transaction_date' => now(),
        ]);

        // Second custody-writing module: Modules\Custody POST /api/custody/transfer (60.00 -> branch custody)
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/custody/transfer', ['transferAmount' => 60])
            ->assertOk();

        // Also test Debt Repayment via Shift module: Cashier1 has 40.00 balance & repays 25.00 to manager
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'transaction_type' => 'Total Sales',
            'amount' => '40.00',
            'is_cash_in' => true,
            'related_shift_id' => $legacyShift->id,
            'transaction_date' => now(),
        ]);
        $repayment = app(HandoverSettlementService::class)->submitDebtRepayment($this->cashier1, $this->manager->id, 2500, 'Partial repayment');
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$repayment->id}/confirm")
            ->assertOk();

        $mgrStmt = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManager->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        // 10000 in - 6000 transfer_to_custody + 2500 debt_repayment = 6500
        $this->assertSame(6500, $mgrStmt['balances']['closing_balance']);
        $transferEntry = collect($mgrStmt['entries'])->firstWhere('classification', 'transfer_to_custody');
        $this->assertNotNull($transferEntry);
        $this->assertSame(6000, $transferEntry['cash_out']);
        $this->assertSame('personal_ledger', $transferEntry['source_type']);
        $this->assertNotEmpty($transferEntry['business_date']);

        $repaymentEntry = collect($mgrStmt['entries'])->firstWhere('classification', 'debt_repayment');
        $this->assertNotNull($repaymentEntry);
        $this->assertSame(2500, $repaymentEntry['cash_in']);

        // Handover settlement entry (overpayment return of 5.00 from manager -> cashier via Shift module)
        config()->set('shift.settlement_authority_approved', true);
        try {
            app(HandoverSettlementService::class)->settle(
                $receipt,
                $this->manager,
                'overpayment_return',
                500,
                0,
                'Return excess 5 SAR',
                (string) Str::uuid()
            );
        } finally {
            config()->set('shift.settlement_authority_approved', false);
        }

        $mgrAfterSettle = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/employees/{$this->empManager->id}/shift-custody-statement")
            ->assertOk()
            ->json();
        $this->assertSame(6000, $mgrAfterSettle['balances']['closing_balance']);
        $settlementEntry = collect($mgrAfterSettle['entries'])->firstWhere('classification', 'handover_settlement');
        $this->assertNotNull($settlementEntry);
        $this->assertSame('تسوية تسليم', $settlementEntry['label_ar']);
        $this->assertSame(500, $settlementEntry['cash_out']);
    }

    public function test_branch_scoped_employee_lookup_cases(): void
    {
        $inactiveEmp = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1009',
            'name' => 'Inactive Employee',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'inactive',
        ]);

        $otherBranchEmp = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id,
            'emp_number' => '2002',
            'name' => 'Other Branch Employee',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        // 1. Valid active employee in branchA
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/branches/{$this->branchA->id}/employees/lookup?empNumber=1001")
            ->assertOk()
            ->assertJsonPath('empNumber', '1001')
            ->assertJsonPath('name', 'Cashier One');

        // 2. Non-existent or blank empNumber -> 404
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/branches/{$this->branchA->id}/employees/lookup?empNumber=999999")
            ->assertNotFound();
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/branches/{$this->branchA->id}/employees/lookup?empNumber=")
            ->assertNotFound();

        // 3. Employee in another branch -> 404
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/branches/{$this->branchA->id}/employees/lookup?empNumber={$otherBranchEmp->emp_number}")
            ->assertNotFound();

        // 4. Inactive employee -> 404
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/branches/{$this->branchA->id}/employees/lookup?empNumber={$inactiveEmp->emp_number}")
            ->assertNotFound();

        // 5. Cross-company emp_number isolation & DB uniqueness guarantee
        $secondCompany = AsabCompany::create([
            'name' => 'Second Co Sharing Branch',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        Employee::create([
            'company_id' => $secondCompany->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1001',
            'name' => 'Other Company Emp Number',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        // Accountant scoped to $this->company receives only Cashier One without cross-company leakage
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/branches/{$this->branchA->id}/employees/lookup?empNumber=1001")
            ->assertOk()
            ->assertJsonPath('name', 'Cashier One');

        // Same-company duplicate emp_number in same branch is enforced at DB level by asab_employees_company_emp_unique
        $this->expectException(UniqueConstraintViolationException::class);
        Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1001',
            'name' => 'Duplicate Emp Number',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);
    }
}
