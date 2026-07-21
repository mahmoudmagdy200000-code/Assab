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
 * Admin "Add Brand" company linkage: the company selector was removed from the
 * form, so every brand now auto-creates a company of its own named after it.
 * Any companyId the client still sends is ignored, never linked.
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

    public function test_brand_create_ignores_supplied_company_id_and_auto_creates_its_own(): void
    {
        // The company selector was removed: a companyId sent by a stale client
        // must NOT link the brand to that company. It auto-creates its own.
        $company = AsabCompany::create(['name' => 'Real Co', 'plan' => 'Basic', 'status' => 'active']);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/brands', [
            'companyId' => $company->id,
            'name' => 'Linked Brand',
        ]);

        $res->assertStatus(201);

        $resolvedCompanyId = $res->json('companyId');
        $this->assertNotSame($company->id, $resolvedCompanyId, 'supplied companyId must be ignored');
        $this->assertSame('Linked Brand', AsabCompany::findOrFail($resolvedCompanyId)->name);
        // The supplied company plus the freshly auto-created one.
        $this->assertSame(2, AsabCompany::count());
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
