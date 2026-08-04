<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\SupplierItem;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «لا يمكن إضافة صنف جديد — المستخدم غير مرتبط بشركة»: the
 * procurement catalog could only be WRITTEN through /company/me/procurement/*,
 * and ResolveTenant refuses that whole surface to a PLATFORM procurement
 * account (company_id NULL — it buys for ASAB, not for one company). The screen
 * therefore offered «إضافة صنف» and an Excel button that always 403'd.
 */
class PlatformProcurementCatalogTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = AsabUser::create([
            'company_id' => null, 'name' => 'مدير المشتريات',
            'email' => 'proc@platform.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->platform->id, 'role_key' => 'procurement', 'scope' => 'all']);
    }

    private function csv(string $rows): string
    {
        return "\xEF\xBB\xBF".'الصنف,الرمز,الوحدة,السعر (ر.س),الحد الأدنى,الحد الأقصى,مدة التحضير (يوم),الفئة,متاح'."\n".$rows;
    }

    public function test_a_platform_procurement_user_can_add_an_item(): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/procurement/items', [
                'name' => 'خبز برجر بريوش',
                'unit' => 'حبة',
                'lastPriceSar' => 500,
                'category' => 'مواد غذائية',
            ])
            ->assertStatus(201)
            ->assertJsonPath('lastPriceHalalas', 50000)
            ->assertJsonPath('lastPriceSar', 500);

        $item = SupplierItem::firstWhere('name', 'خبز برجر بريوش');
        $this->assertNotNull($item);
        $this->assertNull($item->company_id, 'a platform account owns platform (companyless) rows');
    }

    /** The regression itself: the /company/me path is still closed to them. */
    public function test_the_company_surface_stays_closed_to_a_platform_account(): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/items', ['name' => 'x', 'unit' => 'ح'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WRONG_TENANT');
    }

    public function test_a_platform_item_can_be_edited_and_deleted_afterwards(): void
    {
        $id = $this->actingAs($this->platform, 'sanctum')
            ->postJson('/api/v1/procurement/items', ['name' => 'زيت', 'unit' => 'لتر', 'lastPriceSar' => 30])
            ->json('id');

        $this->actingAs($this->platform, 'sanctum')
            ->patchJson("/api/v1/procurement/items/{$id}", ['lastPriceSar' => 35])
            ->assertSuccessful()
            ->assertJsonPath('lastPriceHalalas', 3500);

        $this->actingAs($this->platform, 'sanctum')
            ->getJson("/api/v1/procurement/items/{$id}/price-history")
            ->assertSuccessful()
            ->assertJsonPath('data.0.priceHalalas', 3500);

        $this->actingAs($this->platform, 'sanctum')
            ->deleteJson("/api/v1/procurement/items/{$id}")
            ->assertStatus(204);
    }

    public function test_a_platform_account_cannot_edit_a_companys_item(): void
    {
        $company = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active']);
        $theirs = SupplierItem::create([
            'company_id' => $company->id, 'name' => 'صنف الشركة', 'unit' => 'كجم', 'price' => 100, 'status' => 'active',
        ]);

        $this->actingAs($this->platform, 'sanctum')
            ->patchJson("/api/v1/procurement/items/{$theirs->id}", ['name' => 'مسروق'])
            ->assertStatus(404);

        $this->assertSame('صنف الشركة', $theirs->fresh()->name);
    }

    public function test_the_excel_import_fills_the_catalog(): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->post('/api/v1/procurement/items/import', [
                'file' => UploadedFile::fake()->createWithContent('items.csv', $this->csv(
                    'خبز برجر بريوش,BR-01,حبة,500,10,,1,مواد غذائية,نعم'."\n".
                    'جبن شيدر,CH-01,كجم,"45.50",5,,1,ألبان,نعم'."\n"
                )),
            ])
            ->assertSuccessful()
            ->assertJsonPath('itemCount', 2);

        $this->assertSame(50000, (int) SupplierItem::firstWhere('code', 'BR-01')->price);
        $this->assertSame(4550, (int) SupplierItem::firstWhere('code', 'CH-01')->price);
        $this->assertNull(SupplierItem::firstWhere('code', 'BR-01')->company_id);
    }

    /** Re-uploading the same sheet updates rather than duplicating. */
    public function test_the_import_is_idempotent_for_platform_rows(): void
    {
        $file = fn (string $price) => UploadedFile::fake()->createWithContent(
            'items.csv', $this->csv("خبز برجر بريوش,BR-01,حبة,{$price},,,,مواد غذائية,نعم\n")
        );

        $this->actingAs($this->platform, 'sanctum')
            ->post('/api/v1/procurement/items/import', ['file' => $file('500')])->assertSuccessful();
        $this->actingAs($this->platform, 'sanctum')
            ->post('/api/v1/procurement/items/import', ['file' => $file('520')])->assertSuccessful();

        $rows = SupplierItem::where('code', 'BR-01')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(52000, (int) $rows->first()->price);
    }

    public function test_the_template_is_downloadable(): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->get('/api/v1/procurement/items/template?format=csv')
            ->assertSuccessful()
            ->assertSee('الصنف');
    }
}
