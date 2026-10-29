<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Services\EmployeeLedgerService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Models\ShiftReportRevision;
use Tests\TestCase;

/**
 * BE-14 (STMT-01): Employee Shortage Review State.
 *
 * Verifies that each individual shortage row in an employee statement exhibits
 * its own line-level reviewStatus ('under_review' -> 'approved' on report approval),
 * with stable movement ID, preserved tenant/branch context, and zero duplicate debits.
 */
class EmployeeShortageReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Branch $branch;
    private AsabUser $accountant;
    private Employee $employee;
    private EmployeeLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'STMT-01 Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'STMT-01 Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Test Accountant',
            'email' => 'acc@stmt01.test',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-STMT-01',
            'name' => 'Shortage Employee',
            'role' => 'cashier',
            'status' => 'active',
        ]);

        $this->ledger = app(EmployeeLedgerService::class);
    }

    public function test_shortage_row_exposes_under_review_status_before_report_approval(): void
    {
        // Setup shift and pending review
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $shift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $cashier->id,
        ]);

        $review = ShiftReportManagerReview::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift->id,
            'report_revision_id' => $revision->id,
            'report_revision_number' => 1,
            'status' => 'pending',
            'variance_halalas' => -2000,
            'has_shortage' => true,
        ]);

        $posting = ShiftLiabilityPosting::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift->id,
            'lineage_key' => $shift->id.':employee:'.$this->employee->id,
            'responsible_type' => 'employee',
            'responsible_id' => $this->employee->id,
            'delta_halalas' => 2000,
            'target_halalas' => 2000,
            'effective_before_halalas' => 0,
            'operation_id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => (string) Str::uuid(),
            'ledger_table' => 'asab_employee_movements',
            'ledger_id' => (string) Str::uuid(),
            'review_id' => $review->id,
            'evidence_id' => $revision->id,
            'status' => 'posted',
        ]);

        $movement = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 2000,
            'category' => 'cash_variance',
            'description' => 'خصم فرق كاش',
            'ref' => 'CSH-001',
            'movement_date' => now(),
            'lineage_key' => $posting->lineage_key,
            'posting_id' => $posting->id,
        ]);

        $statement = $this->ledger->statement($this->employee, null);

        $this->assertCount(1, $statement['movements']);
        $row = $statement['movements'][0];
        $this->assertEquals($movement->id, $row['id']);
        $this->assertEquals(2000, $row['amountHalalas']);
        $this->assertEquals('under_review', $row['reviewStatus'], 'Shortage row must be under_review while report review is pending');
        $this->assertNull($statement['settlementStatus'], 'Statement settlementStatus must not be conflated with line reviewStatus');
        $this->assertEquals(-2000, $statement['balanceHalalas']);
    }

    public function test_shortage_row_transitions_to_approved_on_report_approval_with_stable_id_and_no_duplicate_debit(): void
    {
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $shift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $cashier->id,
        ]);

        $review = ShiftReportManagerReview::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift->id,
            'report_revision_id' => $revision->id,
            'report_revision_number' => 1,
            'status' => 'pending',
            'variance_halalas' => -5000,
            'has_shortage' => true,
        ]);

        $posting = ShiftLiabilityPosting::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift->id,
            'lineage_key' => $shift->id.':employee:'.$this->employee->id,
            'responsible_type' => 'employee',
            'responsible_id' => $this->employee->id,
            'delta_halalas' => 5000,
            'target_halalas' => 5000,
            'effective_before_halalas' => 0,
            'operation_id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => (string) Str::uuid(),
            'ledger_table' => 'asab_employee_movements',
            'ledger_id' => (string) Str::uuid(),
            'review_id' => $review->id,
            'evidence_id' => $revision->id,
            'status' => 'posted',
        ]);

        $movement = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'cash_variance',
            'description' => 'خصم فرق كاش',
            'ref' => 'CSH-002',
            'movement_date' => now(),
            'lineage_key' => $posting->lineage_key,
            'posting_id' => $posting->id,
        ]);

        // Prior to approval: under_review
        $before = $this->ledger->statement($this->employee, null);
        $this->assertEquals('under_review', $before['movements'][0]['reviewStatus']);
        $this->assertEquals(1, $before['movementCount']);
        $this->assertEquals(-5000, $before['balanceHalalas']);

        // Head Accountant approves report review
        $review->update([
            'status' => 'approved',
            'reviewer_type' => 'head_accountant',
            'reviewer_id' => $this->accountant->id,
            'reviewed_at' => now(),
        ]);

        // After approval: same row transitions to 'approved'
        $after = $this->ledger->statement($this->employee, null);
        $this->assertEquals(1, $after['movementCount'], 'Movement count must remain exactly 1 (no duplicate debit)');
        $row = $after['movements'][0];
        $this->assertEquals($movement->id, $row['id'], 'Stable movement ID must be preserved');
        $this->assertEquals(5000, $row['amountHalalas']);
        $this->assertEquals('approved', $row['reviewStatus'], 'Review status must transition to approved on Head Accountant approval');
        $this->assertEquals(-5000, $after['balanceHalalas'], 'Standing balance must not change on badge transition');
    }

    public function test_two_shortage_rows_in_different_review_stages_show_independent_statuses(): void
    {
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);
        $shift1 = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->subDay(),
            'status' => 'completed',
        ]);
        $shift2 = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        // Shift 1 review is approved
        $rev1 = ShiftReportManagerReview::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift1->id,
            'report_revision_id' => (string) Str::uuid(),
            'report_revision_number' => 1,
            'status' => 'approved',
            'variance_halalas' => -1000,
            'has_shortage' => true,
        ]);
        $post1 = ShiftLiabilityPosting::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift1->id,
            'lineage_key' => $shift1->id.':employee:'.$this->employee->id,
            'responsible_type' => 'employee',
            'responsible_id' => $this->employee->id,
            'delta_halalas' => 1000,
            'target_halalas' => 1000,
            'effective_before_halalas' => 0,
            'operation_id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => (string) Str::uuid(),
            'ledger_table' => 'asab_employee_movements',
            'ledger_id' => (string) Str::uuid(),
            'review_id' => $rev1->id,
            'status' => 'posted',
        ]);
        $mov1 = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 1000,
            'category' => 'cash_variance',
            'description' => 'فرق كاش وردية أمس',
            'movement_date' => now()->subDay(),
            'lineage_key' => $post1->lineage_key,
            'posting_id' => $post1->id,
        ]);

        // Shift 2 review is pending
        $rev2 = ShiftReportManagerReview::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift2->id,
            'report_revision_id' => (string) Str::uuid(),
            'report_revision_number' => 1,
            'status' => 'pending',
            'variance_halalas' => -3000,
            'has_shortage' => true,
        ]);
        $post2 = ShiftLiabilityPosting::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $shift2->id,
            'lineage_key' => $shift2->id.':employee:'.$this->employee->id,
            'responsible_type' => 'employee',
            'responsible_id' => $this->employee->id,
            'delta_halalas' => 3000,
            'target_halalas' => 3000,
            'effective_before_halalas' => 0,
            'operation_id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => (string) Str::uuid(),
            'ledger_table' => 'asab_employee_movements',
            'ledger_id' => (string) Str::uuid(),
            'review_id' => $rev2->id,
            'status' => 'posted',
        ]);
        $mov2 = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 3000,
            'category' => 'cash_variance',
            'description' => 'فرق كاش وردية اليوم',
            'movement_date' => now(),
            'lineage_key' => $post2->lineage_key,
            'posting_id' => $post2->id,
        ]);

        $statement = $this->ledger->statement($this->employee, null);
        $this->assertEquals(2, $statement['movementCount']);

        $row1 = collect($statement['movements'])->firstWhere('id', $mov1->id);
        $row2 = collect($statement['movements'])->firstWhere('id', $mov2->id);

        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        $this->assertEquals('approved', $row1['reviewStatus'], 'Approved shift review must yield approved reviewStatus on line');
        $this->assertEquals('under_review', $row2['reviewStatus'], 'Pending shift review must yield under_review reviewStatus on line');
        $this->assertEquals(-4000, $statement['balanceHalalas']);
    }

    public function test_legacy_shortage_row_without_review_record_defaults_to_under_review(): void
    {
        $legacy = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 4500,
            'category' => 'cash_variance',
            'description' => 'فرق كاش قديم بدون توثيق مراجعة',
            'movement_date' => now()->subMonth(),
        ]);

        $statement = $this->ledger->statement($this->employee, null);
        $row = collect($statement['movements'])->firstWhere('id', $legacy->id);

        $this->assertNotNull($row);
        $this->assertEquals('under_review', $row['reviewStatus'], 'Legacy shortage rows without review proof must default to under_review');
    }
}
