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

    /**
     * 2026-08-05 «الأصناف اللي موجودة أصناف المبيعات، الصحيح أصناف المشتريات»:
     * the جرد picker must serve PURCHASE items with no `type` parameter at all.
     */
    public function test_the_catalog_defaults_to_purchase_items(): void
    {
        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/catalog?brandId={$this->brand->id}")
            ->assertSuccessful()
            ->assertJsonPath('type', InventoryCatalogItem::TYPE_RAW_MATERIAL)
            ->assertJsonPath('total', 3);

        $this->assertSame(
            [InventoryCatalogItem::TYPE_RAW_MATERIAL],
            collect($response->json('items'))->pluck('type')->unique()->all(),
        );
        // …and no menu category leaks into the picker.
        $this->assertSame(['ألبان', 'زيوت', 'لحوم'], $response->json('categories'));
    }

    /** An unknown type falls back to the purchase sheet, never to an empty list. */
    public function test_an_unknown_type_falls_back_to_purchase_items(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/catalog?brandId={$this->brand->id}&type=nonsense")
            ->assertSuccessful()
            ->assertJsonPath('type', InventoryCatalogItem::TYPE_RAW_MATERIAL)
            ->assertJsonPath('total', 3);
    }

    /**
     * The column default is `sales_item`: an item added from the جرد picker used
     * to land on the menu sheet and disappear from the list that created it.
     */
    public function test_an_item_added_from_the_picker_is_a_purchase_item(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/accountant/inventory/catalog', [
                'brandId' => $this->brand->id, 'name' => 'أرز بسمتي',
                'category' => 'حبوب', 'unit' => 'كجم',
            ])
            ->assertCreated()
            ->assertJsonPath('type', InventoryCatalogItem::TYPE_RAW_MATERIAL);

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/catalog?brandId={$this->brand->id}")
            ->assertSuccessful()
            ->assertJsonPath('total', 4);
    }

    /** …and an explicit sales_item still reaches the menu sheet. */
    public function test_the_picker_can_still_add_a_menu_item_explicitly(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/accountant/inventory/catalog', [
                'brandId' => $this->brand->id, 'name' => 'آيس كوفي',
                'category' => 'مشروبات', 'unit' => 'حبة', 'type' => 'sales_item',
            ])
            ->assertCreated()
            ->assertJsonPath('type', InventoryCatalogItem::TYPE_SALES_ITEM);

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/catalog?brandId={$this->brand->id}")
            ->assertSuccessful()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('counts.salesItem', 3);
    }

    /**
     * A purchase item whose name collides with a menu item must be created, not
     * silently resolved to the menu row (bulk `PUT inventory/catalog`).
     */
    public function test_bulk_catalog_create_does_not_reuse_a_menu_row_of_the_same_name(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->putJson('/api/v1/company/me/inventory/catalog', [
                'branchId' => $this->branch->id,
                'items' => [['name' => 'بطاطس مقلية', 'category' => 'أطعمة', 'unit' => 'كجم']],
            ])
            ->assertSuccessful();

        $rows = InventoryCatalogItem::where('brand_id', $this->brand->id)
            ->where('name', 'بطاطس مقلية')->get();

        $this->assertCount(2, $rows, 'the menu row must not absorb the purchase item');
        $this->assertSame(
            [InventoryCatalogItem::TYPE_RAW_MATERIAL],
            $rows->where('unit', 'كجم')->pluck('type')->all(),
        );
    }

    /**
     * 2026-08-09 «اسم الفرع غير صحيح — بيبعت الـid»: the monthly-review row
     * carried `branchId` only, so the screen printed the raw UUID.
     */
    public function test_the_review_row_carries_the_branch_name(): void
    {
        \Modules\Admin\Models\Operation::create([
            'company_id' => $this->accountant->company_id,
            'branch_id' => $this->branch->id,
            'module_key' => 'inventory',
            'public_id' => 'INV-DOC-1',
            'status' => 'pending',
            'operation_date' => now(),
            'amount' => 0,
            'origin' => 'mobile',
            'payload' => ['items' => [['itemId' => 'i-1', 'name' => 'صدور دجاج', 'actualQty' => 5]]],
        ]);

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/accountant/inventory?type=monthly')
            ->assertSuccessful()
            ->assertJsonPath('branches.0.branchId', $this->branch->id)
            ->assertJsonPath('branches.0.branchName', 'الريان 1');
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
