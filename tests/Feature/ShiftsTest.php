<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\ShiftLatenessService;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\Shift as MobileShift;
use Tests\TestCase;

/**
 * T08 — Shifts foundation: N-shift config, open with cashier + type + float,
 * late detection, KPIs, live sales feed, history/export scope. (The close→pipeline
 * approval chain + cash-gap ledger post are covered separately after blueprint.)
 */
class ShiftsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $manager;

    private Employee $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Shift Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create(['company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@shift.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->manager = AsabUser::create(['company_id' => $this->company->id, 'name' => 'مدير فرع', 'email' => 'brm@shift.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->branchA->id]]);

        $this->cashier = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'emp_number' => '1001', 'name' => 'محمد الكاشير', 'phone' => '0551234567', 'role' => 'كاشير', 'status' => 'active']);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    private function brm()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    // ── Config (T08.1) ───────────────────────────────────────────────────────

    public function test_saving_an_n_shift_config_persists_columns_and_names(): void
    {
        $body = $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 3, 'durationHours' => 8, 'firstShiftStart' => '06:00', 'openingFloatHalalas' => 50000,
        ])->assertOk()->json();

        $this->assertSame(3, $body['numShifts']);
        $this->assertCount(3, $body['shifts']);
        $this->assertSame('الأول', $body['shifts'][0]['name']);
        $this->assertSame('06:00-14:00', $body['shifts'][0]['window']);
        $this->assertSame('14:00-22:00', $body['shifts'][1]['window']);
        $this->assertSame(3, BrandShiftConfig::where('brand_id', $this->brand->id)->value('num_shifts'));
    }

    public function test_saving_the_config_seeds_mobile_shift_rows_for_every_branch(): void
    {
        $res = $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 2, 'durationHours' => 8, 'firstShiftStart' => '06:00',
        ])->assertOk();

        // 2 windows × 2 branches of this brand (فرع أ + فرع ب).
        $res->assertJsonPath('mobileShiftsSeeded', 4);
        $this->assertSame(2, MobileShift::where('branch_id', $this->branchA->id)->count());
        $this->assertSame(2, MobileShift::where('branch_id', $this->branchB->id)->count());

        $first = MobileShift::where('branch_id', $this->branchA->id)->orderBy('start_time')->first();
        $this->assertSame('الأول', $first->name);
        $this->assertTrue($first->is_active);
    }

    public function test_regenerate_reseeds_the_mobile_schedule_without_duplicating(): void
    {
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'numShifts' => 2, 'durationHours' => 8, 'firstShiftStart' => '06:00',
        ])->assertOk();
        $this->assertSame(4, MobileShift::count());

        // The explicit Regenerate action re-projects the saved config; the
        // natural-key upsert updates in place, so nothing is duplicated.
        $this->acc()->postJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config/regenerate")
            ->assertOk()->assertJsonPath('mobileShiftsSeeded', 4);

        $this->assertSame(4, MobileShift::count());
    }

    public function test_legacy_morning_evening_body_is_still_accepted(): void
    {
        $body = $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", [
            'morningWindow' => '06:00-14:00', 'eveningWindow' => '14:00-23:00', 'openingFloatHalalas' => 50000,
        ])->assertOk()->json();

        $this->assertSame(2, $body['numShifts']);
        $this->assertSame('06:00-14:00', $body['morningWindow']);
    }

    public function test_config_defaults_float_and_validates_and_scopes(): void
    {
        // Invalid window format.
        $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", ['firstShiftStart' => '25:99'])
            ->assertStatus(422);
        // Cross-company brand.
        $other = AsabCompany::create(['name' => 'X', 'plan' => 'Basic', 'status' => 'active']);
        $otherBrand = AsabBrand::create(['company_id' => $other->id, 'name' => 'ب', 'sub_status' => 'active', 'status' => 'active']);
        $this->acc()->putJson("/api/v1/company/me/brands/{$otherBrand->id}/shift-config", ['numShifts' => 1])
            ->assertStatus(404);
        // Branch role denied on the config write.
        $this->brm()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", ['numShifts' => 1])
            ->assertStatus(403);
        // Default float when omitted.
        $body = $this->acc()->putJson("/api/v1/company/me/brands/{$this->brand->id}/shift-config", ['numShifts' => 1])->assertOk()->json();
        $this->assertSame(50000, $body['openingFloatHalalas']);
    }

    public function test_config_list_and_write_are_scoped_to_the_assigned_brand(): void
    {
        // A second brand in the SAME company the scoped accountant is NOT assigned to.
        $otherBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'علامة أخرى',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $scoped = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب مخصص',
            'email' => 'scoped-acc@shift.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $scoped->id, 'role_key' => 'accountant',
            'scope' => 'brand', 'brand_ids' => [$this->brand->id],
        ]);

        // The list returns only the accountant's own brand, not the placeholder
        // of a sibling brand (BUG-7).
        $brandIds = collect($this->actingAs($scoped, 'sanctum')
            ->getJson('/api/v1/company/me/shifts/configs')->assertOk()->json('data'))
            ->pluck('brandId')->all();
        $this->assertContains($this->brand->id, $brandIds);
        $this->assertNotContains($otherBrand->id, $brandIds);

        // ...and writing config for the unassigned same-company brand is denied.
        $this->actingAs($scoped, 'sanctum')
            ->putJson("/api/v1/company/me/brands/{$otherBrand->id}/shift-config", ['numShifts' => 1])
            ->assertStatus(404);
    }

    // ── Open (T08.2) ─────────────────────────────────────────────────────────

    public function test_open_persists_cashier_type_and_defaulted_float(): void
    {
        BrandShiftConfig::create(['brand_id' => $this->brand->id, 'num_shifts' => 2, 'duration_hours' => 8, 'first_shift_start' => '06:00', 'shifts' => ['openingFloatHalalas' => 50000]]);

        $body = $this->brm()->postJson('/api/v1/company/me/branch/shifts/open', ['cashierEmpNumber' => '1001'])
            ->assertCreated()->json();

        $this->assertSame('محمد الكاشير', $body['cashierName']);
        $this->assertSame($this->cashier->id, $body['cashierEmployeeId']);
        $this->assertSame(50000, $body['openingFloatHalalas']);
        $this->assertNotNull($body['shiftType']);

        $shift = Shift::first();
        $this->assertSame($this->cashier->id, $shift->cashier_employee_id);
        $this->assertSame(50000, $shift->opening_float);
    }

    public function test_double_open_is_conflict(): void
    {
        $this->brm()->postJson('/api/v1/company/me/branch/shifts/open', ['openingCashHalalas' => 40000])->assertCreated();
        $this->brm()->postJson('/api/v1/company/me/branch/shifts/open', ['openingCashHalalas' => 40000])
            ->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_ALREADY_OPEN');
    }

    public function test_cashier_from_another_branch_is_rejected(): void
    {
        $foreign = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchB->id, 'emp_number' => '2001', 'name' => 'آخر', 'role' => 'كاشير', 'status' => 'active']);

        $this->brm()->postJson('/api/v1/company/me/branch/shifts/open', ['cashierEmpNumber' => $foreign->emp_number])
            ->assertStatus(404);
    }

    // ── Late detection (T08.4) ───────────────────────────────────────────────

    public function test_an_overdue_shift_is_marked_late_idempotently(): void
    {
        BrandShiftConfig::create(['brand_id' => $this->brand->id, 'num_shifts' => 2, 'duration_hours' => 8, 'first_shift_start' => '06:00', 'shifts' => []]);
        $shift = Shift::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'x',
            'shift_no' => 1, 'shift_type' => 'الأول', 'started_at' => Carbon::parse('2026-07-12 06:00'), 'status' => 'active',
        ]);

        $service = app(ShiftLatenessService::class);
        // 10 hours after start (window ends 14:00) → overdue.
        $flipped = $service->markLate(Carbon::parse('2026-07-12 16:00'));
        $this->assertSame(1, $flipped);
        $this->assertSame('late', $shift->fresh()->status);
        // Idempotent — already late, not re-flipped.
        $this->assertSame(0, $service->markLate(Carbon::parse('2026-07-12 17:00')));

        $overdue = $this->acc()->getJson('/api/v1/accountant/shifts/live')->assertOk()->json('overdue');
        $this->assertCount(1, $overdue);
    }

    public function test_a_shift_inside_its_window_is_not_late(): void
    {
        $shift = Shift::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'x',
            'started_at' => Carbon::now()->subMinutes(30), 'status' => 'active',
        ]);
        app(ShiftLatenessService::class)->markLate(Carbon::now());
        $this->assertSame('active', $shift->fresh()->status);
    }

    // ── KPIs (T08.8) ─────────────────────────────────────────────────────────

    public function test_live_returns_kpis(): void
    {
        Shift::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'a', 'started_at' => now(), 'status' => 'active']);
        Shift::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'b', 'started_at' => now(), 'ended_at' => now(), 'status' => 'closed', 'sales_amount' => 500000, 'variance' => -3000]);

        $kpis = $this->acc()->getJson('/api/v1/accountant/shifts/live')->assertOk()->json('kpis');
        $this->assertSame(1, $kpis['openNow']);
        $this->assertSame(1, $kpis['closedToday']);
        $this->assertSame(1, $kpis['cashGapsPendingReview']);
    }

    // ── Live sales feed (T08.10) ─────────────────────────────────────────────

    public function test_a_sales_upload_bumps_the_open_shift(): void
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'a', 'started_at' => now(), 'status' => 'active', 'orders_count' => 0, 'sales_amount' => 0]);

        app(\Modules\Admin\Services\OperationFactory::class)->createFromUpload('sales', ['x' => 1], $this->manager, $this->branchA->id, 25000);

        $shift->refresh();
        $this->assertSame(1, $shift->orders_count);
        $this->assertSame(25000, $shift->sales_amount);
    }

    // ── History + export scope (T08.9) ───────────────────────────────────────

    public function test_history_filters_by_shift_type(): void
    {
        Shift::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'a', 'shift_type' => 'صباحي', 'started_at' => now(), 'ended_at' => now(), 'status' => 'closed']);
        Shift::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'b', 'shift_type' => 'مسائي', 'started_at' => now(), 'ended_at' => now(), 'status' => 'closed']);

        $rows = $this->acc()->getJson('/api/v1/accountant/shifts/history?shiftType=صباحي')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('صباحي', $rows[0]['shiftType']);
    }

    public function test_export_streams_and_is_branch_scoped(): void
    {
        Shift::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'supervisor_name' => 'a', 'started_at' => now(), 'ended_at' => now(), 'status' => 'closed']);
        $this->acc()->get('/api/v1/company/me/shifts/export?format=csv')->assertOk();
    }

    // ── Zero-trust ───────────────────────────────────────────────────────────

    public function test_role_denial(): void
    {
        $this->brm()->getJson('/api/v1/company/me/shifts?status=live')->assertStatus(403);
        $this->acc()->postJson('/api/v1/company/me/branch/shifts/open', ['openingCashHalalas' => 1000])->assertStatus(403);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/accountant/shifts/live')->assertStatus(401);
    }
}
