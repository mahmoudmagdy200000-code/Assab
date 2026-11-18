<?php

namespace Tests\Feature;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Services\ShiftEndService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * BE-01 Regression Suite (AT-01, AT-02, AT-03, AT-13, AT-14):
 * Isolates accountant correction cash_collected cap vs derived sales cash,
 * opening balance & separate custody adjustments, aggregator channel edits,
 * 2-employee shortage splits, audit records, idempotency, and authorization.
 */
class ShiftAccountantCashCapRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Branch $otherBranch;

    private Shift $shiftTemplate;

    private Cashier $cashier;

    private BranchManager $manager;

    private Employee $employee1;

    private Employee $employee2;

    private AsabUser $accountant;

    private AsabUser $otherBranchAccountant;

    private AsabUser $nonAccountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'شركة اختبار سقف الكاش',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'علامة الاختبار',
            'abbr' => 'TST',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->employee1 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'أحمد الأول',
            'emp_number' => '1001',
            'role' => 'staff',
            'email' => 'emp1001@test.com',
            'status' => 'active',
        ]);

        $this->employee2 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'خالد الثاني',
            'emp_number' => '1002',
            'role' => 'staff',
            'email' => 'emp1002@test.com',
            'status' => 'active',
        ]);

        $this->accountant = $this->makeUserWithRole('acc-main@test.com', 'accountant', 'branch', [$this->branch->id]);
        $this->otherBranchAccountant = $this->makeUserWithRole('acc-other@test.com', 'accountant', 'branch', [$this->otherBranch->id]);
        $this->nonAccountant = $this->makeUserWithRole('staff-only@test.com', 'employee', 'all');
    }

    private function makeUserWithRole(string $email, string $roleKey, string $scope, array $branchIds = []): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => $email,
            'email' => $email,
            'password' => 'secret',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'scope' => $scope,
            'branch_ids' => $branchIds,
        ]);

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'status' => 'active',
        ]);

        return $user;
    }

    private function startInProgressShift(array $overrides = []): CashierShift
    {
        return CashierShift::create(array_merge([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::IN_PROGRESS->value,
            'actual_start_time' => now()->subHours(4),
            'opening_balance' => '0.00',
        ], $overrides));
    }

    public function test_accountant_correction_rejects_cash_collected_exceeding_derived_channel_cash(): void
    {
        $shift = $this->startInProgressShift();

        // Initial shift end: gross 200, card 110, apps 0 -> derived cash sales = 90
        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '110.00',
            'cash_collected' => '90.00',
            'counted_cash' => '90.00',
        ], $this->cashier);

        $this->assertSame('90.00', (string) CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->sole()->amount);

        // Accountant tries to correct cash_collected to 200.00 when gross=200 and card=110 (max derived cash = 90.00)
        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Attempting to inflate collected cash to gross 200',
                'changes' => [
                    'cash_collected' => '200.00',
                ],
            ]);

        $res->assertStatus(422);

        $fresh = $ended->fresh();
        $this->assertSame('200.00', (string) $fresh->total_sales);
        $this->assertSame('90.00', (string) $fresh->cash_collected);

        $totalSalesTxns = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->get();
        $this->assertCount(1, $totalSalesTxns);
        $this->assertSame('90.00', (string) $totalSalesTxns->first()->amount);
    }

    public function test_accountant_correction_allows_valid_opening_balance_and_separate_custody_adjustment(): void
    {
        $priorShift = CashierShift::factory()->completed()->create([
            'cashier_id' => Cashier::factory()->create(['branch_id' => $this->branch->id])->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today()->subDay()->toDateString(),
        ]);
        $priorRev = app(ShiftReportRevisionService::class)->recordCashierRevision($priorShift, 'cashier', $priorShift->cashier_id);

        $shift = $this->startInProgressShift(['opening_balance' => '50.00']);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $priorShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->cashier->id,
            'next_cashier_shift_id' => $shift->id,
            'handover_amount' => '50.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now()->subHours(4),
            'status' => 'approved',
            'report_revision_id' => $priorRev->id,
        ]);

        CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_id' => $this->cashier->id,
            'receiving_cashier_shift_id' => $shift->id,
            'report_revision_id' => $priorRev->id,
            'confirmed_amount' => '50.00',
            'confirmed_by_id' => $this->cashier->id,
            'confirmed_at' => now()->subHours(4),
        ]);

        // Confirmed opening handover custody +50.00 and a separately classified cash adjustment +15.00
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Handover Received',
            'amount' => '50.00',
            'is_cash_in' => true,
            'related_shift_id' => $priorShift->id,
            'related_handover_id' => $handover->id,
            'transaction_date' => now()->subHours(4),
        ]);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Manual Adjustment',
            'amount' => '15.00',
            'is_cash_in' => true,
            'related_shift_id' => $shift->id,
            'transaction_date' => now()->subHours(2),
        ]);

        // Gross 200, card 110, cash_collected 90, counted_cash = 140 (90 sales cash + 50 confirmed opening) -> balanced
        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '110.00',
            'cash_collected' => '90.00',
            'counted_cash' => '140.00',
        ], $this->cashier);

        // Accountant corrects card to 100.00 and cash_collected to 100.00 (<= 200 - 100 = 100).
        // Expected drawer becomes 200 - 100 + 50 = 150 vs counted 140 => -10.00 shortage allocated to cashier.
        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Bank settlement confirmed 100 SAR card; 100 SAR cash sales + 50 SAR opening',
                'changes' => [
                    'card_payments' => '100.00',
                    'cash_collected' => '100.00',
                ],
                'shortage_allocations' => [
                    ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000],
                ],
            ]);

        $res->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('financial_results.total_sales', '200.00')
            ->assertJsonPath('financial_results.card_payments', '100.00')
            ->assertJsonPath('financial_results.cash_collected', '100.00')
            ->assertJsonPath('financial_results.variance', '-10.00');

        $fresh = $ended->fresh();
        $this->assertSame('173.91', (string) $fresh->net_sales);
        $this->assertSame('26.09', (string) $fresh->vat_amount);

        // Total Sales custody is 90 + 10 = 100; Handover Received (50) and Manual Adjustment (15) preserved
        $salesCustodyNet = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->get()
            ->sum(fn ($t) => $t->is_cash_in ? (float) $t->amount : -(float) $t->amount);
        $this->assertEquals(100.00, $salesCustodyNet);

        $this->assertSame('50.00', (string) CashierCustodyTransaction::where('related_handover_id', $handover->id)
            ->where('transaction_type', 'Handover Received')
            ->sole()->amount);
        $this->assertSame('15.00', (string) CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Manual Adjustment')
            ->sole()->amount);
    }

    public function test_accountant_correction_aggregator_edit_and_two_employee_split_with_audit_and_idempotency(): void
    {
        $aggregator = Aggregator::create([
            'name' => 'Ninja',
            'code' => 'NINJA',
            'is_active' => true,
        ]);
        $aggregator->enabledBranches()->attach($this->branch->id, [
            'id' => (string) Str::uuid(),
            'is_enabled' => true,
        ]);

        $shift = $this->startInProgressShift();

        // Initial: gross 200, card 50, Ninja 100, cash_collected 50, counted_cash 50 -> balanced
        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '50.00',
            'cash_collected' => '50.00',
            'counted_cash' => '50.00',
            'aggregators' => [
                ['aggregator_id' => $aggregator->id, 'amount' => '100.00', 'notes' => 'initial'],
            ],
        ], $this->cashier);

        // Correct Ninja 100 -> 50 => expected cash becomes 200 - 50 - 50 = 100 vs counted 50 => -50.00 shortage (-5000h)
        // Incomplete split (4900h) must be rejected without mutation
        $incompleteRes = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Incomplete split check',
                'changes' => [
                    'aggregators' => [
                        ['aggregator_id' => $aggregator->id, 'amount' => '50.00', 'notes' => 'adjusted'],
                    ],
                ],
                'shortage_allocations' => [
                    ['type' => 'employee', 'id' => $this->employee1->id, 'amount' => 3000],
                    ['type' => 'employee', 'id' => $this->employee2->id, 'amount' => 1900],
                ],
            ]);
        $incompleteRes->assertStatus(409);

        // Complete split: 3000h + 2000h = 5000h (SAR 30 + SAR 20)
        $idemKey = (string) Str::uuid();
        $validPayload = [
            'expected_revision' => 1,
            'reason' => 'Ninja statement corrected from 100 to 50 SAR',
            'changes' => [
                'aggregators' => [
                    ['aggregator_id' => $aggregator->id, 'amount' => '50.00', 'notes' => 'adjusted'],
                ],
            ],
            'shortage_allocations' => [
                ['type' => 'employee', 'id' => $this->employee1->id, 'amount' => 3000],
                ['type' => 'employee', 'id' => $this->employee2->id, 'amount' => 2000],
            ],
        ];

        $res1 = $this->withHeader('Idempotency-Key', $idemKey)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", $validPayload);

        $res1->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('financial_results.total_sales', '200.00')
            ->assertJsonPath('financial_results.variance', '-50.00')
            ->assertJsonPath('financial_results.variance_halalas', -5000);

        // Replay with same Idempotency-Key must not duplicate anything
        $res2 = $this->withHeader('Idempotency-Key', $idemKey)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", $validPayload);
        $res2->assertOk();
        $this->assertSame($res1->getContent(), $res2->getContent());

        // Audit rows captured actor, reason, revision
        $this->assertDatabaseHas('shift_report_corrections', [
            'new_revision_number' => 2,
            'actor_type' => 'accountant',
            'actor_id' => $this->accountant->id,
            'reason' => 'Ninja statement corrected from 100 to 50 SAR',
        ]);

        // Liability posted to employee movements (3000h and 2000h), NOT physical cash custody
        $this->assertSame(2, ShiftLiabilityPosting::where('cashier_shift_id', $ended->id)->count());
        $this->assertSame(3000, (int) EmployeeMovement::where('employee_id', $this->employee1->id)->sole()->amount);
        $this->assertSame(2000, (int) EmployeeMovement::where('employee_id', $this->employee2->id)->sole()->amount);
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $ended->id)->count());
    }

    public function test_accountant_correction_rejects_channel_sum_exceeding_gross_and_unconfigured_aggregator(): void
    {
        $shift = $this->startInProgressShift([
            'status' => ShiftStatus::COMPLETED->value,
            'total_sales' => '200.00',
            'card_payments' => '50.00',
            'cash_collected' => '150.00',
        ]);
        app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->cashier->id);

        // 1. Card payments exceeding gross even without prior ShiftReportCashCount
        $overCardRes = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$shift->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Cards exceed gross sales',
                'changes' => [
                    'card_payments' => '210.00',
                ],
            ]);
        $overCardRes->assertStatus(422);

        // 2. Unconfigured/inactive aggregator rejected
        $inactiveAgg = Aggregator::create([
            'name' => 'InactiveApp',
            'code' => 'INACT',
            'is_active' => false,
        ]);
        $badAggRes = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$shift->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Inactive aggregator attempt',
                'changes' => [
                    'aggregators' => [
                        ['aggregator_id' => $inactiveAgg->id, 'amount' => '10.00', 'notes' => null],
                    ],
                ],
            ]);
        $badAggRes->assertStatus(422);
    }

    public function test_accountant_correction_blocks_final_approved_wrong_role_and_wrong_branch(): void
    {
        $shift = $this->startInProgressShift();
        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '110.00',
            'cash_collected' => '90.00',
            'counted_cash' => '90.00',
        ], $this->cashier);

        // 1. Non-accountant role -> 403
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->nonAccountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Wrong role attempt',
                'changes' => ['handover_notes' => 'x'],
            ])
            ->assertStatus(403);

        // 2. Accountant for another branch -> 403
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->otherBranchAccountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Wrong branch attempt',
                'changes' => ['handover_notes' => 'x'],
            ])
            ->assertStatus(403);

        // 3. Final-approved operation -> 409 REPORT_FINAL_APPROVED
        $adminShift = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_employee_id' => $this->employee1->id,
            'shift_type' => 'صباحي',
            'role' => 'كاشير',
            'status' => 'closed',
            'legacy_shift_id' => $ended->id,
            'sales_amount' => 20000,
            'variance' => 0,
        ]);
        Operation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'public_id' => 'SHF-'.strtoupper(Str::random(6)),
            'module_key' => 'shifts',
            'source_id' => $adminShift->id,
            'title' => 'Finalized shift',
            'amount' => 20000,
            'status' => Operation::STATUS_FINAL,
            'payload' => ['shiftId' => $adminShift->id, 'legacyShiftId' => $ended->id],
        ]);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Attempt on finalized report',
                'changes' => ['handover_notes' => 'should fail'],
            ])
            ->assertStatus(409);
    }

    public function test_accountant_card_increase_caps_previously_posted_sales_custody_to_derived_cash(): void
    {
        $shift = $this->startInProgressShift();

        // Initial: gross 200, card 50, cash_collected 150, counted 150 -> custody Total Sales = +150.00
        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '50.00',
            'cash_collected' => '150.00',
            'counted_cash' => '150.00',
        ], $this->cashier);

        // Accountant corrects card_payments from 50 to 110 (so derived sales cash = 90) without passing cash_collected.
        // Expected drawer becomes 90 vs counted 150 => +60 surplus (no allocation required).
        // Total Sales custody must be reconciled down from 150 to 90.
        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'POS batch settled 110 SAR card instead of 50 SAR',
                'changes' => [
                    'card_payments' => '110.00',
                ],
            ]);

        $res->assertOk();

        $salesCustodyNet = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->get()
            ->sum(fn ($t) => $t->is_cash_in ? (float) $t->amount : -(float) $t->amount);
        $this->assertEquals(90.00, $salesCustodyNet);
    }

    public function test_reverse_case_1_card_decrease_alone_that_would_increase_custody_returns_422_with_no_mutation(): void
    {
        $shift = $this->startInProgressShift();

        // Initial: gross 200, card 110, reported cash_collected 120, counted 100 -> posted Total Sales custody = 90.00
        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '110.00',
            'cash_collected' => '120.00',
            'counted_cash' => '100.00',
        ], $this->cashier);

        $beforeTxns = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->get();
        $this->assertCount(1, $beforeTxns);
        $this->assertSame('90.00', (string) $beforeTxns->first()->amount);

        // Case 1: Accountant adjusts ONLY card_payments 110 -> 100 without specifying cash_collected
        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'POS statement shows 100.00 SAR card instead of 110.00 SAR',
                'changes' => [
                    'card_payments' => '100.00',
                ],
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['cash_collected']]]);

        $fresh = $ended->fresh();
        $this->assertSame('110.00', (string) $fresh->card_payments);
        $this->assertSame('120.00', (string) $fresh->cash_collected);
        $this->assertSame(1, $fresh->reportAggregate()->sole()->current_revision_number);

        $afterTxns = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->get();
        $this->assertCount(1, $afterTxns);
        $this->assertEquals(90.00, $afterTxns->sum(fn ($t) => $t->is_cash_in ? (float) $t->amount : -(float) $t->amount));
    }

    public function test_reverse_case_2_explicit_cash_collected_90_saves_keeps_custody_90_and_exposes_10_collection_difference(): void
    {
        $shift = $this->startInProgressShift();

        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '110.00',
            'cash_collected' => '120.00',
            'counted_cash' => '100.00',
        ], $this->cashier);

        // Case 2: Accountant adjusts card 110 -> 100 and explicitly sets cash_collected = 90.00
        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Confirmed card 100.00 SAR and actual cash collected 90.00 SAR',
                'changes' => [
                    'card_payments' => '100.00',
                    'cash_collected' => '90.00',
                ],
            ]);

        $res->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('financial_results.total_sales', '200.00')
            ->assertJsonPath('financial_results.card_payments', '100.00')
            ->assertJsonPath('financial_results.cash_collected', '90.00');

        $fresh = $ended->fresh();
        $this->assertSame(10.0, $fresh->calculateChannelDifference());
        $channelCheck = ShiftFinancialCalculator::salesChannelCheck(20000, 10000, 0, 9000);
        $this->assertSame(-1000, $channelCheck['channelDifference']);

        // Custody stays at 90.00 with zero new entry
        $afterTxns = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->get();
        $this->assertCount(1, $afterTxns);
        $this->assertSame('90.00', (string) $afterTxns->first()->amount);
        $this->assertEquals(90.00, $afterTxns->sum(fn ($t) => $t->is_cash_in ? (float) $t->amount : -(float) $t->amount));

        $this->assertDatabaseHas('shift_report_corrections', [
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'new_value' => '100.00',
        ]);
        $this->assertDatabaseHas('shift_report_corrections', [
            'new_revision_number' => 2,
            'field_name' => 'cash_collected',
            'new_value' => '90.00',
        ]);
    }

    public function test_reverse_case_3_explicit_cash_collected_100_posts_single_plus_10_entry_and_is_idempotent(): void
    {
        $shift = $this->startInProgressShift();

        $ended = app(ShiftEndService::class)->endShiftOnly($shift, [
            'total_sales' => '200.00',
            'card_payments' => '110.00',
            'cash_collected' => '120.00',
            'counted_cash' => '100.00',
        ], $this->cashier);

        $beforeTxn = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->sole();

        // Case 3: Accountant adjusts card 110 -> 100 and explicitly sets cash_collected = 100.00
        $idemKey = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Confirmed card 100.00 SAR and actual cash collected 100.00 SAR',
            'changes' => [
                'card_payments' => '100.00',
                'cash_collected' => '100.00',
            ],
        ];

        $res1 = $this->withHeader('Idempotency-Key', $idemKey)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", $payload);

        $res1->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('financial_results.total_sales', '200.00')
            ->assertJsonPath('financial_results.card_payments', '100.00')
            ->assertJsonPath('financial_results.cash_collected', '100.00');

        $this->assertSame(0.0, $ended->fresh()->calculateChannelDifference());

        // Replay exact same Idempotency-Key + payload must not duplicate +10.00 custody entry
        $res2 = $this->withHeader('Idempotency-Key', $idemKey)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$ended->id}/corrections", $payload);

        $res2->assertOk();
        $this->assertSame($res1->getContent(), $res2->getContent());

        $afterTxns = CashierCustodyTransaction::where('related_shift_id', $ended->id)
            ->where('transaction_type', 'Total Sales')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $afterTxns);

        $newEntry = $afterTxns->first(fn ($t) => $t->id !== $beforeTxn->id);
        $this->assertNotNull($newEntry);
        $this->assertTrue((bool) $newEntry->is_cash_in);
        $this->assertSame('10.00', (string) $newEntry->amount);
        $this->assertEquals(100.00, $afterTxns->sum(fn ($t) => $t->is_cash_in ? (float) $t->amount : -(float) $t->amount));
    }
}
