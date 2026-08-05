<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Meeting 2026-07-30 «العلامات التجارية مش راجعة» — the accountant's
 * تحديد الأصناف للجرد screen: brand pills → branch pills → items.
 */
class InventoryBrandSelectionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabBrand $otherBrand;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->otherBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'شاورما هاوس', 'abbr' => 'SH',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'برجر', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active',
        ]);
    }

    private function accountant(array $role): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب برجر بيت',
            'email' => uniqid().'@test.sa',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(array_merge(['user_id' => $user->id, 'role_key' => 'accountant'], $role));

        return $user;
    }

    public function test_brand_scoped_accountant_sees_only_their_brand_pills(): void
    {
        $accountant = $this->accountant(['scope' => 'brand', 'brand_ids' => [$this->brand->id]]);

        $res = $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/accountant/inventory/brands');

        $res->assertStatus(200);
        $rows = $res->json();
        $this->assertCount(1, $rows);
        $this->assertSame('برجر بيت', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['branchCount']);
        $this->assertSame(1, $rows[0]['itemCount']);
    }

    public function test_scope_all_accountant_sees_every_company_brand(): void
    {
        $accountant = $this->accountant(['scope' => 'all']);

        $res = $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/accountant/inventory/brands');

        $res->assertStatus(200)->assertJsonCount(2);
    }

    public function test_brand_branches_returns_the_brand_branch_pills(): void
    {
        $accountant = $this->accountant(['scope' => 'all']);

        $res = $this->actingAs($accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/brands/{$this->brand->id}/branches");

        $res->assertStatus(200)->assertJsonCount(1);
        $this->assertSame($this->branch->id, $res->json('0.id'));
    }

    public function test_foreign_brand_branches_is_404(): void
    {
        $accountant = $this->accountant(['scope' => 'brand', 'brand_ids' => [$this->brand->id]]);

        $this->actingAs($accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/brands/{$this->otherBrand->id}/branches")
            ->assertStatus(404);
    }

    public function test_restaurant_scoped_accountant_with_unlinked_branches_still_resolves_the_brand(): void
    {
        // Branch linked only through its restaurant (asab_brand_id NULL) —
        // catalog reads used to fail closed to empty for this accountant.
        $restaurant = AsabRestaurant::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم التحلية',
        ]);
        Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null,
            'asab_restaurant_id' => $restaurant->id,
        ]);
        $accountant = $this->accountant(['scope' => 'restaurant', 'restaurant_ids' => [$restaurant->id]]);

        $res = $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/accountant/inventory/catalog?type=all');

        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('items'), 'restaurant-scoped accountant must see the brand catalog');
    }
}
