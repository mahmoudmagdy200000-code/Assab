<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Category;
use Tests\TestCase;

/**
 * Meeting gap «اختار معدات → يجيب التلاجة والبوتاجاز»: choosing a category on
 * the mobile Item List screen must list the brand catalog items uploaded from
 * the dashboard sheet (tagged by «التصنيف»).
 */
class ExpenseCategoryItemsTest extends TestCase
{
    use RefreshDatabase;

    private function brandWithBranch(): array
    {
        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;
        $brand = AsabBrand::create([
            'company_id' => $companyId, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $companyId]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        return [$brand, $branch, $manager];
    }

    public function test_category_lists_the_brand_catalog_items_tagged_with_its_name(): void
    {
        [$brand, , $manager] = $this->brandWithBranch();

        $meat = Category::create(['name' => 'لحوم', 'type' => 'purchase', 'is_active' => true]);

        foreach ([['لحمة', 'RM-1', 1000], ['دجاج', 'RM-2', 2000], ['سمك', 'RM-3', 3000]] as [$name, $code, $price]) {
            InventoryCatalogItem::create([
                'brand_id' => $brand->id, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
                'name' => $name, 'code' => $code, 'category' => 'لحوم',
                'unit' => 'KG', 'unit_price' => $price, 'status' => 'active',
            ]);
        }
        // Another category's item must not leak in.
        InventoryCatalogItem::create([
            'brand_id' => $brand->id, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
            'name' => 'عيش', 'category' => 'مخبوزات', 'unit' => 'PCS', 'unit_price' => 500, 'status' => 'active',
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/expenses/categories/{$meat->id}/items");

        $response->assertStatus(200)
            ->assertJsonPath('data.category.name', 'لحوم')
            ->assertJsonCount(3, 'data.items');

        $names = collect($response->json('data.items'))->pluck('name')->all();
        $this->assertSame(['دجاج', 'سمك', 'لحمة'], $names);
        $this->assertEquals(10.0, $response->json('data.items.2.price'));
    }

    public function test_parent_category_includes_items_tagged_with_its_children_names(): void
    {
        [$brand, , $manager] = $this->brandWithBranch();

        $parent = Category::create(['name' => 'مواد غذائية', 'type' => 'purchase', 'is_active' => true]);
        Category::create(['name' => 'لحوم', 'type' => 'purchase', 'parent_id' => $parent->id, 'is_active' => true]);

        InventoryCatalogItem::create([
            'brand_id' => $brand->id, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
            'name' => 'لحمة', 'category' => 'لحوم', 'unit' => 'KG', 'unit_price' => 1000, 'status' => 'active',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/expenses/categories/{$parent->id}/items")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'لحمة');
    }

    public function test_unlinked_branch_gets_an_empty_fail_closed_list(): void
    {
        $branch = Branch::factory()->create(['asab_brand_id' => null]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cat = Category::create(['name' => 'لحوم', 'type' => 'purchase', 'is_active' => true]);

        $otherBrandCompany = AsabCompany::create(['name' => 'X', 'plan' => 'Basic', 'status' => 'active'])->id;
        $otherBrand = AsabBrand::create([
            'company_id' => $otherBrandCompany, 'name' => 'Other', 'abbr' => 'OT',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        InventoryCatalogItem::create([
            'brand_id' => $otherBrand->id, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
            'name' => 'لحمة', 'category' => 'لحوم', 'unit' => 'KG', 'unit_price' => 1000, 'status' => 'active',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/expenses/categories/{$cat->id}/items")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');
    }
}
