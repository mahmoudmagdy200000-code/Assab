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
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * «رفعت الأصول الثابتة، عملت refresh، مش بتتحفظ» — reported 2026-07-26.
 *
 * `asab_assets.company_id` is NOT NULL and the importer read it from
 * `branches.asab_company_id`, which is NULL on branches that never got the ASAB
 * hierarchy columns. For a platform admin (no company of their own) EVERY row
 * then died on the constraint, yet the endpoint answered 200 with
 * `assetCount: 0` and leaked the raw INSERT into the error list, so the column
 * stayed «لم يُرفع» after each re-upload with nothing actionable on screen.
 */
class BranchFixedAssetsUploadPersistenceTest extends TestCase
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
            'name' => 'Platform Admin', 'email' => 'admin@assets.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'حية عنب', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'حية عنب', 'abbr' => 'HN',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'الخرج', 'status' => 'active',
        ]);
    }

    private function assetsCsv(string $name = 'ثلاجة'): string
    {
        return "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n"
            .$name.',معدات مطبخ,,INV-1,"10,000.00",60,أحمد,'."\n";
    }

    private function upload(string $url, string $contents)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->post($url, ['file' => UploadedFile::fake()->createWithContent('assets.csv', $contents)]);
    }

    /** The reported case: hierarchy columns set except the company link. */
    public function test_upload_persists_for_a_branch_whose_company_link_is_missing(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => null,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv())
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1)
            ->assertJsonPath('errors', []);

        $asset = Asset::withoutGlobalScopes()->firstWhere('name', 'ثلاجة');
        $this->assertNotNull($asset);
        // Company resolved through the restaurant, and the branch backfilled so
        // every later screen reads the link directly.
        $this->assertSame($this->company->id, $asset->company_id);
        $this->assertSame($this->company->id, $branch->fresh()->asab_company_id);

        // …and it survives the refresh.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$branch->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('fixedAssets', true)
            ->assertJsonPath('fixedAssetsStatus', 'done')
            ->assertJsonPath('fixedAssetsCount', 1);
    }

    public function test_upload_resolves_the_company_through_the_brand_alone(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => null,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => null,
        ]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv('فرن'))
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1);

        $this->assertSame($this->company->id, Asset::withoutGlobalScopes()->firstWhere('name', 'فرن')->company_id);
    }

    public function test_a_branch_linked_to_nothing_is_refused_with_the_reason(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null,
        ]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_NOT_LINKED');

        // Refused before importing: no half state, no misleading status row.
        $this->assertSame(0, Asset::withoutGlobalScopes()->count());
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$branch->id}/upload-status")
            ->assertJsonPath('fixedAssetsStatus', 'not_uploaded');
    }

    public function test_an_upload_that_stores_nothing_is_an_error_not_a_success(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        // Row that the importer rejects (condition counts over the quantity).
        $csv = 'Serial Number,Zone,Asset Category (Type),Asset Name,Total Quantity,Excellent,Maintenance,Problem,Purchase Date,Purchase Value,Notes'."\n"
            .'SN-1,Z,Vehicles,Overcounted,3,3,2,1,2024-01-01,100,'."\n";

        $res = $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $csv);

        $res->assertStatus(422)->assertJsonPath('error.code', 'UPLOAD_FAILED');
        $this->assertStringContainsString('exceed total quantity', $res->json('error.details.errors.0.message'));
        // The failure and its reason stay readable on the status column.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$branch->id}/upload-status")
            ->assertJsonPath('fixedAssetsStatus', 'failed')
            ->assertJsonPath('fixedAssetsFailedRows', 1);
    }

    public function test_brand_level_column_reads_every_branch_in_one_call(): void
    {
        $uploaded = Branch::factory()->create([
            'name' => 'فرع الخرج 1', 'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id, 'asab_restaurant_id' => $this->restaurant->id,
        ]);
        $pending = Branch::factory()->create([
            'name' => 'فرع الخرج 2', 'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id, 'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $this->upload("/api/v1/admin/branches/{$uploaded->id}/upload/fixed-assets", $this->assetsCsv('ثلاجة'))
            ->assertStatus(200);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/brands/{$this->brand->id}/branches/upload-status");

        $res->assertStatus(200)
            ->assertJsonPath('totals.branches', 2)
            ->assertJsonPath('totals.uploaded', 1)
            ->assertJsonPath('totals.failed', 0);

        $rows = collect($res->json('branches'))->keyBy('branchId');
        $this->assertSame('done', $rows[$uploaded->id]['fixedAssetsStatus']);
        $this->assertSame('الخرج', $rows[$uploaded->id]['restaurantName']);
        $this->assertSame('not_uploaded', $rows[$pending->id]['fixedAssetsStatus']);
    }

    public function test_a_driver_error_is_not_leaked_to_the_client(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        // 200-char name against a 200-char column plus a suffix → driver error.
        $res = $this->upload(
            "/api/v1/admin/branches/{$branch->id}/upload/fixed-assets",
            $this->assetsCsv(str_repeat('ط', 400)),
        );

        if ($res->status() === 200) {
            $this->markTestSkipped('This driver does not enforce the column length.');
        }

        $message = $res->json('error.details.errors.0.message');
        $this->assertStringNotContainsString('insert into', (string) $message);
        $this->assertStringNotContainsString('asab_assets', (string) $message);
    }
}
