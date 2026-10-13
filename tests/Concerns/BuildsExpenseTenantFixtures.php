<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Expense\Models\Expense;

/**
 * Two-company / multi-brand fixtures for the expense tenant-isolation tests (EXP-06).
 *
 *   Company A ── Brand A1 ── Branch A1 ── Branch manager A1 ── expenses
 *            └── Brand A2 ── Branch A2 ── Branch manager A2 ── expenses
 *   Company B ── Brand B1 ── Branch B1 ── Branch manager B1 ── expenses
 *
 * The mobile `brand_owners` / `brand_managers` tables carry no brand column. The
 * only brand-assignment source in the repository is the dashboard side
 * (MobileBranchScopeService): identity map or same e-mail -> asab_users ->
 * asab_user_roles(brand-owner).brand_ids. Fixtures therefore express an
 * "assignment" exactly that way, and an actor with no such link is unassigned.
 */
trait BuildsExpenseTenantFixtures
{
    protected AsabCompany $companyA;

    protected AsabCompany $companyB;

    protected AsabBrand $brandA1;

    protected AsabBrand $brandA2;

    protected AsabBrand $brandB1;

    protected Branch $branchA1;

    protected Branch $branchA2;

    protected Branch $branchB1;

    protected BranchManager $bmA1;

    protected BranchManager $bmA2;

    protected BranchManager $bmB1;

    /** Owner of brand A1 only. */
    protected BrandOwner $ownerA1;

    /** Owner of brands A1 and A2 (legitimate multi-brand ownership). */
    protected BrandOwner $ownerA12;

    /** Owner of brand B1 (the other company). */
    protected BrandOwner $ownerB1;

    /** Active owner with NO dashboard link at all (fail-closed). */
    protected BrandOwner $ownerUnlinked;

    /** Brand manager assigned to brand A1 only. */
    protected BrandManager $managerA1;

    /** Brand manager with no brand assignment. */
    protected BrandManager $managerUnassigned;

    protected function buildExpenseTenants(): void
    {
        $this->companyA = AsabCompany::create(['name' => 'Tenant A Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'Tenant B Co', 'plan' => 'Professional', 'status' => 'active']);

        $this->brandA1 = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A1', 'abbr' => 'A1', 'status' => 'active']);
        $this->brandA2 = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A2', 'abbr' => 'A2', 'status' => 'active']);
        $this->brandB1 = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'Brand B1', 'abbr' => 'B1', 'status' => 'active']);

        $this->branchA1 = Branch::factory()->create(['name' => 'Branch A1', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA1->id]);
        $this->branchA2 = Branch::factory()->create(['name' => 'Branch A2', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA2->id]);
        $this->branchB1 = Branch::factory()->create(['name' => 'Branch B1', 'asab_company_id' => $this->companyB->id, 'asab_brand_id' => $this->brandB1->id]);

        $this->bmA1 = BranchManager::factory()->create(['branch_id' => $this->branchA1->id]);
        $this->bmA2 = BranchManager::factory()->create(['branch_id' => $this->branchA2->id]);
        $this->bmB1 = BranchManager::factory()->create(['branch_id' => $this->branchB1->id]);

        $this->ownerA1 = $this->makeBrandOwner('owner.a1@tenant.test', 'Owner A1');
        $this->ownerA12 = $this->makeBrandOwner('owner.a12@tenant.test', 'Owner A1+A2');
        $this->ownerB1 = $this->makeBrandOwner('owner.b1@tenant.test', 'Owner B1');
        $this->ownerUnlinked = $this->makeBrandOwner('owner.none@tenant.test', 'Owner Unlinked');

        $this->linkToBrands($this->ownerA1, $this->companyA, [$this->brandA1->id], withIdentityMap: true);
        $this->linkToBrands($this->ownerA12, $this->companyA, [$this->brandA1->id, $this->brandA2->id], withIdentityMap: true);
        $this->linkToBrands($this->ownerB1, $this->companyB, [$this->brandB1->id], withIdentityMap: true);

        $this->managerA1 = $this->makeBrandManager('manager.a1@tenant.test', 'Manager A1');
        $this->managerUnassigned = $this->makeBrandManager('manager.none@tenant.test', 'Manager Unassigned');
        // D-2: a brand manager is authorised ONLY through an explicit per-manager
        // delegation (brand_manager identity map + brand-manager role), never by
        // e-mail match to a brand owner. managerUnassigned gets none (fail closed).
        $this->assignBrandManager($this->managerA1, $this->companyA, [$this->brandA1->id]);
    }

