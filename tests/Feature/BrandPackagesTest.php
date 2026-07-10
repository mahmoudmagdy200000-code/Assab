<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Database\Seeders\AsabBrandPackageSeeder;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabBrandPackage;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * WS6 — brand subscription packages catalog (admin CRUD + table-driven
 * plan validation/pricing in brand & subscription flows) and the fixed-asset
 * public_id uniqueness regression.
 */
class BrandPackagesTest extends TestCase
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

    private function seedPackages(): void
    {
        $this->seed(AsabBrandPackageSeeder::class);
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

    // ---- Package CRUD ----

    public function test_index_lists_active_and_inactive_packages(): void
    {
        $this->seedPackages();
        AsabBrandPackage::where('code', 'silver')->first()->update(['is_active' => false]);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/packages');

        $res->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'nameEn', 'price', 'isActive']]]);
        $this->assertContains(false, array_column($res->json('data'), 'isActive'));
    }

    public function test_store_creates_package_and_rejects_duplicate_code(): void
    {
        $payload = ['code' => 'diamond', 'name' => 'ماسي', 'nameEn' => 'Diamond', 'price' => 400000];

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/packages', $payload);

        $res->assertStatus(201)
            ->assertJsonPath('code', 'diamond')
            ->assertJsonPath('price', 400000)
            ->assertJsonPath('isActive', true);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/packages', $payload)
            ->assertStatus(422);
    }

    public function test_store_restores_soft_deleted_package_with_same_code(): void
    {
        $this->seedPackages();
        $gold = AsabBrandPackage::where('code', 'gold')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/packages/{$gold->id}")
            ->assertStatus(204);

        // Re-creating a soft-deleted code must not 500 on the DB unique index:
        // the trashed row is restored with the new attributes.
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/packages', [
            'code' => 'gold', 'name' => 'ذهبي جديد', 'nameEn' => 'Gold v2', 'price' => 180000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('id', $gold->id)
            ->assertJsonPath('code', 'gold')
            ->assertJsonPath('name', 'ذهبي جديد')
            ->assertJsonPath('price', 180000)
            ->assertJsonPath('isActive', true);

        $this->assertSame(1, AsabBrandPackage::withTrashed()->where('code', 'gold')->count());
        $this->assertNull($gold->fresh()->deleted_at);
    }

    public function test_update_patches_name_price_and_active_flag(): void
    {
        $this->seedPackages();
        $gold = AsabBrandPackage::where('code', 'gold')->firstOrFail();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/packages/{$gold->id}", ['price' => 200000, 'isActive' => false]);

        $res->assertStatus(200)
            ->assertJsonPath('price', 200000)
            ->assertJsonPath('isActive', false)
            ->assertJsonPath('name', 'ذهبي');
    }

    public function test_destroy_soft_deletes_package(): void
    {
        $this->seedPackages();
        $silver = AsabBrandPackage::where('code', 'silver')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/packages/{$silver->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('asab_brand_packages', ['id' => $silver->id]);

        $index = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/packages');
        $this->assertNotContains('silver', array_column($index->json('data'), 'code'));
    }

    // ---- Brand create validates against the table ----

    public function test_brand_create_accepts_table_package_code(): void
    {
        $this->seedPackages();
        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => $companyId,
            'name' => 'New Brand',
            'plan' => 'gold',
        ]);

        $res->assertStatus(201)->assertJsonPath('plan', 'gold');
    }

    public function test_brand_create_rejects_unknown_package_code(): void
    {
        $this->seedPackages();
        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => $companyId,
            'name' => 'New Brand',
            'plan' => 'nonexistent-package',
        ])->assertStatus(422);
    }

    public function test_brand_create_accepts_legacy_arabic_alias(): void
    {
        $this->seedPackages();
        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => $companyId,
            'name' => 'New Brand',
            'plan' => 'ذهبي',
        ])->assertStatus(201)->assertJsonPath('plan', 'gold');
    }

    // ---- Subscription pricing comes from the table ----

    public function test_subscription_store_prices_from_table(): void
    {
        $this->seedPackages();
        AsabBrandPackage::where('code', 'gold')->first()->update(['price' => 999999]);
        $brand = $this->brand();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/subscriptions', [
            'brandId' => $brand->id,
            'plan' => 'gold',
            'months' => 12,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('plan', 'ذهبي')
            ->assertJsonPath('monthlyPrice', 999999);
    }

    public function test_subscription_store_accepts_legacy_arabic_alias(): void
    {
        $this->seedPackages();
        $brand = $this->brand();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/subscriptions', [
            'brandId' => $brand->id,
            'plan' => 'بلاتيني',
            'months' => 6,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('plan', 'بلاتيني')
            ->assertJsonPath('monthlyPrice', 250000);
    }

    public function test_subscription_store_rejects_inactive_package(): void
    {
        $this->seedPackages();
        AsabBrandPackage::where('code', 'gold')->first()->update(['is_active' => false]);
        $brand = $this->brand();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/subscriptions', [
            'brandId' => $brand->id,
            'plan' => 'gold',
            'months' => 12,
        ])->assertStatus(422);
    }

    public function test_subscription_store_falls_back_to_legacy_catalog_when_table_unseeded(): void
    {
        $brand = $this->brand();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/subscriptions', [
            'brandId' => $brand->id,
            'plan' => 'gold',
            'months' => 12,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('plan', 'ذهبي')
            ->assertJsonPath('monthlyPrice', 175000);
    }

    public function test_change_plan_reads_price_from_table(): void
    {
        $this->seedPackages();
        AsabBrandPackage::where('code', 'silver')->first()->update(['price' => 123456]);
        $brand = $this->brand();
        $sub = AsabSubscription::create([
            'company_id' => $brand->company_id,
            'brand_id' => $brand->id,
            'plan' => 'ذهبي',
            'status' => 'active',
            'expires_at' => now()->addYear(),
            'days_left' => 365,
            'monthly_price' => 175000,
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/subscriptions/{$sub->id}/change-plan", ['plan' => 'silver']);

        $res->assertStatus(200)
            ->assertJsonPath('plan', 'فضي')
            ->assertJsonPath('monthlyPrice', 123456);
    }

    // ---- Fixed-asset public_id regression (WS6 part B) ----

    public function test_fixed_asset_upload_survives_soft_deleted_public_id(): void
    {
        $company = AsabCompany::create(['name' => 'Asset Co', 'plan' => 'Basic', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id]);

        // Two assets, the newest soft-deleted: count()+1 would regenerate FA-002
        // and hit the UNIQUE index (the old bug).
        Asset::create([
            'company_id' => $company->id, 'public_id' => 'FA-001', 'name' => 'A1',
            'cost' => 100, 'book_value' => 100, 'status' => 'pending_branch',
        ]);
        Asset::create([
            'company_id' => $company->id, 'public_id' => 'FA-002', 'name' => 'A2',
            'cost' => 100, 'book_value' => 100, 'status' => 'pending_branch',
        ])->delete();

        $csv = "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n"
            .'ثلاجة,معدات,الفرع الرئيسي,INV-9,5000,60,أحمد,'."\n";
        $file = UploadedFile::fake()->createWithContent('assets.csv', $csv);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->post("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('assetCount', 1)->assertJsonPath('errors', []);

        $created = Asset::where('name', 'ثلاجة')->firstOrFail();
        $this->assertSame('FA-003', $created->public_id);
        $this->assertSame(3, Asset::withTrashed()->count());
    }
}
