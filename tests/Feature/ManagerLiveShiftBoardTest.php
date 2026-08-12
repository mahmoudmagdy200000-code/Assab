<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift as AsabShift;
use Modules\Admin\Services\ShiftLatenessService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\BranchManagerShift;
use Tests\TestCase;

/**
 * «مدير عمل ستارت شيفت ومظهرش» — the manager's workday lives in
 * `branch_manager_shifts`, which nothing ever mirrored into `asab_shifts`, so
 * the live board only ever showed cashiers.
 */
class ManagerLiveShiftBoardTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Live Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'برجر بيت', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'name' => 'مدير التعاون', 'branch_id' => $this->branch->id,
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@manager-live.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [$this->brand->id],
        ]);
    }

    /** Today's workday row, exactly as `GET /shift/current` creates it. */
    private function managerShift(): BranchManagerShift
    {
        return BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'not_started',
            'opening_balance' => 500.00,
        ]);
    }

    private function start(BranchManagerShift $shift): BranchManagerShift
    {
        $shift->update(['status' => 'in_progress', 'actual_start_time' => now()->subHour()]);

        return $shift->fresh();
    }

    public function test_a_started_manager_shift_appears_on_the_live_board(): void
    {
        $shift = $this->start($this->managerShift());

        $mirror = AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $shift->id)->first();
        $this->assertNotNull($mirror);
        $this->assertSame('active', $mirror->status);
        $this->assertSame(AsabShift::ROLE_BRANCH_MANAGER, $mirror->role);
        $this->assertSame($this->branch->id, $mirror->branch_id);
        $this->assertSame($this->company->id, $mirror->company_id);
        $this->assertSame(50000, $mirror->opening_float);
        // Display-only: the branch's sales belong to the cashier rows.
        $this->assertSame(0, $mirror->sales_amount);

        $res = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/shifts/live');
        $res->assertOk();

        $row = collect($res->json('active'))->firstWhere('id', $mirror->id);
        $this->assertNotNull($row);
        $this->assertSame('مدير التعاون', $row['cashierName']);
        $this->assertSame('branch_manager', $row['role']);
        $this->assertSame('مدير فرع', $row['roleLabelAr']);
        $this->assertSame(1, $res->json('kpis.openNow'));
    }

    public function test_the_mirror_carries_the_managers_roster_employee_for_contact(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-0009', 'name' => 'مدير التعاون', 'role' => 'مدير فرع',
            'phone' => '0551234567', 'monthly_salary' => 0, 'status' => 'active',
        ]);

        $shift = $this->start($this->managerShift());

        $mirror = AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $shift->id)->first();
        $this->assertSame($employee->id, $mirror->cashier_employee_id);

        $res = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/shifts/live');
        $row = collect($res->json('active'))->firstWhere('id', $mirror->id);
        $this->assertSame('0551234567', $row['cashierPhone']);
        $this->assertSame('wa.me/966551234567', $row['whatsapp']);
    }

    public function test_restarting_does_not_duplicate_the_row(): void
    {
        $shift = $this->start($this->managerShift());
        $shift->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        $this->assertSame(1, AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $shift->id)->count());
    }

    public function test_ending_the_workday_takes_the_manager_off_the_board(): void
    {
        $shift = $this->start($this->managerShift());
        $shift->update(['status' => 'completed', 'actual_end_time' => now()]);

        $rows = AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $shift->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('closed', $rows->first()->status);
        $this->assertNotNull($rows->first()->ended_at);
        // No invented cash gap — the manager's money is reviewed once, as the
        // daily sales statement.
        $this->assertNull($rows->first()->variance);

        $res = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/shifts/live');
        $res->assertOk();
        $this->assertSame([], collect($res->json('active'))->pluck('id')->all());
        $this->assertSame(0, $res->json('kpis.cashGapsPendingReview'));
    }

    public function test_a_manager_workday_is_never_flipped_late(): void
    {
        $shift = $this->managerShift();
        $shift->update(['status' => 'in_progress', 'actual_start_time' => now()->subDays(2)]);

        $this->assertSame(0, app(ShiftLatenessService::class)->markLate());
        $this->assertSame('active', AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $shift->id)->value('status'));
    }

    public function test_an_open_manager_workday_does_not_block_opening_a_till_shift(): void
    {
        $this->start($this->managerShift());

        $brm = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير الفرع', 'email' => 'brm@manager-live.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $brm->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->branch->id],
        ]);

        $body = $this->actingAs($brm, 'sanctum')
            ->postJson('/api/v1/company/me/branch/shifts/open', ['openingCashHalalas' => 10000])
            ->assertCreated()->json();

        $this->assertSame(AsabShift::ROLE_CASHIER, $body['role']);
    }
}