    protected function makeBrandOwner(string $email, string $name): BrandOwner
    {
        return BrandOwner::create([
            'name' => $name, 'email' => $email, 'phone' => '05'.random_int(10000000, 99999999),
            'password' => 'secret-password', 'is_active' => true, 'status' => 'active',
        ]);
    }

    protected function makeBrandManager(string $email, string $name): BrandManager
    {
        return BrandManager::create([
            'name' => $name, 'email' => $email, 'phone' => '05'.random_int(10000000, 99999999),
            'password' => 'secret-password', 'is_active' => true, 'status' => 'active',
        ]);
    }

    /**
     * Assign a mobile brand actor to dashboard brands (the repository's only assignment source).
     *
     * @param  string[]  $brandIds
     */
    protected function linkToBrands(BrandOwner $actor, AsabCompany $company, array $brandIds, bool $withIdentityMap): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $company->id, 'name' => $actor->name, 'email' => $actor->email,
            'password' => 'scope-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'brand-owner', 'scope' => 'brand', 'brand_ids' => $brandIds]);

        if ($withIdentityMap) {
            AsabIdentityMap::create([
                'company_id' => $company->id, 'entity_type' => AsabIdentityMap::ENTITY_BRAND_OWNER,
                'dashboard_type' => 'asab_user', 'dashboard_id' => $user->id, 'legacy_type' => 'brand_owner',
                'legacy_id' => $actor->id, 'match_method' => 'email', 'linked_email' => $actor->email,
                'source' => 'test', 'linked_at' => now(),
            ]);
        }

        return $user;
    }

    /**
     * D-2 explicit brand-manager delegation: a verified brand_manager identity
     * map to a dashboard user holding a brand-manager role with the given brands.
     *
     * @param  string[]  $brandIds
     */
    protected function assignBrandManager(BrandManager $manager, AsabCompany $company, array $brandIds): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $company->id, 'name' => $manager->name, 'email' => $manager->email,
            'password' => 'scope-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'brand-manager', 'scope' => 'brand', 'brand_ids' => $brandIds]);
        AsabIdentityMap::create([
            'company_id' => $company->id, 'entity_type' => AsabIdentityMap::ENTITY_BRAND_MANAGER,
            'dashboard_type' => 'asab_user', 'dashboard_id' => $user->id, 'legacy_type' => 'brand_manager',
            'legacy_id' => $manager->id, 'match_method' => 'explicit', 'linked_email' => $manager->email,
            'source' => 'test', 'linked_at' => now(),
        ]);

        return $user;
    }

    /** Revoke a brand-manager delegation (delete the map + role) — fail-closed on the next request. */
    protected function revokeBrandManager(BrandManager $manager): void
    {
        $userId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $manager->id)->value('dashboard_id');
        AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $manager->id)->delete();
        if ($userId) {
            AsabUserRole::where('user_id', $userId)->where('role_key', 'brand-manager')->delete();
        }
    }

    /** A submitted (pending) mobile expense plus its read-only dashboard mirror operation. */
    protected function pendingExpense(BranchManager $manager, array $overrides = []): Expense
    {
        $expense = Expense::create(array_merge([
            'branch_manager_id' => $manager->id, 'expense_type' => 'quick_cash', 'status' => 'pending',
            'submitted_at' => now(), 'total_amount' => 400.00, 'net_amount' => 400.00, 'vat_amount' => 0.00,
            'payment_method' => 'supplier',
        ], $overrides));
        app(ExpenseBridgeService::class)->sync($expense);

        return $expense->fresh();
    }

    /** Every table a decision may write to: md5 over its rows, so "zero writes" is exact. */
    protected function writeSnapshot(): array
    {
        $tables = [
            'expenses', 'expense_timelines', 'asab_operations', 'asab_approval_steps', 'asab_notifications',
            'custody_transactions', 'personal_ledger_transactions', 'notification_logs', 'notifications', 'jobs',
        ];
        $out = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy('id')->values()->toJson();
            $out[$table] = md5($rows).':'.DB::table($table)->count();
        }

        return $out;
    }
}
