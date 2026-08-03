<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * The «رفع البيانات» screen, as reported on 2026-08-03:
 *
 *  1. the الأصول الثابتة column stayed «لم يُرفع» after a successful upload,
 *  2. re-downloading the template handed back an EMPTY sheet instead of what
 *     the system had stored,
 *  3. «ملخص البيانات» never reached 100% — it counted a brand-level fixed-assets
 *     step the screen does not offer.
 */
class AdminUploadDataRoundTripTest extends TestCase
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
            'name' => 'Platform Admin', 'email' => 'admin@roundtrip.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'جورمية كافيه', 'abbr' => 'JK',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'التعاون', 'status' => 'active',
        ]);
    }

    private function upload(string $url, string $contents, string $filename = 'sheet.csv')
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->post($url, ['file' => UploadedFile::fake()->createWithContent($filename, $contents)]);
    }

    private function fetch(string $url)
    {
        return $this->actingAs($this->admin, 'sanctum')->get($url);
    }

    private function assetsCsv(string $name = 'ثلاجة'): string
    {
        return "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n"
            .$name.',معدات مطبخ,,INV-1,"10,000.00",60,أحمد,'."\n";
    }

    // ---- 1. «حالة الرفع» stays «لم يُرفع» ----

    /**
     * The reported shape: the branch hangs off the RESTAURANT and carries no
     * `asab_brand_id`. The column filtered on that one column, so the brand
     * screen was told the brand has no branches at all and every row on it
     * fell back to «لم يُرفع».
     */
    public function test_the_column_sees_a_branch_linked_only_through_its_restaurant(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'التعاون 1',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv())
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1);

        $res = $this->fetch("/api/v1/admin/brands/{$this->brand->id}/branches/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('totals.branches', 1)
            ->assertJsonPath('totals.uploaded', 1);

        $this->assertSame('done', $res->json('branches.0.fixedAssetsStatus'));
        $this->assertSame('التعاون', $res->json('branches.0.restaurantName'));
    }

    // ---- 3. «ملخص البيانات» ----

    public function test_the_summary_counts_the_three_shared_cards_plus_branch_and_restaurant_steps(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'التعاون 1',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        foreach ([
            'sales-items' => 'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".'SKU-1,برجر,وجبات,حبة,10'."\n",
            'raw-materials' => 'رمز المادة,اسم المادة,التصنيف,وحدة القياس,التكلفة'."\n".'RM-1,لحم,لحوم,كجم,45'."\n",
            'suppliers' => 'رقم المورد,اسم المورد,الفئة,جهة الاتصال,شروط الدفع'."\n".'SUP-1,مورد اللحوم,لحوم,خالد,30 يوم'."\n",
        ] as $type => $csv) {
            $this->upload("/api/v1/admin/brands/{$this->brand->id}/upload/{$type}", "\xEF\xBB\xBF".$csv)
                ->assertStatus(200);
        }

        // Three shared cards done; the branch's assets and the restaurant's
        // roster are still pending → 3 of 5.
        $this->fetch("/api/v1/admin/brands/{$this->brand->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('summary.shared', ['done' => 3, 'total' => 3])
            ->assertJsonPath('summary.branchAssets', ['done' => 0, 'total' => 1])
            ->assertJsonPath('summary.restaurantEmployees', ['done' => 0, 'total' => 1])
            ->assertJsonPath('completionPct', 60);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv())
            ->assertStatus(200);
        $this->upload(
            "/api/v1/admin/restaurants/{$this->restaurant->id}/upload/employees",
            "\xEF\xBB\xBF".'اسم الموظف,الوظيفة,اسم الفرع,رقم الجوال,رقم الهوية,الراتب الشهري (ر.س),نوع الوردية,تاريخ التعيين'."\n"
                .'سعد,طباخ,التعاون 1,0551234567,1234567890,4500,صباحية,2026-01-05'."\n",
        )->assertStatus(200);

        // Everything the screen asks for → 100%, which the 4-step denominator
        // could never reach.
        $this->fetch("/api/v1/admin/brands/{$this->brand->id}/upload-status")
            ->assertJsonPath('summary.branchAssets', ['done' => 1, 'total' => 1])
            ->assertJsonPath('summary.restaurantEmployees', ['done' => 1, 'total' => 1])
            ->assertJsonPath('completionPct', 100);
    }

    // ---- 2. the template comes back filled ----

    public function test_the_catalog_template_downloads_the_brands_saved_rows(): void
    {
        $this->upload(
            "/api/v1/admin/brands/{$this->brand->id}/upload/sales-items",
            "\xEF\xBB\xBF".'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".'SKU-1,برجر لحم,وجبات,حبة,10.50'."\n",
        )->assertStatus(200);

        $body = $this->fetch("/api/v1/admin/upload/templates/sales-items?format=csv&brandId={$this->brand->id}")
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('رمز الصنف', $body);
        $this->assertStringContainsString('SKU-1,برجر لحم,وجبات,,حبة,10.50', $body);

        // …and the blank sheet is still one query param away.
        $blank = $this->fetch("/api/v1/admin/upload/templates/sales-items?format=csv&brandId={$this->brand->id}&withData=0")
            ->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('SKU-1', $blank);
    }

    public function test_a_brand_with_nothing_stored_still_gets_the_blank_template(): void
    {
        $body = $this->fetch("/api/v1/admin/upload/templates/suppliers?format=csv&brandId={$this->brand->id}")
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('رقم المورد', $body);
        $this->assertSame(1, substr_count(trim($body), "\n") + 1);
    }

    public function test_the_asset_template_downloads_the_branch_register_without_the_sample_rows(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'التعاون 1',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv('ثلاجة عرض'))
            ->assertStatus(200);

        $body = $this->fetch("/api/v1/admin/upload/templates/fixed-assets?format=csv&branchId={$branch->id}")
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('Serial Number', $body);
        $this->assertStringContainsString('ثلاجة عرض', $body);
        // Sample rows are scaffolding for an empty sheet only.
        $this->assertStringNotContainsString('Sample only', $body);
        $this->assertStringContainsString('10000.00', $body);
    }

    /**
     * The download is an edit surface, so the sheet it hands back must re-import
     * onto the SAME rows — a plain create() doubled the catalog on every pass.
     */
    public function test_re_uploading_the_exported_catalog_updates_instead_of_duplicating(): void
    {
        $csv = "\xEF\xBB\xBF".'رمز الصنف,اسم الصنف,التصنيف,وحدة البيع,السعر'."\n".'SKU-1,برجر,وجبات,حبة,10'."\n";
        $this->upload("/api/v1/admin/brands/{$this->brand->id}/upload/sales-items", $csv)->assertStatus(200);

        $exported = $this->fetch("/api/v1/admin/upload/templates/sales-items?format=csv&brandId={$this->brand->id}")
            ->getContent();
        $corrected = str_replace('برجر', 'برجر دجاج', $exported);

        $this->upload("/api/v1/admin/brands/{$this->brand->id}/upload/sales-items", $corrected)->assertStatus(200);

        $items = InventoryCatalogItem::where('brand_id', $this->brand->id)->get();
        $this->assertCount(1, $items);
        $this->assertSame('برجر دجاج', $items->first()->name);
    }

    public function test_re_uploading_an_exported_register_matches_assets_on_their_serial(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $csv = 'Serial Number,Zone,Asset Category (Type),Asset Name,Total Quantity,Excellent,Maintenance,Problem,Purchase Date,Purchase Value,Notes'."\n"
            .'SN-1,Kitchen,معدات مطبخ,ثلاجة,3,3,0,0,2026-01-01,28000.00,'."\n";

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $csv)->assertStatus(200);
        $publicId = Asset::withoutGlobalScopes()->firstWhere('serial', 'SN-1')->public_id;

        $corrected = str_replace('ثلاجة', 'ثلاجة عرض', $csv);
        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $corrected)->assertStatus(200);

        $assets = Asset::withoutGlobalScopes()->where('branch_id', $branch->id)->get();
        $this->assertCount(1, $assets);
        $this->assertSame('ثلاجة عرض', $assets->first()->name);
        // …and it keeps the id the register was printed with.
        $this->assertSame($publicId, $assets->first()->public_id);
    }
}
