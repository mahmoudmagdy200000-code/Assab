<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * The «الفرع» and «العلامة التجارية» dropdowns of every scoped screen
 * (reported 2026-08-14 on the accountant's المصروفات page: both came back
 * empty / wrong).
 *
 * `lookups/branches` reads the LEGACY `branches` table, which carries no tenant
 * scope, so it used to hand every caller every branch on the platform — a
 * cross-company leak as well as a filter whose options did not match the rows
 * the screen could load. `lookups/brands` was scoped to the company but not to
 * the caller's ASSIGNMENT.
 */
class ScopedFilterLookupsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brandA;

    private AsabBrand $brandB;

    private Branch $branchA;

    private Branch $branchB;

    private Branch $foreignBranch;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Lookup Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند أ', 'status' => 'active']);
        $this->brandB = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند ب', 'status' => 'active']);

        $this->branchA = Branch::factory()->create([
            'name' => 'فرع أ', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id,
        ]);
        $this->branchB = Branch::factory()->create([
            'name' => 'فرع ب', 'asab_brand_id' => $this->brandB->id, 'asab_company_id' => $this->company->id,
        ]);

        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $this->foreignBranch = Branch::factory()->create(['name' => 'فرع شركة أخرى', 'asab_company_id' => $other->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب أ', 'email' => 'acc-lookup@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant',
            'scope' => 'brand', 'brand_ids' => [$this->brandA->id],
        ]);
    }

    private function asAccountant()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    public function test_the_brand_filter_offers_only_the_accountant_own_brands(): void
    {
        $res = $this->asAccountant()->getJson('/api/v1/company/me/lookups/brands')->assertStatus(200);

        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertSame(['براند أ'], $names, 'a brand the accountant does not cover must not be offered');
    }

    public function test_the_branch_filter_offers_only_the_assigned_branches(): void
    {
        $res = $this->asAccountant()->getJson('/api/v1/company/me/lookups/branches')->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$this->branchA->id], $ids);
        // Neither the sibling brand's branch nor another company's leaks in.
        $this->assertNotContains($this->branchB->id, $ids);
        $this->assertNotContains($this->foreignBranch->id, $ids);
    }

    public function test_the_branch_rows_carry_their_brand_even_when_linked_through_a_restaurant(): void
    {
        $restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brandA->id, 'name' => 'مطعم أ',
        ]);
        $viaRestaurant = Branch::factory()->create([
            'name' => 'فرع عبر المطعم',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null,
            'asab_restaurant_id' => $restaurant->id,
        ]);

        $rows = collect($this->asAccountant()
            ->getJson('/api/v1/company/me/lookups/branches')->assertStatus(200)->json('data'));

        $row = $rows->firstWhere('id', $viaRestaurant->id);
        $this->assertNotNull($row, 'a restaurant-linked branch belongs to the accountant too');
        $this->assertSame($this->brandA->id, $row['brandId'], 'the brand is derived through the restaurant');
    }

    public function test_the_brand_id_filter_narrows_the_branch_list(): void
    {
        $rows = $this->asAccountant()
            ->getJson('/api/v1/company/me/lookups/branches?brandId='.$this->brandB->id)
            ->assertStatus(200)->json('data');

        // Narrows only — it can never widen past the assignment.
        $this->assertSame([], $rows);
    }

    public function test_a_head_with_company_wide_scope_still_sees_only_their_company(): void
    {
        $head = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'رئيس', 'email' => 'head-lookup@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $head->id, 'role_key' => 'head', 'scope' => 'all']);

        $ids = collect($this->actingAs($head, 'sanctum')
            ->getJson('/api/v1/company/me/lookups/branches')->assertStatus(200)->json('data'))
            ->pluck('id')->all();

        $this->assertContains($this->branchA->id, $ids);
        $this->assertContains($this->branchB->id, $ids);
        $this->assertNotContains($this->foreignBranch->id, $ids, 'another company must never appear');
    }
}
