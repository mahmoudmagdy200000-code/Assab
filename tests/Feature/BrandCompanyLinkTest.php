<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Database\Seeders\AsabBrandPackageSeeder;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * Admin "Add Brand" company linkage: companyId is optional (auto-creates a
 * company for the brand) but, when supplied, must reference a live company —
 * asab_brands.company_id has no FK, so validation is the only guard against a
 * permanently orphaned brand.
 */
class BrandCompanyLinkTest extends TestCase
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

    public function test_brand_create_without_company_id_auto_creates_company_named_after_brand(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'name' => 'Solo Brand',
        ]);

        $res->assertStatus(201)->assertJsonPath('name', 'Solo Brand');

        $companyId = $res->json('companyId');
        $this->assertNotNull($companyId);

        $company = AsabCompany::find($companyId);
        $this->assertNotNull($company, 'brand must resolve to a real, non-orphaned company');
        $this->assertSame('Solo Brand', $company->name);
        $this->assertSame($companyId, AsabBrand::findOrFail($res->json('id'))->company_id);
    }

    public function test_brand_create_rejects_unknown_company_id(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => 'bogus-id',
            'name' => 'Orphan Brand',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['companyId']]]);

        $this->assertSame(0, AsabBrand::count());
    }

    public function test_brand_create_rejects_soft_deleted_company_id(): void
    {
        $company = AsabCompany::create(['name' => 'Gone Co', 'plan' => 'Basic', 'status' => 'active']);
        $company->delete();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => $company->id,
            'name' => 'Orphan Brand',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['companyId']]]);

        $this->assertSame(0, AsabBrand::count());
    }

    public function test_brand_create_links_to_supplied_company(): void
    {
        $company = AsabCompany::create(['name' => 'Real Co', 'plan' => 'Basic', 'status' => 'active']);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => $company->id,
            'name' => 'Linked Brand',
        ]);

        $res->assertStatus(201)->assertJsonPath('companyId', $company->id);

        // No stray company invented when one was supplied.
        $this->assertSame(1, AsabCompany::count());
    }

    public function test_brand_update_resolves_legacy_arabic_plan_alias(): void
    {
        $this->seed(AsabBrandPackageSeeder::class);
        $company = AsabCompany::create(['name' => 'Plan Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'Plan Brand',
            'plan' => 'silver',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/brands/{$brand->id}", ['plan' => 'ذهبي'])
            ->assertStatus(200)
            ->assertJsonPath('plan', 'gold');

        $this->assertSame('gold', $brand->fresh()->plan);
    }

    public function test_brand_update_rejects_unknown_plan(): void
    {
        $this->seed(AsabBrandPackageSeeder::class);
        $company = AsabCompany::create(['name' => 'Plan Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id,
            'name' => 'Plan Brand',
            'plan' => 'silver',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/brands/{$brand->id}", ['plan' => 'nonexistent-package'])
            ->assertStatus(422);

        $this->assertSame('silver', $brand->fresh()->plan);
    }
}
