<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\CashCustody;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyTransaction;
use Illuminate\Testing\TestResponse;
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

    public function test_register_paginates_both_sources_and_exports_the_live_balance_once(): void
    {
        [$company, $brand, $accountant] = $this->registerActor();
        [$legacyBranch] = $this->custodyBranch($company, $brand, 'Legacy Only');
        [$mixedBranch, $mixedManager] = $this->custodyBranch($company, $brand, 'Mixed Ledger');
        [$liveBranch, $liveManager] = $this->custodyBranch($company, $brand, 'Live Only');
        $legacyOnly = $this->legacyCustody($company, $legacyBranch, 20000);
        $this->legacyCustody($company, $mixedBranch, 99900);
        $this->movements($mixedBranch, $mixedManager, now(), ['75.30']);
        $this->movements($liveBranch, $liveManager, now(), ['18.42']);

        $this->actingAs($accountant, 'sanctum');
        $expected = [$legacyOnly->id, 'branch-custody-'.$mixedBranch->id, 'branch-custody-'.$liveBranch->id];
        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $response = $this->getJson("/api/v1/company/me/cash-custody?pageSize=1&page={$page}")
                ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.totalPages', 3)
                ->assertJsonPath('meta.kpis.activeCustodies', 3)
                ->assertJsonCount($page === 4 ? 0 : 1, 'data');
            array_push($seen, ...array_column($response->json('data'), 'id'));
        }
        $this->assertEqualsCanonicalizing($expected, $seen);

        $mixedPath = '/api/v1/company/me/cash-custody?branchId='.$mixedBranch->id;
        $this->getJson($mixedPath.'&status=critical&q=Mixed')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.remainingHalalas', 7530)
            ->assertJsonPath('data.0.source', 'branch_custody_ledger')
            ->assertJsonCount(1, 'data.0.transactions');
        $this->getJson($mixedPath.'&status=low')->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.kpis.activeCustodies', 3);
        $this->getJson('/api/v1/company/me/cash-custody/branch-custody-'.$mixedBranch->id.'/transactions')
            ->assertOk()->assertJsonPath('custody.currentBalanceHalalas', 7530);

        $csv = $this->csvRows($this->get('/api/v1/company/me/cash-custody/export?format=csv&q=Mixed')->assertOk());
        $this->assertCount(2, $csv);
        $this->assertSame('Mixed Ledger', $csv[1][0]);
        $this->assertSame('75.30', $csv[1][4]);
    }

    public function test_month_ledger_preserves_running_balances_and_exports_every_movement(): void
    {
        [$company, $brand, $accountant] = $this->registerActor();
        [$branch, $manager] = $this->custodyBranch($company, $brand, 'High Volume');
        $month = Carbon::parse('2026-10-01');
        $this->movements($branch, $manager, $month->copy()->subDay(), ['50.25']);
        $this->movements($branch, $manager, $month->copy()->addDay(), array_fill(0, 2000, '-0.01'));
        $this->movements($branch, $manager, $month->copy()->addDays(2), ['5.27']);
        $this->movements($branch, $manager, $month->copy()->addMonth(), ['7.35']);

        $this->actingAs($accountant, 'sanctum');
        $path = '/api/v1/company/me/cash-custody/branch-custody-'.$branch->id.'/transactions';
        $this->getJson($path.'?month=2026-10&pageSize=200&page=1')->assertOk()
            ->assertJsonPath('meta.total', 2001)
            ->assertJsonPath('meta.totalPages', 11)
            ->assertJsonPath('custody.openingBalanceHalalas', 5025)
            ->assertJsonPath('custody.closingBalanceHalalas', 3552)
            ->assertJsonPath('custody.currentBalanceHalalas', 4287)
            ->assertJsonPath('transactions.0.runningBalanceHalalas', 3552)
            ->assertJsonCount(200, 'transactions');
        $this->getJson($path.'?month=2026-10&pageSize=200&page=11')->assertOk()
            ->assertJsonCount(1, 'transactions')->assertJsonPath('transactions.0.runningBalanceHalalas', 5024);
        $this->getJson($path.'?month=2026-09&page=2')->assertOk()
            ->assertJsonCount(0, 'transactions')->assertJsonPath('custody.closingBalanceHalalas', 5025);

        $csv = $this->csvRows($this->get($path.'/export?format=csv&month=2026-10')->assertOk());
        $this->assertCount(2005, $csv);
        $this->assertSame('50.25', $csv[1][5]);
        $this->assertSame('35.52', $csv[2][5]);
        $this->assertSame('50.24', $csv[2002][5]);
        $this->assertSame('35.52', $csv[2003][5]);
        $this->assertSame('42.87', $csv[2004][5]);
    }

    private function registerActor(): array
    {
        $company = AsabCompany::create(['name' => 'Register Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Register Brand', 'status' => 'active']);
        $accountant = AsabUser::create([
            'company_id' => $company->id, 'name' => 'Register Accountant', 'email' => 'register@int-cash.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $accountant->id, 'role_key' => 'accountant', 'status' => 'active']);
        AsabUserRole::create([
            'user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'brand', 'brand_ids' => [$brand->id],
        ]);

        return [$company, $brand, $accountant];
    }

    private function custodyBranch(AsabCompany $company, AsabBrand $brand, string $name): array
    {
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id, 'name' => $name]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);

        return [$branch, $manager];
    }

    private function legacyCustody(AsabCompany $company, Branch $branch, int $amount): CashCustody
    {
        return CashCustody::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'custodian_name' => 'Former custodian',
            'amount' => $amount, 'used' => 0, 'min_alert' => 0, 'status' => 'active',
        ]);
    }

    private function movements(Branch $branch, BranchManager $manager, Carbon $date, array $amounts): void
    {
        foreach (array_chunk($amounts, 80) as $amountBatch) {
            $rows = array_map(fn (string $amount) => [
                'id' => (string) Str::uuid(),
                'branch_manager_id' => $manager->id,
                'branch_id' => $branch->id,
                'type' => str_starts_with($amount, '-') ? 'Expenses Deduction' : 'Bank Transfer',
                'amount' => ltrim($amount, '-'), 'is_cash_in' => ! str_starts_with($amount, '-'),
                'transaction_date' => $date,
            ], $amountBatch);
            CustodyTransaction::query()->insert($rows);
        }
    }

    private function csvRows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $file = new \SplFileObject($path, 'r');
        try {
            $rows = [];
            while (($row = $file->fgetcsv(',', '"', '')) !== false) {
                if ($row !== [null]) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            unset($file);
            unlink($path);
        }
    }
}
