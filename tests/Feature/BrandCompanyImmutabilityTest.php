<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Tests\TestCase;

/**
 * F-03 — a brand's company is immutable through every application path. The
 * brand-actor expense readers (R-01) attribute history by origin_asab_brand_id
 * against brands that are company-consistent at creation; that is safe ONLY while
 * a brand cannot be re-parented to another company. This test LOCKS that
 * invariant: the sole brand-update endpoint must not change company_id, so a
 * cross-company origin can never be manufactured by moving a brand.
 */
class BrandCompanyImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_update_endpoint_cannot_change_company(): void
    {
        $companyA = AsabCompany::create(['name' => 'Co A', 'plan' => 'Basic', 'status' => 'active']);
        $companyB = AsabCompany::create(['name' => 'Co B', 'plan' => 'Basic', 'status' => 'active']);

        $brand = AsabBrand::create([
            'company_id' => $companyA->id, 'name' => 'Brand A', 'abbr' => 'BA',
            'sub_status' => 'active', 'status' => 'active',
        ]);

        // Admin in company A (tenant scope passes for this brand).
        $admin = AsabUser::create([
            'company_id' => $companyA->id, 'name' => 'Admin', 'email' => 'admin.immut@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $admin->id, 'role_key' => 'admin', 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $companyA->id,
            'user_id' => $admin->id,
            'role_key' => 'admin',
            'status' => 'active',
        ]);

        // Attempt to re-parent the brand to company B via the update endpoint.
        $this->actingAs($admin, 'sanctum')
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->patchJson("/api/v1/admin/brands/{$brand->id}", [
                'name' => 'Brand A (renamed)',
                'company_id' => $companyB->id,
            ])
            ->assertStatus(200);

        // company_id is unchanged; only whitelisted fields (name) applied.
        $fresh = $brand->fresh();
        $this->assertSame($companyA->id, $fresh->company_id, 'brand company must be immutable via the update endpoint');
        $this->assertSame('Brand A (renamed)', $fresh->name);
    }
}
