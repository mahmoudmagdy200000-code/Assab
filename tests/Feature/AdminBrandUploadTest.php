<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\UploadStatus;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Category;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Modules\Supplier\Models\Supplier as LegacySupplier;
use Tests\TestCase;

/**
 * The per-brand «رفع البيانات» tab: template round-trip, header validation,
 * and the columns the importers used to parse and throw away.
 */
class AdminBrandUploadTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AsabUser::create([
            'name' => 'Platform Admin',
            'email' => 'admin@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
    }

    private function brand(): AsabBrand
    {
        $companyId = AsabCompany::create(['name' => 'Brand Co', 'plan' => 'Basic', 'status' => 'active'])->id;

        return AsabBrand::create([
            'company_id' => $companyId,
            'name' => 'Burger Brand',
            'abbr' => 'BB',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
    }

    /** A genuine .xlsx workbook, byte-for-byte what Excel/OpenSpout produce. */
    private function xlsx(array $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up_').'.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($tmp);
        foreach ($rows as $row) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
        }
        $writer->close();
        $binary = file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    private function upload(string $url, string $filename, string $contents)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->post($url, ['file' => UploadedFile::fake()->createWithContent($filename, $contents)]);
    }

    // ---- template → upload round trip (defect A: the validation gate) ----

    public function test_downloaded_xlsx_template_round_trips_back_through_upload(): void
    {
        $brand = $this->brand();

        $tpl = $this->actingAs($this->admin, 'sanctum')->get('/api/v1/admin/upload/templates/sales-items');
        $tpl->assertStatus(200);

        // The template alone is header-only: it must be rejected, not silently
        // reported as a completed upload of 0 rows.
        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/sales-items", 'sales-items.xlsx', $tpl->getContent())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EMPTY_FILE');

        // The same workbook with a data row appended must import.
        $withData = $this->xlsx([
            ['رمز الصنف', 'اسم الصنف', 'التصنيف', 'وحدة البيع', 'السعر'],
            ['SKU-1', 'برجر', 'وجبات', 'حبة', '25.50'],
        ]);
        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/sales-items", 'sales-items.xlsx', $withData)
            ->assertStatus(200)
            ->assertJsonPath('rowsImported', 1)
            ->assertJsonPath('status', 'done');
    }

    public function test_csv_template_round_trips_back_through_upload(): void
    {
        $brand = $this->brand();

        $tpl = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/admin/upload/templates/sales-items?format=csv');
        $tpl->assertStatus(200);

        // The BOM the template writes must not break the header comparison.
        $csv = $tpl->getContent().'SKU-9,شاورما,وجبات,حبة,12'."\n";

        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/sales-items", 'sales-items.csv', $csv)
            ->assertStatus(200)
            ->assertJsonPath('rowsImported', 1);

        $this->assertSame('SKU-9', InventoryCatalogItem::firstOrFail()->code);
    }

    /** The relaxed rule must still gate: only the three parsable extensions. */
    public function test_disallowed_extension_is_rejected(): void
    {
        $brand = $this->brand();

        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/sales-items", 'items.pdf', '%PDF-1.4 not a sheet')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, UploadStatus::count());
    }

    // ---- defect D: code / category / price ----

    public function test_catalog_row_persists_code_category_and_price_in_halalas(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".'SKU-1,برجر,وجبات,حبة,25.50'."\n",
        )->assertStatus(200);

        $item = InventoryCatalogItem::firstOrFail();
        $this->assertSame('SKU-1', $item->code);
        $this->assertSame('برجر', $item->name);
        $this->assertSame('وجبات', $item->category);
        $this->assertSame(2550, $item->unit_price);
        $this->assertSame(InventoryCatalogItem::TYPE_SALES_ITEM, $item->type);
    }

    public function test_raw_material_row_persists_code(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/raw-materials",
            'mats.csv',
            'رمز المادة,اسم المادة,التصنيف,وحدة القياس,التكلفة'."\n".'RM-7,طحين,جاف,كجم,9.25'."\n",
        )->assertStatus(200);

        $item = InventoryCatalogItem::firstOrFail();
        $this->assertSame('RM-7', $item->code);
        $this->assertSame(925, $item->unit_price);
        $this->assertSame(InventoryCatalogItem::TYPE_RAW_MATERIAL, $item->type);
    }

    /** Blank codes normalize to null rather than collapsing rows together. */
    public function test_blank_code_is_stored_as_null_and_does_not_collapse_rows(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".',برجر,وجبات,حبة,10'."\n".',شاورما,وجبات,حبة,12'."\n",
        )->assertStatus(200)->assertJsonPath('rowsImported', 2);

        $this->assertSame(2, InventoryCatalogItem::count());
        $this->assertSame(2, InventoryCatalogItem::whereNull('code')->count());
    }

    // ---- defect E: supplier code ----

    public function test_supplier_row_persists_code(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/suppliers",
            'sup.csv',
            'رقم المورد,اسم المورد,الفئة,جهة الاتصال,شروط الدفع'."\n".'SUP-3,مؤسسة اللحوم,لحوم,خالد,صافي 30'."\n",
        )->assertStatus(200)->assertJsonPath('rowsImported', 1);

        $supplier = AsabSupplier::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('SUP-3', $supplier->code);
        $this->assertSame('مؤسسة اللحوم', $supplier->name);
        $this->assertSame('لحوم', $supplier->category);
        $this->assertSame('خالد', $supplier->contact_name);
    }

    // ---- BUG-9: brand upload writes through to the mobile tables ----

    public function test_supplier_upload_provisions_the_legacy_mobile_supplier_row(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/suppliers",
            'sup.csv',
            'رقم المورد,اسم المورد,الفئة,جهة الاتصال,شروط الدفع'."\n".'SUP-3,مؤسسة اللحوم,لحوم,خالد,صافي 30'."\n",
        )->assertStatus(200)->assertJsonPath('rowsImported', 1);

        // The legacy `suppliers` row the mobile Expense/Purchase pickers read.
        $legacy = LegacySupplier::where('name', 'مؤسسة اللحوم')->first();
        $this->assertNotNull($legacy, 'uploaded supplier must reach the legacy mobile table');

        // ...and the dashboard row is linked to it (legacy_supplier_id stamped).
        $asab = AsabSupplier::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($legacy->id, $asab->legacy_supplier_id);
    }

    public function test_raw_material_upload_seeds_branch_items_for_the_brand_branches(): void
    {
        $brand = $this->brand();
        $branchA = Branch::factory()->create(['asab_company_id' => $brand->company_id, 'asab_brand_id' => $brand->id]);
        $branchB = Branch::factory()->create(['asab_company_id' => $brand->company_id, 'asab_brand_id' => $brand->id]);
        // A branch of ANOTHER brand must NOT be seeded (brand-level propagation).
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $brand->company_id]);

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/raw-materials",
            'mats.csv',
            'رمز المادة,اسم المادة,التصنيف,وحدة القياس,التكلفة'."\n".'RM-7,طحين,جاف,كجم,9.25'."\n",
        )->assertStatus(200)->assertJsonPath('rowsImported', 1);

        // The uploaded material became a mobile purchasing item...
        $item = PurchaseItem::where('code', 'RM-7')->firstOrFail();
        // ...seeded into branch_item for BOTH of the brand's branches (the
        // table the mobile purchasing-officer picker paginates), priced in riyals.
        $this->assertTrue(BranchItem::where('item_id', $item->id)->where('branch_id', $branchA->id)->exists());
        $this->assertTrue(BranchItem::where('item_id', $item->id)->where('branch_id', $branchB->id)->exists());
        $this->assertEquals(9.25, (float) BranchItem::where('item_id', $item->id)->where('branch_id', $branchA->id)->value('price'));
        // Not for a branch outside the brand.
        $this->assertFalse(BranchItem::where('item_id', $item->id)->where('branch_id', $foreignBranch->id)->exists());
    }

    public function test_re_uploading_suppliers_does_not_duplicate_the_mobile_row(): void
    {
        $brand = $this->brand();
        $csv = 'رقم المورد,اسم المورد,الفئة,جهة الاتصال,شروط الدفع'."\n".'SUP-3,مؤسسة اللحوم,لحوم,خالد,صافي 30'."\n";

        $url = "/api/v1/admin/brands/{$brand->id}/upload/suppliers";
        $this->upload($url, 'sup.csv', $csv)->assertStatus(200);
        // A corrected re-upload of the same file must UPDATE in place, not add a
        // second row on either side of the bridge (the mobile picker would list
        // the supplier twice otherwise).
        $this->upload($url, 'sup.csv', $csv)->assertStatus(200);

        $this->assertSame(1, AsabSupplier::withoutGlobalScopes()->where('name', 'مؤسسة اللحوم')->count());
        $this->assertSame(1, LegacySupplier::where('name', 'مؤسسة اللحوم')->count());
    }

    public function test_re_uploading_raw_materials_backfills_a_branch_added_after_the_first_upload(): void
    {
        $brand = $this->brand();
        $branchA = Branch::factory()->create(['asab_company_id' => $brand->company_id, 'asab_brand_id' => $brand->id]);
        $csv = 'رمز المادة,اسم المادة,التصنيف,وحدة القياس,التكلفة'."\n".'RM-7,طحين,جاف,كجم,9.25'."\n";
        $url = "/api/v1/admin/brands/{$brand->id}/upload/raw-materials";

        $this->upload($url, 'mats.csv', $csv)->assertStatus(200);
        $item = PurchaseItem::where('code', 'RM-7')->firstOrFail();
        $this->assertTrue(BranchItem::where('item_id', $item->id)->where('branch_id', $branchA->id)->exists());

        // A branch created AFTER the first upload has no seed yet...
        $branchB = Branch::factory()->create(['asab_company_id' => $brand->company_id, 'asab_brand_id' => $brand->id]);
        $this->assertFalse(BranchItem::where('item_id', $item->id)->where('branch_id', $branchB->id)->exists());

        // ...re-uploading the same material backfills it (the live-match now seeds).
        $this->upload($url, 'mats.csv', $csv)->assertStatus(200);
        $this->assertTrue(BranchItem::where('item_id', $item->id)->where('branch_id', $branchB->id)->exists());
        // Still one mobile item — create-only, no duplicate on re-upload.
        $this->assertSame(1, PurchaseItem::where('code', 'RM-7')->count());
    }

    public function test_sales_items_upload_surfaces_an_expense_type_category(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".'SKU-1,برجر,وجبات,حبة,25.50'."\n",
        )->assertStatus(200);

        // The mobile Expense «المصروفات» tab reads categories where type=expense.
        $cat = Category::where('name', 'وجبات')->first();
        $this->assertNotNull($cat, 'uploaded sales-item category must reach the mobile taxonomy');
        $this->assertSame('expense', $cat->type);
        $this->assertTrue((bool) $cat->is_active);
    }

    public function test_raw_materials_upload_surfaces_a_purchase_type_category(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/raw-materials",
            'mats.csv',
            'رمز المادة,اسم المادة,التصنيف,وحدة القياس,التكلفة'."\n".'RM-7,طحين,جاف,كجم,9.25'."\n",
        )->assertStatus(200);

        // The mobile Expense «الأصناف/المشتريات» tab reads categories where type=purchase.
        $cat = Category::where('name', 'جاف')->first();
        $this->assertNotNull($cat);
        $this->assertSame('purchase', $cat->type);
    }

    public function test_item_sheet_with_sub_category_column_builds_the_hierarchical_taxonomy(): void
    {
        $brand = $this->brand();

        // Client sheets label the grouping column «الفئة» and add «اسم الفئة»
        // (its sub-category) — the mobile picker is parent → children.
        $csv = 'رمز الصنف,اسم الصنف,الفئة,اسم الفئة,وحدة البيع,السعر'."\n"
            .'SKU-1,فاتورة كهرباء,مرافق,كهرباء,حبة,25.50'."\n";

        $url = "/api/v1/admin/brands/{$brand->id}/upload/sales-items";
        $this->upload($url, 'items.csv', $csv)->assertStatus(200);
        // Re-upload: neither level duplicates.
        $this->upload($url, 'items.csv', $csv)->assertStatus(200);

        $parent = Category::where('name', 'مرافق')->whereNull('parent_id')->first();
        $this->assertNotNull($parent, '«الفئة» must become the parent category');
        $this->assertSame('expense', $parent->type);

        $child = Category::where('name', 'كهرباء')->first();
        $this->assertNotNull($child, '«اسم الفئة» must become the sub-category');
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('expense', $child->type);

        $this->assertSame(2, Category::count());

        // The shifted unit/price columns still land on the item row.
        $item = InventoryCatalogItem::where('name', 'فاتورة كهرباء')->first();
        $this->assertNotNull($item);
        $this->assertSame('مرافق', $item->category);
        $this->assertSame('حبة', $item->unit);
        $this->assertSame(2550, (int) $item->unit_price);
    }

    public function test_a_blank_sub_category_cell_nests_the_item_name_instead(): void
    {
        $brand = $this->brand();

        // The shipped template CARRIES the «اسم الفئة» column but the exported
        // rows leave it empty (the sub-category is not stored on the catalog
        // row), so every real client sheet looks like this. It used to produce
        // childless parents and the app answered «No sub-categories found»
        // (reported 2026-08-14: غاز / معدات / الوجبات).
        $csv = 'رمز الصنف,اسم الصنف,التصنيف,اسم الفئة,وحدة البيع,السعر'."\n"
            .'H4,تصليح غاز 1,غاز,,KG,700'."\n"
            .'H2,تصليح بوتجاز,معدات,,KG,600'."\n";

        $url = "/api/v1/admin/brands/{$brand->id}/upload/sales-items";
        $this->upload($url, 'items.csv', $csv)->assertStatus(200);
        $this->upload($url, 'items.csv', $csv)->assertStatus(200); // idempotent

        $gas = Category::where('name', 'غاز')->whereNull('parent_id')->first();
        $this->assertNotNull($gas, '«التصنيف» is the parent category');

        $child = Category::where('name', 'تصليح غاز 1')->first();
        $this->assertNotNull($child, 'the item name must nest under its التصنيف when «اسم الفئة» is blank');
        $this->assertSame($gas->id, $child->parent_id);
        $this->assertSame('expense', $child->type);

        // غاز + تصليح غاز 1 + معدات + تصليح بوتجاز, no duplicates on re-upload.
        $this->assertSame(4, Category::count());
    }

    public function test_a_blank_sub_category_cell_nests_the_material_name_for_purchases(): void
    {
        $brand = $this->brand();

        $csv = 'رمز المادة,اسم المادة,التصنيف,اسم الفئة,وحدة القياس,التكلفة'."\n"
            .'R1,صدور دجاج,دواجن,,كجم,22'."\n";

        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/raw-materials", 'items.csv', $csv)
            ->assertStatus(200);

        $parent = Category::where('name', 'دواجن')->whereNull('parent_id')->first();
        $this->assertNotNull($parent);
        $this->assertSame('purchase', $parent->type, 'raw materials feed the purchase taxonomy');

        $child = Category::where('name', 'صدور دجاج')->first();
        $this->assertNotNull($child, '«اسم المادة» must register as the sub-category');
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('purchase', $child->type);
    }

    public function test_reupload_with_sub_column_reparents_an_old_flat_category(): void
    {
        $brand = $this->brand();
        // A flat parent minted by an old 5-column upload («No sub-categories found»).
        $flat = Category::create(['name' => 'غاز', 'type' => 'expense', 'is_active' => true]);

        $csv = 'رمز الصنف,اسم الصنف,الفئة,اسم الفئة,وحدة البيع,السعر'."\n"
            .'SKU-9,أسطوانة غاز,مرافق,غاز,حبة,80'."\n";
        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/sales-items", 'items.csv', $csv)
            ->assertStatus(200);

        $parent = Category::where('name', 'مرافق')->whereNull('parent_id')->first();
        $this->assertNotNull($parent);
        // Healed in place: re-parented under «مرافق», no duplicate row.
        $this->assertSame($parent->id, $flat->fresh()->parent_id);
        $this->assertSame(1, Category::where('name', 'غاز')->count());
    }

    public function test_expense_category_bridge_is_idempotent_and_skips_blank(): void
    {
        $brand = $this->brand();
        $csv = 'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n"
            .'SKU-1,برجر,وجبات,حبة,10'."\n"
            .'SKU-2,عصير,,حبة,5'."\n";                 // blank category → no taxonomy row

        $url = "/api/v1/admin/brands/{$brand->id}/upload/sales-items";
        $this->upload($url, 'items.csv', $csv)->assertStatus(200);
        // Re-upload the same file: categories must not duplicate.
        $this->upload($url, 'items.csv', $csv)->assertStatus(200);

        $this->assertSame(1, Category::where('name', 'وجبات')->count());
        // A sheet without «اسم الفئة» nests the item name under its التصنيف —
        // and re-uploading must not duplicate either level.
        $burger = Category::where('name', 'برجر')->first();
        $this->assertNotNull($burger, 'item name must nest as the category child');
        $this->assertSame(Category::where('name', 'وجبات')->value('id'), $burger->parent_id);
        // وجبات + برجر only — the blank-category row created nothing.
        $this->assertSame(2, Category::count());
    }

    // ---- defect B: header validation ----

    public function test_wrong_headers_are_rejected_with_the_expected_columns(): void
    {
        $brand = $this->brand();

        $res = $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'code,name,category,unit,price'."\n".'SKU-1,برجر,وجبات,حبة,10'."\n",
        );

        $res->assertStatus(422)->assertJsonPath('error.code', 'INVALID_HEADER');
        $this->assertStringContainsString('رمز الصنف', $res->json('error.message'));
        $this->assertSame(0, InventoryCatalogItem::count());
        $this->assertSame(0, UploadStatus::count());
    }

    /** The FE labels the column «الفئة»; the ratified template says «التصنيف». */
    public function test_item_header_accepts_alfia_alias_for_altasnif(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'رمز الصنف,اسم الصنف,الفئة,وحدة البيع,السعر'."\n".'SKU-1,برجر,وجبات,حبة,10'."\n",
        )->assertStatus(200)->assertJsonPath('rowsImported', 1);
    }

    /** A headerless file used to silently lose its first real row. */
    public function test_headerless_file_is_rejected_rather_than_losing_its_first_row(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'SKU-1,برجر,وجبات,حبة,10'."\n".'SKU-2,شاورما,وجبات,حبة,12'."\n",
        )->assertStatus(422)->assertJsonPath('error.code', 'INVALID_HEADER');

        $this->assertSame(0, InventoryCatalogItem::count());
    }

    // ---- defect C: empty file must not report success ----

    public function test_header_only_file_is_rejected_and_stamps_no_upload_status(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n",
        )->assertStatus(422)->assertJsonPath('error.code', 'EMPTY_FILE');

        $this->assertSame(0, UploadStatus::count());
        $this->assertSame(0, InventoryCatalogItem::count());
    }

    public function test_completely_empty_file_is_rejected(): void
    {
        $brand = $this->brand();

        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/sales-items", 'items.csv', '')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EMPTY_FILE');

        $this->assertSame(0, UploadStatus::count());
    }

    // ---- defect H: status() scoping + completion ----

    public function test_status_counts_only_successful_brand_uploads(): void
    {
        $brand = $this->brand();

        $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/sales-items",
            'items.csv',
            'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".'SKU-1,برجر,وجبات,حبة,10'."\n",
        )->assertStatus(200);

        $res = $this->actingAs($this->admin, 'sanctum')->get("/api/v1/admin/brands/{$brand->id}/upload-status");

        $res->assertStatus(200)
            ->assertJsonPath('shared.sales', true)
            ->assertJsonPath('shared.materials', false)
            // «بيانات مشتركة» is the three catalog cards the screen offers, and
            // this brand has no branch/restaurant step yet, so 1 of 3.
            ->assertJsonPath('summary.shared.done', 1)
            ->assertJsonPath('summary.shared.total', 3)
            ->assertJsonPath('summary.branchAssets.total', 0)
            ->assertJsonPath('summary.restaurantEmployees.total', 0)
            // Brand-level assets are their own flag, never one of the three.
            ->assertJsonPath('brandFixedAssets', false)
            ->assertJsonPath('completionPct', 33);
    }

    public function test_status_does_not_count_a_failed_upload_as_complete(): void
    {
        $brand = $this->brand();
        UploadStatus::create([
            'owner_type' => 'brand',
            'owner_id' => $brand->id,
            'upload_type' => 'sales-items',
            'uploaded_count' => 0,
            'status' => 'failed',
            'uploaded_by_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/admin/brands/{$brand->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('shared.sales', false)
            ->assertJsonPath('completionPct', 0);
    }

    // ---- defect F: fixed assets, both layouts ----

    private function branch(): Branch
    {
        $company = AsabCompany::create(['name' => 'Asset Co', 'plan' => 'Basic', 'status' => 'active']);

        return Branch::factory()->create(['asab_company_id' => $company->id]);
    }

    public function test_arabic_fixed_assets_layout_imports_with_notes(): void
    {
        $branch = $this->branch();

        $csv = "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n"
            .'ثلاجة,معدات مطبخ,الفرع الرئيسي,INV-9,"28,000.00",60,أحمد,مستعملة'."\n";

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", 'assets.csv', $csv)
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1)
            ->assertJsonPath('errors', []);

        $asset = Asset::withoutGlobalScopes()->firstOrFail();
        // `(float) "28,000.00"` is 28.0 — the comma-tolerant parser is required.
        $this->assertSame(2800000, $asset->cost);
        $this->assertSame(2800000, $asset->book_value);
        // Both layouts must land on one key: «معدات مطبخ» here and
        // "Kitchen Equipment" in the English sheet are the same category.
        $this->assertSame('kitchen', $asset->category);
        $this->assertSame('مستعملة', $asset->notes);
        $this->assertSame('أحمد', $asset->custodian);
        $this->assertSame('INV-9', $asset->inv_num);
        $this->assertSame(60, $asset->useful_life_months);
        $this->assertSame($branch->id, $asset->branch_id);
        // The Arabic layout carries no date column: nothing may be fabricated.
        $this->assertNull($asset->purchased_at);
    }

    public function test_english_fixed_assets_layout_imports_at_brand_level(): void
    {
        $brand = $this->brand();

        $xlsx = $this->xlsx([
            ['Serial Number', 'Zone', 'Asset Category (Type)', 'Asset Name', 'Total Quantity',
                'Excellent', 'Maintenance', 'Problem', 'Purchase Date', 'Purchase Value', 'Notes'],
            ['SN-100', 'Riyadh North', 'POS & IT Equipment', 'Cashier Terminal', '10',
                '7', '2', '1', '2024-05-28', '28,000.00', 'second hand'],
        ]);

        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/fixed-assets", 'assets.xlsx', $xlsx)
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1)
            ->assertJsonPath('errors', []);

        $asset = Asset::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Cashier Terminal', $asset->name);
        $this->assertSame('SN-100', $asset->serial);
        $this->assertSame('Riyadh North', $asset->zone);
        $this->assertSame('tech', $asset->category);
        $this->assertSame(2800000, $asset->cost);
        $this->assertSame('second hand', $asset->notes);
        $this->assertSame(10, $asset->quantity);
        $this->assertSame(7, $asset->qty_excellent);
        $this->assertSame(2, $asset->qty_maintenance);
        $this->assertSame(1, $asset->qty_problem);
        $this->assertSame('2024-05-28', $asset->purchased_at->format('Y-m-d'));
        // Brand-level uploads name no branch — they stay pending assignment.
        $this->assertNull($asset->branch_id);
        $this->assertSame($brand->company_id, $asset->company_id);
    }

    public function test_english_category_labels_map_onto_canonical_keys(): void
    {
        $brand = $this->brand();

        $rows = [['Serial Number', 'Zone', 'Asset Category (Type)', 'Asset Name', 'Total Quantity',
            'Excellent', 'Maintenance', 'Problem', 'Purchase Date', 'Purchase Value', 'Notes']];
        $labels = [
            'Kitchen Equipment' => 'kitchen',
            'Electrical Equipment' => 'electrical',
            'Leasehold Improvements' => 'construction',
            'Furniture & Fixtures' => 'furniture',
            'Smallwares & Operating Equipment' => 'smallwares',
            'POS & IT Equipment' => 'tech',
            'Software & Licenses' => 'software',
            'Vehicles' => 'vehicles',
            'Totally Unknown Thing' => 'Totally Unknown Thing',
        ];
        foreach (array_keys($labels) as $i => $label) {
            $rows[] = ['SN-'.$i, 'Z', $label, 'Asset '.$i, '1', '1', '0', '0', '2024-01-01', '100', ''];
        }

        $this->upload("/api/v1/admin/brands/{$brand->id}/upload/fixed-assets", 'assets.xlsx', $this->xlsx($rows))
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 9);

        foreach (array_values($labels) as $i => $expected) {
            $this->assertSame($expected, Asset::withoutGlobalScopes()->where('name', 'Asset '.$i)->value('category'));
        }
    }

    public function test_condition_counts_exceeding_total_quantity_are_a_row_error(): void
    {
        $brand = $this->brand();

        $xlsx = $this->xlsx([
            ['Serial Number', 'Zone', 'Asset Category (Type)', 'Asset Name', 'Total Quantity',
                'Excellent', 'Maintenance', 'Problem', 'Purchase Date', 'Purchase Value', 'Notes'],
            ['SN-1', 'Z', 'Vehicles', 'Overcounted', '3', '3', '2', '1', '2024-01-01', '100', ''],
        ]);

        $res = $this->upload("/api/v1/admin/brands/{$brand->id}/upload/fixed-assets", 'assets.xlsx', $xlsx);

        // An upload that stored NOTHING answers 422 (it used to answer 200 with
        // assetCount 0, which the screen showed as a successful upload).
        $res->assertStatus(422)->assertJsonPath('error.code', 'UPLOAD_FAILED');
        $this->assertStringContainsString('exceed total quantity', $res->json('error.details.errors.0.message'));
        $this->assertSame(0, Asset::withoutGlobalScopes()->count());
    }

    public function test_fixed_assets_rejects_a_header_matching_neither_layout(): void
    {
        $brand = $this->brand();

        $res = $this->upload(
            "/api/v1/admin/brands/{$brand->id}/upload/fixed-assets",
            'assets.csv',
            'a,b,c'."\n".'1,2,3'."\n",
        );

        $res->assertStatus(422)->assertJsonPath('error.code', 'INVALID_HEADER');
        $this->assertStringContainsString('Asset Name', $res->json('error.message'));
    }
}
