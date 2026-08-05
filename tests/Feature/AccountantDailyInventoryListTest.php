<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «عدد الأصناف غير صحيح … ومختلفة عن الموجود في الجرد
 * والتصنيفات غير صحيحة»:
 *  - the catalog listed «أصناف المبيعات» only, so a brand with 30 uploaded rows
 *    reported 22 — with MENU categories instead of the raw-material ones;
 *  - and «تحديد أصناف الجرد اليومي» wrote the dashboard list only, never the
 *    mobile schedule the branch actually counts from.
 */
class AccountantDailyInventoryListTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $accountant;

    private AsabBrand $brand;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $company->id, 'name' => 'جورمية كافيه', 'abbr' => 'GC',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'الريان 1', 'asab_company_id' => $company->id, 'asab_brand_id' => $this->brand->id,
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $company->id, 'name' => 'محاسب', 'email' => 'acc@inv.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [$this->brand->id], 'module_keys' => ['inventory'],
        ]);

        // The two uploaded sheets: 2 menu items + 3 raw materials = 5 rows.
        $this->catalogItem('برجر دجاج حار', 'أطعمة', 'حبة', InventoryCatalogItem::TYPE_SALES_ITEM);
        $this->catalogItem('بطاطس مقلية', 'أطعمة', 'حبة', InventoryCatalogItem::TYPE_SALES_ITEM);
        $this->catalogItem('صدور دجاج', 'لحوم', 'كجم', InventoryCatalogItem::TYPE_RAW_MATERIAL);
        $this->catalogItem('جبن شيدر', 'ألبان', 'كجم', InventoryCatalogItem::TYPE_RAW_MATERIAL);
        $this->catalogItem('زيت قلي', 'زيوت', 'لتر', InventoryCatalogItem::TYPE_RAW_MATERIAL);
    }

    private function catalogItem(string $name, string $category, string $unit, string $type): InventoryCatalogItem
    {
        return InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => $type, 'name' => $name,
            'category' => $category, 'unit' => $unit, 'status' => 'active',
        ]);
    }

    public function test_the_brand_pill_counts_every_uploaded_sheet(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/accountant/inventory/brands')
            ->assertSuccessful()
            ->assertJsonPath('0.itemCount', 5)
            ->assertJsonPath('0.salesItemCount', 2)
            ->assertJsonPath('0.rawMaterialCount', 3);
    }

    public function test_the_catalog_can_list_the_raw_materials_the_branch_counts(): void
    {
        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/catalog?brandId={$this->brand->id}&type=raw_material")
            ->assertSuccessful()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('counts.salesItem', 2)
            ->assertJsonPath('counts.rawMaterial', 3)
            ->assertJsonPath('counts.all', 5);

        // Categories belong to the listed sheet — menu categories must not leak
        // into a raw-material list.
        $this->assertSame(['ألبان', 'زيوت', 'لحوم'], $response->json('categories'));
    }

    public function test_type_all_returns_both_sheets(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/catalog?brandId={$this->brand->id}&type=all")
            ->assertSuccessful()
            ->assertJsonPath('total', 5);
    }

    public function test_saving_the_daily_list_reaches_the_apps_count_sheet(): void
    {
        $raw = InventoryCatalogItem::where('type', InventoryCatalogItem::TYPE_RAW_MATERIAL)->pluck('id')->all();

        $this->actingAs($this->accountant, 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => $raw])
            ->assertSuccessful()
            ->assertJsonPath('savedCount', 3)
            ->assertJsonPath('appListCount', 3);

        $schedule = DailyInventorySchedule::where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($schedule, 'the branch had no count sheet to receive the selection');
        $this->assertSame(3, DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $schedule->id)->count());

        // …and the items are visible to the branch pickers (branch_item pivot).
        $this->assertSame(3, BranchItem::where('branch_id', $this->branch->id)->count());
        $this->assertNotNull(PurchaseItem::firstWhere('name', 'صدور دجاج'));
    }

    /** A re-save replaces the sheet instead of appending to it. */
    public function test_re_saving_replaces_the_previous_selection(): void
    {
        $raw = InventoryCatalogItem::where('type', InventoryCatalogItem::TYPE_RAW_MATERIAL)->pluck('id')->all();

        $this->actingAs($this->accountant, 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => $raw])
            ->assertSuccessful();

        $this->actingAs($this->accountant, 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", [
                'items' => [$raw[0]],
            ])
            ->assertSuccessful()
            ->assertJsonPath('appListCount', 1);

        $schedule = DailyInventorySchedule::where('branch_id', $this->branch->id)->firstOrFail();
        $this->assertSame(1, DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $schedule->id)->count());
    }

    /** An existing legacy item is reused, never duplicated. */
    public function test_an_existing_mobile_item_is_reused(): void
    {
        $existing = PurchaseItem::create([
            'name' => 'صدور دجاج', 'unit' => 'كجم', 'category' => 'لحوم', 'is_active' => true,
        ]);
        $raw = InventoryCatalogItem::where('name', 'صدور دجاج')->pluck('id')->all();

        $this->actingAs($this->accountant, 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => $raw])
            ->assertSuccessful()
            ->assertJsonPath('newMobileItems', 0);

        $this->assertSame(1, PurchaseItem::where('name', 'صدور دجاج')->count());
        $this->assertSame(
            $existing->id,
            DailyInventoryScheduleItem::query()->value('item_id'),
        );
    }
}
