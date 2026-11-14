<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\BranchShiftConfig;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Services\ShiftScheduleBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

final class ShiftScheduleSourceAndRosterAssignmentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabBrand $brand;
    private Branch $branchWithOverride;
    private Branch $branchInheriting;
    private BranchManager $managerOverride;
    private BranchManager $managerInheriting;
    private Cashier $cashierOverride;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Riyadh'));

        $this->company = AsabCompany::create([
            'id' => (string) Str::uuid(),
            'name' => 'شركة دار الهيل للمطاعم',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $this->brand = AsabBrand::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'name' => 'دار الهيل',
            'status' => 'active',
        ]);

        // Branch 1: Has dedicated override (e.g. فرع الملقا)
        $this->branchWithOverride = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'name' => 'فرع الملقا – الرياض',
            'location' => 'الملقا، الرياض',
            'status' => 'active',
            'is_active' => true,
        ]);
        $this->managerOverride = BranchManager::factory()->create([
            'branch_id' => $this->branchWithOverride->id,
            'name' => 'ماجد القحطاني',
            'email' => 'majed.alqahtani@daralhail.test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'is_active' => true,
        ]);
        $this->cashierOverride = Cashier::factory()->create([
            'branch_id' => $this->branchWithOverride->id,
            'created_by' => $this->managerOverride->id,
            'name' => 'يوسف الدوسري',
            'email' => 'yousef.aldosari@daralhail.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        // Branch 2: Inherits from Brand
        $this->branchInheriting = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'name' => 'فرع العليا',
            'location' => 'العليا، الرياض',
            'status' => 'active',
            'is_active' => true,
        ]);
        $this->managerInheriting = BranchManager::factory()->create([
            'branch_id' => $this->branchInheriting->id,
            'name' => 'مدير العليا',
            'email' => 'manager.olaya@daralhail.test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_dated_roster_returns_schedule_source_for_branch_with_own_override(): void
    {
        // Branch has own config: 2 shifts, 2h duration, starting 08:00 (08:00-10:00 and 10:00-12:00)
        BranchShiftConfig::create([
            'branch_id' => $this->branchWithOverride->id,
            'num_shifts' => 2,
            'duration_hours' => 2,
            'duration_minutes' => 120,
            'first_shift_start' => '08:00',
        ]);

        // Seed templates
        $bridge = app(ShiftScheduleBridgeService::class);
        $seeded = $bridge->regenerateForBranch($this->branchWithOverride->id);
        $this->assertSame(2, $seeded);

        $this->actingAs($this->managerOverride, 'sanctum');

        $res = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster?start_date=2026-11-12&end_date=2026-11-18');
        $res->assertOk();
        $res->assertJsonPath('data.schedule_source.has_own_config', true);
        $res->assertJsonPath('data.schedule_source.type', 'branch');
        $res->assertJsonPath('data.schedule_source.label_ar', 'إعداد خاص بالفرع');
        $res->assertJsonPath('data.schedule_source.owner_name', 'فرع الملقا – الرياض');

        $templates = $res->json('data.templates');
        $this->assertCount(2, $templates);
        $this->assertSame('08:00', substr($templates[0]['start_time'], 0, 5));
        $this->assertSame('10:00', substr($templates[0]['end_time'], 0, 5));
        $this->assertSame('10:00', substr($templates[1]['start_time'], 0, 5));
        $this->assertSame('12:00', substr($templates[1]['end_time'], 0, 5));
    }

    public function test_dated_roster_returns_schedule_source_for_branch_inheriting_from_brand(): void
    {
        // Brand has config: 2 shifts, 5h duration, starting 06:30 (06:30-11:30 and 11:30-16:30)
        BrandShiftConfig::create([
            'brand_id' => $this->brand->id,
            'num_shifts' => 2,
            'duration_hours' => 5,
            'duration_minutes' => 300,
            'first_shift_start' => '06:30',
        ]);

        $bridge = app(ShiftScheduleBridgeService::class);
        $seeded = $bridge->regenerateForBrand($this->brand->id);
        $this->assertSame(4, $seeded); // Both branches inherit since branchWithOverride has no BranchShiftConfig in this test

        $this->actingAs($this->managerInheriting, 'sanctum');

        $res = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster?start_date=2026-11-12&end_date=2026-11-18');
        $res->assertOk();
        $res->assertJsonPath('data.schedule_source.has_own_config', false);
        $res->assertJsonPath('data.schedule_source.type', 'brand');
        $res->assertJsonPath('data.schedule_source.label_ar', 'إعداد موروث من العلامة التجارية');
        $res->assertJsonPath('data.schedule_source.owner_name', 'دار الهيل');

        $templates = $res->json('data.templates');
        $this->assertCount(2, $templates);
        $this->assertSame('06:30', substr($templates[0]['start_time'], 0, 5));
        $this->assertSame('11:30', substr($templates[0]['end_time'], 0, 5));
        $this->assertSame('11:30', substr($templates[1]['start_time'], 0, 5));
        $this->assertSame('16:30', substr($templates[1]['end_time'], 0, 5));
    }

    public function test_brand_regeneration_preserves_branch_override_and_updates_inheriting(): void
    {
        // Branch 1 has override (2 x 2h @ 08:00)
        BranchShiftConfig::create([
            'branch_id' => $this->branchWithOverride->id,
            'num_shifts' => 2,
            'duration_hours' => 2,
            'duration_minutes' => 120,
            'first_shift_start' => '08:00',
        ]);
        app(ShiftScheduleBridgeService::class)->regenerateForBranch($this->branchWithOverride->id);

        // Brand config is updated to (2 x 5h @ 06:30)
        BrandShiftConfig::updateOrCreate(
            ['brand_id' => $this->brand->id],
            [
                'num_shifts' => 2,
                'duration_hours' => 5,
                'duration_minutes' => 300,
                'first_shift_start' => '06:30',
            ]
        );

        // Regenerate brand
        app(ShiftScheduleBridgeService::class)->regenerateForBrand($this->brand->id);

        // Branch 1 templates MUST be preserved (08:00-10:00, 10:00-12:00)
        $branch1Templates = Shift::where('branch_id', $this->branchWithOverride->id)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();
        $this->assertCount(2, $branch1Templates);
        $this->assertSame('08:00', substr($branch1Templates[0]->start_time->format('H:i:s'), 0, 5));
        $this->assertSame('10:00', substr($branch1Templates[0]->end_time->format('H:i:s'), 0, 5));

        // Branch 2 templates MUST reflect brand (06:30-11:30, 11:30-16:30)
        $branch2Templates = Shift::where('branch_id', $this->branchInheriting->id)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();
        $this->assertCount(2, $branch2Templates);
        $this->assertSame('06:30', substr($branch2Templates[0]->start_time->format('H:i:s'), 0, 5));
        $this->assertSame('11:30', substr($branch2Templates[0]->end_time->format('H:i:s'), 0, 5));
    }

    public function test_branch_regeneration_resolves_brand_via_restaurant_link(): void
    {
        $restaurant = AsabRestaurant::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'brand_id' => $this->brand->id,
            'name' => 'مطعم دار الهيل',
            'city' => 'الرياض',
            'status' => 'active',
        ]);

        $restaurantBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null, // Links solely through restaurant
            'asab_restaurant_id' => $restaurant->id,
            'name' => 'فرع المطعم المباشر',
            'location' => 'طريق الملك فهد، الرياض',
            'status' => 'active',
            'is_active' => true,
        ]);

        BrandShiftConfig::create([
            'brand_id' => $this->brand->id,
            'num_shifts' => 2,
            'duration_hours' => 5,
            'duration_minutes' => 300,
            'first_shift_start' => '06:30',
        ]);

        $bridge = app(ShiftScheduleBridgeService::class);
        $seeded = $bridge->regenerateForBranch($restaurantBranch->id);
        $this->assertSame(2, $seeded);

        $templates = Shift::where('branch_id', $restaurantBranch->id)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();
        $this->assertCount(2, $templates);
        $this->assertSame('06:30', substr($templates[0]->start_time->format('H:i:s'), 0, 5));
    }

    public function test_roster_assignments_save_and_retrieve_end_to_end(): void
    {
        $this->actingAs($this->managerOverride, 'sanctum');

        $template = Shift::create([
            'id' => (string) Str::uuid(),
            'branch_id' => $this->branchWithOverride->id,
            'name' => 'الوردية الأولى',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'is_active' => true,
        ]);

        // 1. Post roster assignment
        $postRes = $this->postJson('/api/v1/branch-manager/cashiers/roster-assignments', [
            'assignments' => [
                [
                    'shift_id' => $template->id,
                    'cashier_id' => $this->cashierOverride->id,
                    'effective_from' => '2026-11-13',
                ],
            ],
        ]);
        $postRes->assertOk();
        $postRes->assertJsonPath('success', true);

        // 2. Read back
        $getRes = $this->getJson('/api/v1/branch-manager/cashiers/roster-assignments');
        $getRes->assertOk();
        $data = $getRes->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame($template->id, $data[0]['shift_id']);
        $this->assertSame($this->cashierOverride->id, $data[0]['cashier_id']);
        $this->assertSame('2026-11-13', substr($data[0]['effective_from'], 0, 10));
    }
    private function overnightTemplate(): Shift
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 22:39:00', 'Asia/Riyadh'));
        config(['shift.week.work_days' => [0, 1, 2, 3, 4]]);
        $this->actingAs($this->managerOverride, 'sanctum');
        return Shift::create([
            'id' => (string) Str::uuid(), 'branch_id' => $this->branchWithOverride->id,
            'name' => 'الأول', 'start_time' => '22:30:00', 'end_time' => '00:30:00', 'is_active' => true,
        ]);
    }

    public function test_friday_legacy_change_does_not_claim_success_without_assigning(): void
    {
        $template = $this->overnightTemplate();
        $this->putJson('/api/v1/branch-manager/cashiers/update-shifts', [
            'cashier_id' => $this->cashierOverride->id, 'shift_ids' => [$template->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_ids');
        $this->assertDatabaseMissing('cashier_shifts', ['cashier_id' => $this->cashierOverride->id]);
    }

    public function test_explicit_friday_assignment_creates_one_overnight_shift(): void
    {
        $template = $this->overnightTemplate();
        $payload = ['cashier_id' => $this->cashierOverride->id, 'shift_ids' => [$template->id], 'shift_date' => '2026-11-13'];
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', $payload)->assertOk()->assertJsonPath('data.shifts_assigned', 1);
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', $payload)->assertOk();
        $this->assertSame(1, \Modules\Shift\Models\CashierShift::where('cashier_id', $this->cashierOverride->id)->count());
    }

    public function test_missing_roster_schema_returns_actionable_error_without_blocking_dated_assignment(): void
    {
        $template = $this->overnightTemplate();
        \Illuminate\Support\Facades\Schema::rename('branch_shift_roster_assignments', 'test_hidden_roster');
        try {
            $this->postJson('/api/v1/branch-manager/cashiers/roster-assignments', [
                'shift_id' => $template->id, 'cashier_id' => $this->cashierOverride->id, 'effective_from' => '2026-11-13',
            ])->assertStatus(503)->assertJsonPath('error.code', 'ROSTER_SCHEMA_NOT_READY');
            $this->getJson('/api/v1/branch-manager/cashiers/roster-assignments')->assertOk()->assertJsonPath('data', []);
            $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
                'cashier_id' => $this->cashierOverride->id, 'shift_ids' => [$template->id], 'shift_date' => '2026-11-13',
            ])->assertOk()->assertJsonPath('data.shifts_assigned', 1);
        } finally {
            \Illuminate\Support\Facades\Schema::rename('test_hidden_roster', 'branch_shift_roster_assignments');
        }
    }

}
