<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\SupplierItem;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «تم إنشاء مورد جديد ولكن لا يمكن إضافة الأصناف والأسعار
 * بالطريقتين»: the portal's Excel button had no import endpoint at all (only
 * export existed), and «السعر (ر.س)» was posted into a field the API reads as
 * halalas — 20 ر.س stored as 0.20. The header also showed the login's name
 * instead of the supplier company, so a rename never appeared.
 */
class SupplierCatalogUploadTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $user;

    private AsabSupplier $supplier;

    /** The portal flag is read at route registration — set it before boot. */
    public function createApplication()
    {
        putenv('FEATURE_ASAB_SUPPLIER_PORTAL=true');
        $_ENV['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'true';
        $_SERVER['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'true';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'شركة الدواجن الوطنية', 'plan' => 'Professional', 'status' => 'active']);
        $this->user = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مسؤول المورد',
            'email' => 'sup@catalog.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->user->id, 'role_key' => 'supplier', 'scope' => 'all']);
        $this->supplier = AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'شركة الدواجن الوطنية',
            'contact_email' => 'sup@catalog.test', 'user_id' => $this->user->id, 'status' => 'active',
        ]);
    }

    private function csv(string $rows): string
    {
        return "\xEF\xBB\xBF".'الصنف,الرمز,الوحدة,السعر (ر.س),الحد الأدنى,الحد الأقصى,مدة التحضير (يوم),الفئة,متاح'."\n".$rows;
    }

    private function importCsv(string $contents)
    {
        return $this->actingAs($this->user, 'sanctum')->post('/api/v1/asab/supplier/items/import', [
            'file' => UploadedFile::fake()->createWithContent('items.csv', $contents),
        ]);
    }

    /** The portal must be reachable — with the flag off every call 404s. */
    public function test_the_catalog_endpoints_are_registered(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/asab/supplier/items')
            ->assertSuccessful();
    }

    public function test_adding_an_item_accepts_a_sar_price(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/asab/supplier/items', [
                'name' => 'جبن الدويم - بن زقر',
                'code' => 'SK-R-001',
                'unit' => 'KG',
                'priceSar' => 20,
                'minQty' => 10,
                'maxQty' => 2000,
                'leadTimeDays' => 1,
                'available' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('priceHalalas', 2000)
            ->assertJsonPath('priceSar', 20);

        $this->assertSame(2000, (int) SupplierItem::firstWhere('code', 'SK-R-001')->price);
    }

    public function test_the_excel_import_creates_the_catalog(): void
    {
        $this->importCsv($this->csv(
            'جبن الدويم,SK-R-001,KG,20,10,2000,1,ألبان,نعم'."\n".
            'دجاج طازج,SK-R-002,KG,"18.50",5,500,2,دواجن,نعم'."\n"
        ))->assertSuccessful()->assertJsonPath('itemCount', 2);

        $cheese = SupplierItem::firstWhere('code', 'SK-R-001');
        $this->assertSame(2000, (int) $cheese->price);       // 20.00 ر.س
        $this->assertSame('KG', $cheese->unit);
        $this->assertSame($this->supplier->id, $cheese->supplier_id);
        $this->assertSame($this->user->id, $cheese->supplier_user_id);

        $this->assertSame(1850, (int) SupplierItem::firstWhere('code', 'SK-R-002')->price);
    }

    /** «صدّر → عدّل → ارفع» must update, never duplicate. */
    public function test_re_uploading_the_same_sheet_updates_prices(): void
    {
        $this->importCsv($this->csv('جبن الدويم,SK-R-001,KG,20,,,,ألبان,نعم'."\n"))->assertSuccessful();
        $this->importCsv($this->csv('جبن الدويم,SK-R-001,KG,22,,,,ألبان,لا'."\n"))->assertSuccessful();

        $items = SupplierItem::where('code', 'SK-R-001')->get();
        $this->assertCount(1, $items);
        $this->assertSame(2200, (int) $items->first()->price);
        $this->assertFalse((bool) $items->first()->available);
        $this->assertSame('inactive', $items->first()->status);
    }

    public function test_a_sheet_without_an_item_column_is_refused_with_a_reason(): void
    {
        $this->importCsv("\xEF\xBB\xBF".'العمود,قيمة'."\n".'س,1'."\n")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'HEADER_MISMATCH');

        $this->assertSame(0, SupplierItem::count());
    }

    public function test_the_template_is_downloadable(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/asab/supplier/items/template?format=csv')
            ->assertSuccessful()
            ->assertSee('الصنف');
    }

    /** The portal header reads the SUPPLIER name, not the login's name. */
    public function test_auth_me_carries_the_supplier_identity(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertSuccessful()
            ->assertJsonPath('supplier.name', 'شركة الدواجن الوطنية')
            ->assertJsonPath('supplier.id', $this->supplier->id)
            ->assertJsonPath('name', 'مسؤول المورد');
    }

    public function test_renaming_the_supplier_reaches_the_portal_identity(): void
    {
        $admin = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير الشركة',
            'email' => 'ca@catalog.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $admin->id, 'role_key' => 'company-admin', 'scope' => 'all']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/company/me/suppliers/{$this->supplier->id}", ['name' => 'شركة الدواجن الوطنية المحدثة'])
            ->assertSuccessful();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('supplier.name', 'شركة الدواجن الوطنية المحدثة');
    }
}
