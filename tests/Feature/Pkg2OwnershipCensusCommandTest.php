<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Expense;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * The read-only PKG-2 census command runs and reports the FU-02 / D-2
 * remediation inventory without mutating anything.
 */
class Pkg2OwnershipCensusCommandTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    public function test_census_runs_and_reports_counts(): void
    {
        $this->buildExpenseTenants();
        // An expense with a proper origin, and a manager with no explicit delegation.
        $this->pendingExpense($this->bmA1, ['status' => 'approved', 'total_amount' => 10, 'net_amount' => 10, 'vat_amount' => 0]);

        $this->artisan('asab:pkg2-census', ['--json' => true])->assertExitCode(0);

        // managerUnassigned exists without a brand_manager delegation → flagged.
        $this->artisan('asab:pkg2-census')
            ->expectsOutputToContain('d2_brand_managers_without_explicit_assignment')
            ->assertExitCode(0);
    }

    public function test_census_flags_partial_origin_and_ineffective_delegation(): void
    {
        $this->buildExpenseTenants();

        // FU-02 partial origin: an expense created at a branch that is NOT linked
        // to the dashboard hierarchy — origin BRANCH is stamped, origin
        // BRAND/company stay NULL, so it is brand-invisible.
        $unbranded = Branch::factory()->create(['asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null]);
        $bmU = BranchManager::factory()->create(['branch_id' => $unbranded->id]);
        $partial = Expense::create([
            'branch_manager_id' => $bmU->id, 'expense_type' => 'quick_cash', 'status' => 'approved',
            'total_amount' => 5, 'net_amount' => 5, 'vat_amount' => 0, 'payment_method' => 'supplier',
        ]);
        $this->assertSame($unbranded->id, $partial->origin_branch_id);
        $this->assertNull($partial->origin_asab_brand_id);

        // D-2 ineffective delegation: a live map whose dashboard user is disabled
        // resolves to NO brands, yet exists (so it is not "without assignment").
        $manager = $this->makeBrandManager('ineffective@tenant.test', 'Ineffective Manager');
        $dashUser = $this->assignBrandManager($manager, $this->companyA, [$this->brandA1->id]);
        $dashUser->forceFill(['status' => 'suspended'])->save();

        Artisan::call('asab:pkg2-census', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $report['fu02_expenses_partial_origin']);
        $this->assertGreaterThanOrEqual(1, $report['d2_brand_managers_ineffective_delegation']);
    }

    public function test_census_governs_orphaned_brand_manager_roles(): void
    {
        $this->buildExpenseTenants();

        // A brand-manager ROLE row not backed by any live delegation (historical /
        // out-of-band residue) — e.g. a dashboard user that carries the role but
        // has no brand_manager identity map pointing at them.
        $strayUser = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Stray', 'email' => 'stray.role@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);
        \Modules\Admin\Models\AsabUserRole::create([
            'user_id' => $strayUser->id, 'role_key' => 'brand-manager', 'scope' => 'brand',
            'brand_ids' => [$this->brandA1->id],
        ]);

        Artisan::call('asab:pkg2-census', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertGreaterThanOrEqual(1, $report['d2_orphaned_brand_manager_roles']);
    }
}
