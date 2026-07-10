<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BranchInventoryList;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Setting;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Regression (WS1): branch-manager read endpoints must read the ASAB dashboard
 * stores, tenant-scoped — items/suppliers used to read legacy tables with no
 * tenant filter, and settings let the manager edit admin-owned branch identity.
 */
class BranchDashboardReadsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private AsabUser $manager;

    private AsabCompany $otherCompany;

    private AsabBrand $otherBrand;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Branch Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand A', 'status' => 'active']);
        $this->branch = Branch::create([
            'name' => 'فرع العليا',
            'location' => 'Riyadh',
            'lat' => 0,
            'lng' => 0,
            'phone' => '0112223344',
            'address' => 'شارع العليا العام',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير الفرع',
            'email' => 'branch@reads.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->manager->id,
            'role_key' => 'branch',
            'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);

        $this->otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $this->otherBrand = AsabBrand::create(['company_id' => $this->otherCompany->id, 'name' => 'Brand B', 'status' => 'active']);
        $this->otherBranch = Branch::create([
            'name' => 'فرع منافس',
            'location' => 'Jeddah',
            'lat' => 0,
            'lng' => 0,
            'asab_company_id' => $this->otherCompany->id,
            'asab_brand_id' => $this->otherBrand->id,
        ]);
    }

    public function test_items_returns_branch_assigned_list_and_not_other_tenants(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'المحاسب أحمد',
            'email' => 'acct@reads.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $assigned = InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'برجر لحم', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active',
        ]);
        // In the brand catalog but NOT assigned to this branch's list.
        InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'بيتزا مارغريتا', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active',
        ]);
        $foreign = InventoryCatalogItem::create([
            'brand_id' => $this->otherBrand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'صنف شركة تانية', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active',
        ]);
        BranchInventoryList::create([
            'branch_id' => $this->branch->id, 'catalog_item_id' => $assigned->id, 'added_by_id' => $accountant->id,
        ]);
        BranchInventoryList::create([
            'branch_id' => $this->otherBranch->id, 'catalog_item_id' => $foreign->id,
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/branch/inventory-items');

        $res->assertOk();
        $names = collect($res->json('items'))->pluck('name');
        $this->assertTrue($names->contains('برجر لحم'));
        $this->assertFalse($names->contains('بيتزا مارغريتا'), 'unassigned catalog item must not appear');
        $this->assertFalse($names->contains('صنف شركة تانية'), 'other tenant item must not leak');
        $this->assertSame('المحاسب أحمد', $res->json('configuredBy'));
    }

    public function test_items_falls_back_to_brand_sales_catalog_when_no_list_configured(): void
    {
        InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'شاورما دجاج', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active',
        ]);
        InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
            'name' => 'دقيق خام', 'category' => 'مواد خام', 'unit' => 'كجم', 'status' => 'active',
        ]);
        InventoryCatalogItem::create([
            'brand_id' => $this->otherBrand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'صنف شركة تانية', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active',
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/branch/inventory-items');

        $res->assertOk();
        $names = collect($res->json('items'))->pluck('name');
        $this->assertTrue($names->contains('شاورما دجاج'));
        $this->assertFalse($names->contains('دقيق خام'), 'raw materials are not sales items');
        $this->assertFalse($names->contains('صنف شركة تانية'), 'other tenant item must not leak');
        $this->assertNull($res->json('configuredBy'));
    }

    public function test_suppliers_returns_only_own_companys_active_suppliers(): void
    {
        AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'مورد الخضار', 'category' => 'خضار', 'status' => 'active',
        ]);
        AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'مورد موقوف', 'category' => 'لحوم', 'status' => 'inactive',
        ]);
        AsabSupplier::create([
            'company_id' => $this->otherCompany->id, 'name' => 'مورد شركة تانية', 'category' => 'خضار', 'status' => 'active',
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/branch/suppliers');

        $res->assertOk();
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('مورد الخضار'));
        $this->assertFalse($names->contains('مورد موقوف'), 'inactive supplier must not appear');
        $this->assertFalse($names->contains('مورد شركة تانية'), 'other tenant supplier must not leak');

        $row = collect($res->json('data'))->firstWhere('name', 'مورد الخضار');
        $this->assertSame('خضار', $row['category']);
        $this->assertTrue($row['isActive']);
    }

    public function test_settings_shows_admin_maintained_branch_identity_read_only(): void
    {
        // Stale manager-entered identity in the payload must NOT win over the
        // admin-maintained Branch record.
        Setting::create([
            'company_id' => $this->company->id,
            'group_key' => 'branch:'.$this->branch->id,
            'payload' => ['branchName' => 'اسم قديم', 'phone' => '000', 'taxNumber' => 'TAX-1'],
        ]);
        \Modules\Admin\Models\BrandShiftConfig::create([
            'brand_id' => $this->brand->id, 'num_shifts' => 2, 'duration_hours' => 8, 'first_shift_start' => '08:00',
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/branch/settings');

        $res->assertOk();
        $this->assertSame('فرع العليا', $res->json('branchName'));
        $this->assertSame('0112223344', $res->json('phone'));
        $this->assertSame('شارع العليا العام', $res->json('address'));
        $this->assertSame(['branchName', 'phone', 'address'], $res->json('readOnlyFields'));
        $this->assertSame('TAX-1', $res->json('taxNumber'));
        $this->assertTrue($res->json('shiftConfig.readOnly'));
    }

    public function test_update_settings_ignores_identity_fields_and_preserves_unspecified_prefs(): void
    {
        Setting::create([
            'company_id' => $this->company->id,
            'group_key' => 'branch:'.$this->branch->id,
            'payload' => ['taxNumber' => 'TAX-1', 'autoReminders' => true, 'openTime' => '08:00'],
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')->patchJson('/api/v1/branch/settings', [
            'branchName' => 'اسم مخترق',
            'phone' => '9999',
            'address' => 'عنوان مخترق',
            'wasteThreshold' => 5,
        ]);

        $res->assertOk();
        $payload = Setting::where('group_key', 'branch:'.$this->branch->id)->first()->payload;
        $this->assertArrayNotHasKey('branchName', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('address', $payload);
        $this->assertSame(5, (int) $payload['wasteThreshold']);
        $this->assertSame('TAX-1', $payload['taxNumber'], 'unspecified pref must be preserved');
        $this->assertTrue($payload['autoReminders'], 'unspecified pref must be preserved');
        $this->assertSame('08:00', $payload['openTime'], 'admin shift timing must be carried over');

        // The read side still serves the admin-set identity.
        $get = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/branch/settings');
        $get->assertOk();
        $this->assertSame('فرع العليا', $get->json('branchName'));
    }
}
