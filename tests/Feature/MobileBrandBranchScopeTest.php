<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Shift\Models\Shift;
use Tests\Concerns\LinksMobileBrandScope;
use Tests\TestCase;

/**
 * Brand isolation on the mobile branch pickers: the add-cashier branch list (and
 * the brand-owner branch selectors) used to return every brand's branches.
 */
class MobileBrandBranchScopeTest extends TestCase
{
    use LinksMobileBrandScope, RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $ownBrand;

    private Branch $ownBranch;

    private Branch $siblingBranch;

    private Branch $foreignBranch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Scope Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->ownBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'برجر بيت', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $foreignBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'شاورما الأصيل', 'sub_status' => 'active', 'status' => 'active',
        ]);

        $this->ownBranch = Branch::factory()->create(['name' => 'فرع التحلية']);
        $this->siblingBranch = Branch::factory()->create(['name' => 'فرع العليا']);
        $this->foreignBranch = Branch::factory()->create(['name' => 'فرع الروضة']);

        $this->tagBranchesWithBrand($this->ownBrand, $this->ownBranch, $this->siblingBranch);
        $this->tagBranchesWithBrand($foreignBrand, $this->foreignBranch);

        $this->manager = BranchManager::factory()->create(['branch_id' => $this->ownBranch->id]);
    }

    public function test_branch_picker_returns_only_the_managers_own_brand(): void
    {
        $res = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/branches');
        $res->assertOk();

        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('فرع التحلية', $names);
        $this->assertContains('فرع العليا', $names);
        $this->assertNotContains('فرع الروضة', $names);
    }

    public function test_adding_a_cashier_to_another_brands_branch_is_rejected(): void
    {
        $shift = Shift::factory()->create(['branch_id' => $this->foreignBranch->id]);

        $res = $this->actingAs($this->manager, 'sanctum')->postJson('/api/branch-manager/cashiers', [
            'name' => 'كاشير',
            'email' => 'intruder@asab.test',
            'store_branch_id' => $this->foreignBranch->id,
            'shift_ids' => [$shift->id],
        ]);

        $res->assertStatus(403);
        $this->assertDatabaseMissing('cashiers', ['email' => 'intruder@asab.test']);
    }

    public function test_brand_owner_branch_list_excludes_other_brands(): void
    {
        $owner = BrandOwner::create([
            'name' => 'Owner One',
            'email' => 'owner@example.com',
            'phone' => '0500000000',
            'password' => 'secret-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);
        $brand = $this->linkBrandOwner($owner, $this->ownBranch, $this->siblingBranch);
        $this->assertNotSame($brand->id, $this->foreignBranch->asab_brand_id);

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/brand-owner/dashboard/branches');
        $res->assertOk();

        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('فرع التحلية', $names);
        $this->assertNotContains('فرع الروضة', $names);
    }

    public function test_an_unlinked_brand_owner_sees_nothing_rather_than_every_brand(): void
    {
        $owner = BrandOwner::create([
            'name' => 'Owner Two',
            'email' => 'owner2@example.com',
            'phone' => '0500000001',
            'password' => 'secret-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/brand-owner/dashboard/branches');

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
