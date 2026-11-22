<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyTransaction;
use Tests\TestCase;

class BranchCustodyDashboardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_cash_custody_includes_branch_ledger_and_enforces_scope(): void
    {
        $company = AsabCompany::create(['name' => 'Co A', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand A', 'status' => 'active']);
        $branch = Branch::factory()->create([
            'asab_company_id' => $company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Riyadh Main',
        ]);
        $manager = BranchManager::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Mgr A',
            'is_active' => true,
            'status' => 'active',
        ]);

        CustodyTransaction::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'type' => 'Bank Transfer',
            'amount' => '100.00',
            'is_cash_in' => true,
            'transaction_date' => now()->subMinute(),
            'notes' => 'Funding receipt',
        ]);
        CustodyTransaction::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'type' => 'Expenses Deduction',
            'amount' => '25.00',
            'is_cash_in' => false,
            'transaction_date' => now(),
            'notes' => 'Expense submit',
        ]);

        $accountant = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Acct A',
            'email' => 'acct-a@int-cash.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'brand',
            'brand_ids' => [$brand->id],
            'branch_ids' => [$branch->id],
        ]);

        $list = $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->assertJsonPath('meta.kpis.activeCustodies', 1)
            ->assertJsonPath('data.0.id', 'branch-custody-'.$branch->id)
            ->assertJsonPath('data.0.amountHalalas', 10000)
            ->assertJsonPath('data.0.usedHalalas', 2500)
            ->assertJsonPath('data.0.remainingHalalas', 7500);

        $this->assertSame(1, $list->json('meta.total'));

        $this->actingAs($accountant, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/branch-custody-{$branch->id}/transactions")
            ->assertOk()
            ->assertJsonPath('custody.currentBalanceHalalas', 7500)
            ->assertJsonCount(2, 'transactions');

        $this->actingAs($accountant, 'sanctum')
            ->get("/api/v1/company/me/cash-custody/branch-custody-{$branch->id}/transactions/export?format=csv")
            ->assertOk();

        // Cross-tenant isolation: foreign company accountant must see 0 custodies and 404 on ledger.
        $otherCompany = AsabCompany::create(['name' => 'Co B', 'plan' => 'Professional', 'status' => 'active']);
        $otherBrand = AsabBrand::create(['company_id' => $otherCompany->id, 'name' => 'Brand B', 'status' => 'active']);
        $foreignAccountant = AsabUser::create([
            'company_id' => $otherCompany->id,
            'name' => 'Acct B',
            'email' => 'acct-b@int-cash.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        CompanyUser::create([
            'company_id' => $otherCompany->id,
            'user_id' => $foreignAccountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $foreignAccountant->id,
            'role_key' => 'accountant',
            'scope' => 'brand',
            'brand_ids' => [$otherBrand->id],
            'branch_ids' => [],
        ]);

        $this->actingAs($foreignAccountant, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($foreignAccountant, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/branch-custody-{$branch->id}/transactions")
            ->assertNotFound();
    }
}
