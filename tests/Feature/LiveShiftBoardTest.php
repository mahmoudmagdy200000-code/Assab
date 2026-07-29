<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift as AsabShift;
use Modules\Branch\Models\Branch;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Events\ShiftStartedEvent;
use Modules\Shift\Models\CashierShift;
use Tests\TestCase;

/**
 * ACC-6.1 «مباشر» board: a mobile shift must appear while it RUNS, scoped to
 * the accountant's brands. It used to reach `asab_shifts` only at close time,
 * so the live tab was always empty.
 */
class LiveShiftBoardTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Employee $employee;

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
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-0001', 'name' => 'كاشير 1', 'role' => 'cashier',
            'monthly_salary' => 0, 'status' => 'active',
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@live.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [$this->brand->id],
        ]);
    }

    /** A legacy shift owned by this branch's cashier, already mirrored. */
    private function legacyShift(): CashierShift
    {
        $legacy = CashierShift::factory()->create([
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
            'opening_balance' => 100.00,
        ]);
        $this->employee->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();

        return $legacy;
    }

    public function test_a_running_mobile_shift_appears_on_the_live_board(): void
    {
        $legacy = $this->legacyShift();

        event(new ShiftStartedEvent($legacy));

        $mirror = AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $legacy->id)->first();
        $this->assertNotNull($mirror);
        $this->assertSame('active', $mirror->status);
        $this->assertSame($this->branch->id, $mirror->branch_id);
        $this->assertSame(10000, $mirror->opening_float);

        $res = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/shifts/live');
        $res->assertOk();
        $this->assertSame([$mirror->id], collect($res->json('active'))->pluck('id')->all());
        $this->assertSame(1, $res->json('kpis.openNow'));
    }

    public function test_starting_twice_does_not_duplicate_the_row(): void
    {
        $legacy = $this->legacyShift();

        event(new ShiftStartedEvent($legacy));
        event(new ShiftStartedEvent($legacy));

        $this->assertSame(1, AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $legacy->id)->count());
    }

    public function test_closing_finishes_the_same_row_instead_of_adding_a_second(): void
    {
        $legacy = $this->legacyShift();
        event(new ShiftStartedEvent($legacy));
        $liveId = AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $legacy->id)->value('id');

        $legacy->forceFill(['total_sales' => 500.00, 'cash_collected' => 480.00])->save();
        event(new ShiftEndedEvent($legacy, false));

        $rows = AsabShift::withoutGlobalScopes()->where('legacy_shift_id', $legacy->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($liveId, $rows->first()->id);
        $this->assertNotSame('active', $rows->first()->status);

        // And it leaves the live board once it is no longer running.
        $res = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/shifts/live');
        $res->assertOk();
        $this->assertSame([], collect($res->json('active'))->pluck('id')->all());
    }

    public function test_another_brands_running_shift_is_not_shown(): void
    {
        $otherBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'شاورما الأصيل', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id, 'asab_brand_id' => $otherBrand->id,
        ]);
        $otherEmployee = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $otherBranch->id,
            'emp_number' => 'EMP-0002', 'name' => 'كاشير 2', 'role' => 'cashier',
            'monthly_salary' => 0, 'status' => 'active',
        ]);
        $otherLegacy = CashierShift::factory()->create([
            'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->subHour(),
        ]);
        $otherEmployee->forceFill(['legacy_cashier_id' => $otherLegacy->cashier_id])->save();

        event(new ShiftStartedEvent($this->legacyShift()));
        event(new ShiftStartedEvent($otherLegacy));

        $res = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/accountant/shifts/live');
        $res->assertOk();

        $branchIds = collect($res->json('active'))->pluck('branchId')->unique()->values()->all();
        $this->assertSame([$this->branch->id], $branchIds);
    }
}
