<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Tests\TestCase;

/**
 * The live demo database predates the seeder fixes and can never be re-seeded
 * (it holds real records). asab:demo-topup fills the gaps additively — and,
 * more importantly, must be provably incapable of overwriting live data.
 */
class DemoTopUpCommandTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'TopUp Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create([
            'name' => 'فرع الديمو', 'asab_company_id' => $this->company->id, 'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        // A completed cashier shift gives the daily close something to total.
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'status' => ShiftStatus::COMPLETED,
            'total_sales' => 5000, 'cash_collected' => 2000, 'card_payments' => 2000,
        ]);
    }

    public function test_it_fills_the_three_demo_gaps(): void
    {
        $this->assertNull(AsabUser::withoutGlobalScopes()->where('email', 'branch@nakhat.sa')->first());

        $this->artisan('asab:demo-topup')->assertExitCode(0);

        $branchUser = AsabUser::withoutGlobalScopes()->where('email', 'branch@nakhat.sa')->firstOrFail();
        $this->assertTrue(AsabUserRole::where('user_id', $branchUser->id)->where('role_key', 'branch')->exists());
        $this->assertTrue(Operation::withoutGlobalScopes()->where('module_key', 'sales')->exists());

        $waste = Operation::withoutGlobalScopes()->where('module_key', 'waste')->firstOrFail();
        $this->assertStringStartsWith('WD-', $waste->public_id);
        $this->assertSame(11750, $waste->amount);
        $this->assertCount(2, $waste->payload['products']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('asab:demo-topup --dry-run')->assertExitCode(0);

        $this->assertNull(AsabUser::withoutGlobalScopes()->where('email', 'branch@nakhat.sa')->first());
        $this->assertSame(0, Operation::withoutGlobalScopes()->count());
    }

    public function test_it_is_idempotent_and_adds_nothing_on_a_second_run(): void
    {
        $this->artisan('asab:demo-topup')->assertExitCode(0);
        $opCount = Operation::withoutGlobalScopes()->count();
        $userCount = AsabUser::withoutGlobalScopes()->count();

        $this->artisan('asab:demo-topup')->assertExitCode(0);

        $this->assertSame($opCount, Operation::withoutGlobalScopes()->count());
        $this->assertSame($userCount, AsabUser::withoutGlobalScopes()->count());
    }

    /** A manager working today must never have their live shift rewritten. */
    public function test_it_never_touches_a_live_manager_day(): void
    {
        $live = BranchManagerShift::firstOrCreate([
            'branch_manager_id' => $this->manager->id,
            'shift_date' => today(),
        ], [
            'branch_id' => $this->branch->id,
        ]);
        $live->update([
            'status' => 'active',
            'actual_start_time' => now()->subHour(),
        ]);

        $this->artisan('asab:demo-topup')->assertExitCode(0);

        $this->assertSame('active', $live->fresh()->status);
        $this->assertNull($live->fresh()->actual_end_time);
        $this->assertFalse(Operation::withoutGlobalScopes()->where('module_key', 'sales')->exists());
    }

    /** An existing branch login keeps its own password — a demo top-up is not a reset. */
    public function test_it_does_not_reset_an_existing_branch_login(): void
    {
        $existing = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'بوابة قائمة', 'email' => 'branch@nakhat.sa',
            'password' => 'a-real-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $existing->id, 'role_key' => 'branch', 'scope' => 'branch',
            'brand_ids' => [], 'restaurant_ids' => [], 'branch_ids' => [$this->branch->id], 'module_keys' => ['sales'],
        ]);
        $hash = $existing->password;

        $this->artisan('asab:demo-topup')->assertExitCode(0);

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame('بوابة قائمة', $existing->fresh()->name);
    }
}
