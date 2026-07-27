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
 * «مصدر الفروع غير المربوطة» — reported by the frontend 2026-07-26.
 *
 * The brand tree only walks restaurants, so a branch whose `asab_restaurant_id`
 * is NULL never appears in any response — the id needed to PATCH it into a
 * restaurant did not exist anywhere in the API, which made the «اربط الفروع»
 * button (and the branchesLinked=0 warning that asks for it) unbuildable.
 */
class BrandUnlinkedBranchesTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@linking.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'Link Co', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'حية عنب', 'abbr' => 'HA',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'الخرج', 'status' => 'active',
        ]);
    }

    private function fetch(string $url)
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson($url);
    }

    public function test_unlinked_endpoint_returns_brand_stamped_and_orphan_branches_but_not_linked_ones(): void
    {
        $linked = Branch::factory()->create([
            'name' => 'فرع مربوط',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
        $brandOnly = Branch::factory()->create([
            'name' => 'فرع بالبراند بس', 'city' => 'الرياض',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => null,
        ]);
        $orphan = Branch::factory()->create([
            'name' => 'فرع قديم',
            'asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null,
        ]);

        $res = $this->fetch("/api/v1/admin/brands/{$this->brand->id}/branches?linked=false");
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($brandOnly->id, $ids);
        $this->assertContains($orphan->id, $ids);
        $this->assertNotContains($linked->id, $ids);

        $byId = collect($res->json('data'))->keyBy('id');
        $this->assertSame('brand', $byId[$brandOnly->id]['linkage']);
        $this->assertSame('orphan', $byId[$orphan->id]['linkage']);
        $this->assertSame('الرياض', $byId[$brandOnly->id]['city']);

        // The restaurant picker's options ride along in meta — no second call.
        $res->assertJsonPath('meta.linked', 'false');
        $res->assertJsonPath('meta.restaurants.0.id', $this->restaurant->id);
    }

    public function test_unlinked_branches_alias_matches_the_query_filter(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'فرع بلا مطعم',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => null,
        ]);

        $alias = $this->fetch("/api/v1/admin/brands/{$this->brand->id}/unlinked-branches");
        $alias->assertOk()->assertJsonPath('data.0.id', $branch->id);
        $alias->assertJsonPath('meta.linked', 'false');
    }

    public function test_another_companys_branch_is_never_offered_as_a_link_candidate(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $otherBrand = AsabBrand::create([
            'company_id' => $otherCompany->id, 'name' => 'براند تاني', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $foreign = Branch::factory()->create([
            'name' => 'فرع شركة تانية',
            'asab_company_id' => $otherCompany->id,
            'asab_brand_id' => $otherBrand->id,
            'asab_restaurant_id' => null,
        ]);

        $res = $this->fetch("/api/v1/admin/brands/{$this->brand->id}/branches?linked=false");

        $this->assertNotContains($foreign->id, collect($res->json('data'))->pluck('id')->all());
    }

    public function test_linked_true_returns_only_branches_under_the_brands_restaurants(): void
    {
        $linked = Branch::factory()->create([
            'name' => 'فرع مربوط',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
        Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => null,
        ]);

        $res = $this->fetch("/api/v1/admin/brands/{$this->brand->id}/branches?linked=true");

        $res->assertOk()->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.id', $linked->id);
        $res->assertJsonPath('data.0.linkage', 'linked');
        $res->assertJsonPath('data.0.restaurantName', 'الخرج');
    }

    public function test_brand_tree_exposes_unlinked_branches_and_counts(): void
    {
        Branch::factory()->create([
            'name' => 'فرع مربوط',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
        $unlinked = Branch::factory()->create([
            'name' => 'فرع غير مربوط',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => null,
        ]);

        $res = $this->fetch('/api/v1/admin/brands');
        $res->assertOk();

        $brand = collect($res->json('data'))->firstWhere('id', $this->brand->id);
        $this->assertSame([$unlinked->id], collect($brand['unlinkedBranches'])->pluck('id')->all());
        $this->assertSame(['linked' => 1, 'unlinked' => 1], $brand['branchCounts']);
    }

    public function test_patching_restaurant_id_links_the_branch_and_stamps_the_whole_chain(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null,
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')->patchJson(
            "/api/v1/admin/branches/{$branch->id}",
            ['restaurantId' => $this->restaurant->id],
        );

        $res->assertOk()->assertJsonPath('restaurantId', $this->restaurant->id);
        $branch->refresh();
        $this->assertSame($this->restaurant->id, $branch->asab_restaurant_id);
        $this->assertSame($this->brand->id, $branch->asab_brand_id);
        $this->assertSame($this->company->id, $branch->asab_company_id);

        // Gone from the candidate list, present in the linked one.
        $after = $this->fetch("/api/v1/admin/brands/{$this->brand->id}/branches?linked=false");
        $this->assertNotContains($branch->id, collect($after->json('data'))->pluck('id')->all());
    }
}
