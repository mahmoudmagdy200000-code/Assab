<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Tests\TestCase;

/**
 * G2 — P2: Shift Custody Statement Feature Tests
 *
 * Verifies the read-only projection of shift custody balances,
 * MT-11, MT-12, MT-15, MT-17, MT-18, MT-19 (zero-writes & scoping),
 * MT-29, MT-30, and employee screen identity mappings.
 */
class ShiftCustodyStatementTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabCompany $otherCompany;
    private Branch $branchA;
    private Branch $branchB;
    private AsabUser $accountant;
    private AsabUser $scopedAccountant;
    private Employee $empCashier1;
    private Employee $empCashier2;
    private Employee $empManager;
    private Employee $empUnlinked;
    private Cashier $cashier1;
    private Cashier $cashier2;
    private BranchManager $manager;
    private Shift $shiftTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Assab Custody Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->otherCompany = AsabCompany::create([
            'name' => 'Competitor Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand Custody',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branchA = Branch::factory()->create([
            'name' => 'Branch Alpha',
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->branchB = Branch::factory()->create([
            'name' => 'Branch Beta',
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branchA->id]);

        $this->accountant = $this->createAccountantUser('accountant@assab.test', 'all');
        $this->scopedAccountant = $this->createAccountantUser('scoped@assab.test', 'branch', [$this->branchA->id]);

        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branchA->id]);
        $this->cashier1 = Cashier::factory()->create(['branch_id' => $this->branchA->id, 'created_by' => $this->manager->id]);
        $this->cashier2 = Cashier::factory()->create(['branch_id' => $this->branchA->id, 'created_by' => $this->manager->id]);

        $this->empCashier1 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '1001',
            'name' => 'Cashier One',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

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
            'name' => 'Manager Alpha',
            'role' => 'branch_manager',
            'monthly_salary' => 200000,
            'status' => 'active',
        ]);

        $this->empUnlinked = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '9999',
            'name' => 'Unlinked Employee',
            'role' => 'عامل',
            'monthly_salary' => 50000,
            'status' => 'active',
        ]);

        // Canonical identity map links
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

    private function createAccountantUser(string $email, string $scope, array $branchIds = []): AsabUser
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
            'scope' => $scope,
            'branch_ids' => $branchIds,
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        return $user;
    }

    private function actingAsAccountant(?AsabUser $user = null)
    {
        return $this->actingAs($user ?? $this->accountant, 'sanctum');
    }

    private function createReceipt(
        CashierShift $receivingShift,
        Cashier $receiver,
        string $amount,
        ?string $handoverId = null
    ): CashierShiftHandoverReceipt {
        $aggregate = ShiftReportAggregate::firstOrCreate(
            ['source_type' => 'cashier_shift', 'source_id' => $receivingShift->id],
            [
                'current_revision_number' => 1,
            ]
        );

        $revision = ShiftReportRevision::firstOrCreate(
            ['report_aggregate_id' => $aggregate->id, 'revision_number' => 1],
            [
                'created_by_type' => 'cashier',
                'created_by_id' => $receiver->id,
            ]
        );

        return CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handoverId,
            'receiving_cashier_shift_id' => $receivingShift->id,
            'receiving_cashier_id' => $receiver->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => $amount,
            'confirmed_by_id' => $receiver->id,
            'confirmed_at' => now(),
        ]);
    }

    // ── Contract & Identity Mapping Tests ────────────────────────────────────

    public function test_custody_statement_envelope_structure_for_linked_employee_with_transactions(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Opening Cash',
            'amount' => '100.00',
            'is_cash_in' => true,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        $response = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame($this->empCashier1->id, $response['identity']['employee_id']);
        $this->assertSame('linked', $response['identity_link_state']);
        $this->assertSame('halala', $response['unit']);
        $this->assertTrue($response['balances']['is_available']);
        $this->assertSame(10000, $response['balances']['closing_balance']);
        $this->assertCount(1, $response['entries']);
        $this->assertSame('opening_cash', $response['entries'][0]['classification']);
        $this->assertSame(10000, $response['entries'][0]['cash_in']);
        $this->assertSame(0, $response['entries'][0]['cash_out']);
        $this->assertSame(10000, $response['entries'][0]['running_balance']);
        $this->assertSame('confirmed', $response['entries'][0]['confirmation_state']);
        $this->assertIsArray($response['pending']);
        $this->assertArrayHasKey('pagination', $response);
    }

    public function test_custody_statement_empty_when_linked_without_transactions(): void
    {
        $response = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame('linked', $response['identity_link_state']);
        $this->assertTrue($response['balances']['is_available']);
        $this->assertSame(0, $response['balances']['opening_balance']);
        $this->assertSame(0, $response['balances']['closing_balance']);
        $this->assertEmpty($response['entries']);
        $this->assertEmpty($response['pending']);
        $this->assertSame(0, $response['pagination']['total']);
    }

    public function test_custody_statement_unlinked_employee_has_unavailable_balances(): void
    {
        $response = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empUnlinked->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame('unlinked', $response['identity_link_state']);
        $this->assertFalse($response['balances']['is_available']);
        $this->assertNull($response['balances']['opening_balance']);
        $this->assertNull($response['balances']['closing_balance']);
        $this->assertEmpty($response['entries']);
        $this->assertEmpty($response['pending']);
    }

    // ── MT-11: Confirmed Handover Between Two Cashiers 4200 ──────────────────

    public function test_mt_11_confirmed_handover_between_two_cashiers_4200(): void
    {
        $shiftA = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);

        $shiftB = CashierShift::factory()->create([
            'cashier_id' => $this->cashier2->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        // Cashier 1 initial sales of 4200.00 (420,000 halala)
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shiftA->id,
            'transaction_type' => 'Total Sales',
            'amount' => '4200.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::now()->subMinutes(30),
            'event_at' => Carbon::now()->subMinutes(30),
        ]);

        // Create Handover Receipt of 4200.00
        $receipt = $this->createReceipt($shiftB, $this->cashier2, '4200.00');

        // Cashier 1 custody: cash out 4200
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shiftA->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '4200.00',
            'is_cash_in' => false,
            'receipt_id' => $receipt->id,
            'counterpart_name' => $this->cashier2->name,
            'transaction_date' => Carbon::now()->subMinutes(10),
            'event_at' => Carbon::now()->subMinutes(10),
        ]);

        // Cashier 2 custody: cash in 4200
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier2->id,
            'related_shift_id' => $shiftA->id, // legacy records sender shift in related_shift_id
            'transaction_type' => 'Handover Received',
            'amount' => '4200.00',
            'is_cash_in' => true,
            'receipt_id' => $receipt->id,
            'counterpart_name' => $this->cashier1->name,
            'transaction_date' => Carbon::now()->subMinutes(10),
            'event_at' => Carbon::now()->subMinutes(10),
        ]);

        // 1. Check Cashier 1 Statement
        $resA = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(0, $resA['balances']['closing_balance']);
        $this->assertSame(420000, $resA['balances']['total_cash_in']);
        $this->assertSame(420000, $resA['balances']['total_cash_out']);

        $sentEntry = collect($resA['entries'])->firstWhere('classification', 'handover_sent');
        $this->assertNotNull($sentEntry);
        $this->assertSame(420000, $sentEntry['cash_out']);
        $this->assertSame(0, $sentEntry['running_balance']);
        $this->assertSame($receipt->id, $sentEntry['receipt_id']);
        $this->assertSame('cashier', $sentEntry['counterparty']['type']);
        $this->assertSame($this->cashier2->id, $sentEntry['counterparty']['id']);

        // 2. Check Cashier 2 Statement
        $resB = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(420000, $resB['balances']['closing_balance']);
        $receivedEntry = collect($resB['entries'])->firstWhere('classification', 'handover_received');
        $this->assertNotNull($receivedEntry);
        $this->assertSame(420000, $receivedEntry['cash_in']);
        $this->assertSame(420000, $receivedEntry['running_balance']);
        $this->assertSame($receipt->id, $receivedEntry['receipt_id']);
        $this->assertSame('cashier', $receivedEntry['counterparty']['type']);
        $this->assertSame($this->cashier1->id, $receivedEntry['counterparty']['id']);
        $this->assertSame($shiftB->id, $receivedEntry['receiving_shift_id']);

        // 3. Total restaurant sales remains 4200.00 (420000 halala), not duplicated to 8400.00
        $totalSalesTransactions = CashierCustodyTransaction::where('transaction_type', 'Total Sales')->sum('amount');
        $this->assertEquals(4200.00, (float) $totalSalesTransactions);
    }

    // ── MT-12: Cashier to Manager Chain (3000 sales + 7200 handover) ─────────

    public function test_mt_12_cashier_to_manager_handover_chain(): void
    {
        // Cashier 2 collects 3000.00 additional sales (total custody becomes 4200 + 3000 = 7200)
        $shiftB = CashierShift::factory()->create([
            'cashier_id' => $this->cashier2->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);

        $mgrWorkday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first();
        if ($mgrWorkday) {
            $mgrWorkday->update(['status' => 'in_progress', 'cash_collected' => '7200.00']);
        } else {
            $mgrWorkday = BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branchA->id,
                'shift_date' => today()->toDateString(),
                'status' => 'in_progress',
                'cash_collected' => '7200.00',
            ]);
        }

        // Cashier 2 initial 4200 + new sales 3000
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier2->id,
            'related_shift_id' => $shiftB->id,
            'transaction_type' => 'Opening Cash',
            'amount' => '4200.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::now()->subHours(2),
            'event_at' => Carbon::now()->subHours(2),
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier2->id,
            'related_shift_id' => $shiftB->id,
            'transaction_type' => 'Total Sales',
            'amount' => '3000.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::now()->subHour(),
            'event_at' => Carbon::now()->subHour(),
        ]);

        // Handover receipt of 7200 to manager
        $receipt = $this->createReceipt($shiftB, $this->cashier2, '7200.00');

        // Cashier 2 custody: cash out 7200
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier2->id,
            'related_shift_id' => $shiftB->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '7200.00',
            'is_cash_in' => false,
            'receipt_id' => $receipt->id,
            'counterpart_name' => $this->manager->name,
            'transaction_date' => Carbon::now()->subMinutes(15),
            'event_at' => Carbon::now()->subMinutes(15),
        ]);

        // Manager personal ledger transaction: received from cashier (Total Sales with receipt_id)
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'related_shift_id' => $mgrWorkday->id,
            'transaction_type' => 'Total Sales',
            'amount' => '7200.00',
            'is_cash_in' => true,
            'receipt_id' => $receipt->id,
            'cashier_name' => $this->cashier2->name,
            'transaction_date' => Carbon::now()->subMinutes(15),
            'event_at' => Carbon::now()->subMinutes(15),
        ]);

        // 1. Check Cashier 2 statement: balance is 0
        $resB = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(0, $resB['balances']['closing_balance']);

        // 2. Check Manager statement: balance is 7200.00 (720,000 halala)
        $resMgr = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empManager->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(720000, $resMgr['balances']['closing_balance']);

        $mgrEntry = collect($resMgr['entries'])->firstWhere('classification', 'handover_received');
        $this->assertNotNull($mgrEntry, 'Manager transaction with receipt_id must be classified as handover_received, not sales.');
        $this->assertSame(720000, $mgrEntry['cash_in']);
        $this->assertSame($receipt->id, $mgrEntry['receipt_id']);
        $this->assertSame('cashier', $mgrEntry['counterparty']['type']);
        $this->assertSame($this->cashier2->id, $mgrEntry['counterparty']['id']);

        // Combined actual sales in system remains 3000 + 4200 = 7200, not doubled
        $this->assertSame(1, CashierCustodyTransaction::where('transaction_type', 'Total Sales')->count());
    }

    // ── MT-15: Pending Handover Request Behavior ─────────────────────────────

    public function test_mt_15_pending_handover_in_pending_outside_balance_and_counted_once_on_confirm(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        // Confirmed cash in of 1000.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Opening Cash',
            'amount' => '1000.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::now()->subMinutes(20),
            'event_at' => Carbon::now()->subMinutes(20),
        ]);

        // Pending handover request of 400.00 to Cashier 2
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_id' => $this->cashier2->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => '400.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
        ]);

        // 1. Pending check: balance remains 1000.00 (100,000 halala), pending has 40000
        $res = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(100000, $res['balances']['closing_balance']);
        $this->assertCount(1, $res['pending']);
        $this->assertSame(40000, $res['pending'][0]['amount']);
        $this->assertSame('pending', $res['pending'][0]['status']);
        $this->assertSame('handover_sent', $res['pending'][0]['request_type']);

        // Also check receiver pending (inbound)
        $resRec = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier2->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(0, $resRec['balances']['closing_balance']);
        $this->assertCount(1, $resRec['pending']);
        $this->assertSame(40000, $resRec['pending'][0]['amount']);
        $this->assertSame('handover_received', $resRec['pending'][0]['request_type']);

        // 2. Reject handover: no cash deducted
        $handover->update(['status' => 'rejected']);
        $resAfterReject = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(100000, $resAfterReject['balances']['closing_balance']);
        $this->assertEmpty($resAfterReject['pending']);

        // 3. Confirm new handover: reflected exactly once
        $receipt = $this->createReceipt($shift, $this->cashier2, '400.00', $handover->id);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '400.00',
            'is_cash_in' => false,
            'receipt_id' => $receipt->id,
            'counterpart_name' => $this->cashier2->name,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        $resAfterConfirm = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        // 100000 - 40000 = 60000 halala
        $this->assertSame(60000, $resAfterConfirm['balances']['closing_balance']);
    }

    // ── MT-17: Period Filtering, Opening Balance & Stable Pagination ─────────

    public function test_mt_17_period_filtering_opening_and_closing_balance_across_pages(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        // Day 1 (before requested period): 100.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Opening Cash',
            'amount' => '100.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::parse('2026-11-01 09:00:00'),
            'event_at' => Carbon::parse('2026-11-01 09:00:00'),
        ]);

        // Day 2 (inside period): 200.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Total Sales',
            'amount' => '200.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::parse('2026-11-02 10:00:00'),
            'event_at' => Carbon::parse('2026-11-02 10:00:00'),
        ]);

        // Day 3 (inside period): 300.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Total Sales',
            'amount' => '300.00',
            'is_cash_in' => true,
            'transaction_date' => Carbon::parse('2026-11-03 11:00:00'),
            'event_at' => Carbon::parse('2026-11-03 11:00:00'),
        ]);

        // Query period: 2026-11-02 to 2026-11-04
        $url = "/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement?from=2026-11-02&to=2026-11-04";

        $fullRes = $this->actingAsAccountant()->getJson($url)->assertOk()->json();

        // Opening balance includes Day 1 (100.00 = 10000 halala)
        $this->assertSame(10000, $fullRes['balances']['opening_balance']);
        // Closing balance includes Day 1 + Day 2 + Day 3 (10000 + 20000 + 30000 = 60000 halala)
        $this->assertSame(60000, $fullRes['balances']['closing_balance']);
        $this->assertCount(2, $fullRes['entries']);

        // Check running balances in full list
        $this->assertSame(30000, $fullRes['entries'][0]['running_balance']); // 10000 + 20000
        $this->assertSame(60000, $fullRes['entries'][1]['running_balance']); // 30000 + 30000

        // Test Pagination: Page 1 with pageSize=1
        $page1 = $this->actingAsAccountant()->getJson("{$url}&page=1&pageSize=1")->assertOk()->json();
        $this->assertSame(1, $page1['pagination']['page']);
        $this->assertSame(2, $page1['pagination']['total']);
        $this->assertSame(2, $page1['pagination']['totalPages']);
        $this->assertCount(1, $page1['entries']);
        $this->assertSame(30000, $page1['entries'][0]['running_balance']);
        $this->assertSame(60000, $page1['balances']['closing_balance']);

        // Test Pagination: Page 2 with pageSize=1
        $page2 = $this->actingAsAccountant()->getJson("{$url}&page=2&pageSize=1")->assertOk()->json();
        $this->assertSame(2, $page2['pagination']['page']);
        $this->assertCount(1, $page2['entries']);
        // Running balance continues from previous page (60000), NOT reset to 30000
        $this->assertSame(60000, $page2['entries'][0]['running_balance']);
        $this->assertSame(60000, $page2['balances']['closing_balance']);
    }

    // ── MT-18: Variance & Shortage Excluded, Payroll Ledger Isolated ─────────

    public function test_mt_18_variance_and_shortage_excluded_from_cash_balances_and_payroll_ledger_isolated(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        // Cash sales 500.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Total Sales',
            'amount' => '500.00',
            'is_cash_in' => true,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        // Non-cash Variance entry of 50.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Variance',
            'amount' => '50.00',
            'is_cash_in' => false,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        // Non-cash Shortage entry of 20.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Shortage',
            'amount' => '20.00',
            'is_cash_in' => false,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        // Personal payroll ledger movements in employee_movements (ACC-7 dues)
        EmployeeMovement::create([
            'employee_id' => $this->empCashier1->id,
            'movement_type' => 'credit',
            'amount' => 50000, // 500.00 bonus
            'category' => 'bonus',
            'description' => 'Monthly Bonus',
            'movement_date' => now(),
        ]);

        EmployeeMovement::create([
            'employee_id' => $this->empCashier1->id,
            'movement_type' => 'debit',
            'amount' => 15000, // 150.00 advance
            'category' => 'advance',
            'description' => 'Mid-month advance',
            'movement_date' => now(),
        ]);

        // 1. Custody statement MUST only have physical cash 500.00 (50,000 halala)
        $res = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(50000, $res['balances']['closing_balance']);
        $this->assertCount(1, $res['entries']);
        $this->assertSame('sales_collection', $res['entries'][0]['classification']);

        // 2. Personal employee ledger movements remain exactly 2 and unaltered
        $this->assertSame(2, EmployeeMovement::where('employee_id', $this->empCashier1->id)->count());
    }

    // ── MT-19: Zero Writes Assertion & Out-of-Scope Authorization ────────────

    public function test_mt_19_zero_writes_and_out_of_scope_rejection(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Total Sales',
            'amount' => '300.00',
            'is_cash_in' => true,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        // Record table counts before
        $tables = [
            'cashier_custody_transactions' => CashierCustodyTransaction::count(),
            'personal_ledger_transactions' => PersonalLedgerTransaction::count(),
            'cashier_shift_handover_receipts' => CashierShiftHandoverReceipt::count(),
            'cashier_shift_handovers' => CashierShiftHandover::count(),
            'asab_identity_map' => AsabIdentityMap::count(),
            'employees' => Employee::count(),
            'employee_movements' => EmployeeMovement::count(),
        ];

        // First GET
        $res1 = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        // Second GET
        $res2 = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame($res1, $res2);

        // Verify ZERO writes
        foreach ($tables as $table => $countBefore) {
            $countAfter = match ($table) {
                'cashier_custody_transactions' => CashierCustodyTransaction::count(),
                'personal_ledger_transactions' => PersonalLedgerTransaction::count(),
                'cashier_shift_handover_receipts' => CashierShiftHandoverReceipt::count(),
                'cashier_shift_handovers' => CashierShiftHandover::count(),
                'asab_identity_map' => AsabIdentityMap::count(),
                'employees' => Employee::count(),
                'employee_movements' => EmployeeMovement::count(),
            };
            $this->assertSame($countBefore, $countAfter, "Table {$table} must not be mutated during read statement.");
        }

        // Out-of-scope test 1: Employee belonging to another company -> 404
        $otherEmployee = Employee::create([
            'company_id' => $this->otherCompany->id,
            'branch_id' => $this->branchA->id,
            'emp_number' => '7777',
            'name' => 'Other Co Employee',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$otherEmployee->id}/shift-custody-statement")
            ->assertNotFound();

        // Out-of-scope test 2: Scoped accountant accessing employee in unpermitted branch -> 404
        $empBranchB = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id,
            'emp_number' => '8888',
            'name' => 'Branch B Employee',
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        $this->actingAsAccountant($this->scopedAccountant)
            ->getJson("/api/v1/company/me/employees/{$empBranchB->id}/shift-custody-statement")
            ->assertNotFound();
    }

    // ── MT-29: Handover 48, Reversal, and Corrected 480 ──────────────────────

    public function test_mt_29_handover_reversal_and_corrected_delivery(): void
    {
        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
        ]);

        // 1. Initial flawed handover sent 48.00 (4,800 halala)
        $receipt1 = $this->createReceipt($shift, $this->cashier2, '48.00');

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '48.00',
            'is_cash_in' => false,
            'receipt_id' => $receipt1->id,
            'counterpart_name' => $this->cashier2->name,
            'transaction_date' => Carbon::now()->subMinutes(30),
            'event_at' => Carbon::now()->subMinutes(30),
        ]);

        // 2. Reversal of 48.00 (cash comes back)
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Handover Sent Reversal',
            'amount' => '48.00',
            'is_cash_in' => true,
            'receipt_id' => $receipt1->id,
            'counterpart_name' => $this->cashier2->name,
            'transaction_date' => Carbon::now()->subMinutes(20),
            'event_at' => Carbon::now()->subMinutes(20),
        ]);

        // 3. Corrected handover of 480.00 (48,000 halala)
        $receipt2 = $this->createReceipt($shift, $this->cashier2, '480.00');

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '480.00',
            'is_cash_in' => false,
            'receipt_id' => $receipt2->id,
            'counterpart_name' => $this->cashier2->name,
            'transaction_date' => Carbon::now()->subMinutes(10),
            'event_at' => Carbon::now()->subMinutes(10),
        ]);

        $res = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertCount(3, $res['entries']);
        // Net cash out is 48000 halala
        $this->assertSame(4800, $res['balances']['total_cash_in']);
        $this->assertSame(52800, $res['balances']['total_cash_out']);
        $this->assertSame(-48000, $res['balances']['net_period_change']);
        $this->assertSame(-48000, $res['balances']['closing_balance']);
    }

    // ── MT-30: Debt 20 and Cash Repayment 5 ──────────────────────────────────

    public function test_mt_30_debt_and_cash_repayment(): void
    {
        // Cashier has recorded shortage/debt of 20.00 (2000 halala) in employee movements
        EmployeeMovement::create([
            'employee_id' => $this->empCashier1->id,
            'movement_type' => 'debit',
            'amount' => 2000,
            'category' => 'shortage',
            'description' => 'Shift Shortage Debt',
            'movement_date' => Carbon::now()->subDays(2),
        ]);

        $shift = CashierShift::factory()->create([
            'cashier_id' => $this->cashier1->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);

        // Cashier repays 5.00 cash (500 halala) via receipt into custody
        $receipt = $this->createReceipt($shift, $this->cashier1, '5.00');

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier1->id,
            'related_shift_id' => $shift->id,
            'transaction_type' => 'Debt Repayment Cash In',
            'amount' => '5.00',
            'is_cash_in' => true,
            'receipt_id' => $receipt->id,
            'counterpart_name' => $this->manager->name,
            'transaction_date' => now(),
            'event_at' => now(),
        ]);

        // And records debt settlement credit of 500 halala in personal movements
        EmployeeMovement::create([
            'employee_id' => $this->empCashier1->id,
            'movement_type' => 'credit',
            'amount' => 500,
            'category' => 'settlement',
            'description' => 'Shortage Repayment Ref: ' . $receipt->id,
            'movement_date' => now(),
        ]);

        // 1. Check custody statement: shows cash inflow of 500 halala (5.00)
        $res = $this->actingAsAccountant()
            ->getJson("/api/v1/company/me/employees/{$this->empCashier1->id}/shift-custody-statement")
            ->assertOk()
            ->json();

        $this->assertSame(500, $res['balances']['closing_balance']);

        // 2. Check employee movements debt remains 2000 - 500 = 1500 halala (15.00)
        $debits = EmployeeMovement::where('employee_id', $this->empCashier1->id)->where('movement_type', 'debit')->sum('amount');
        $credits = EmployeeMovement::where('employee_id', $this->empCashier1->id)->where('movement_type', 'credit')->sum('amount');
        $this->assertSame(1500, $debits - $credits);

        // 3. Shift status remains COMPLETED, not reopened
        $this->assertSame(ShiftStatus::COMPLETED, $shift->fresh()->status);
    }
}
