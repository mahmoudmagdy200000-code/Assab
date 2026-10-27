<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Plan;
use Tests\TestCase;

/**
 * BE-31: Server-side authorization guard on Brand and Restaurant creation.
 * Only Platform Admin is permitted to create Brands and Restaurants.
 * Company Admin creation entry points must return 403 Forbidden.
 */
class BrandRestaurantCreationGuardTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Plan $plan;
    private CompanySubscription $subscription;
    private AsabBrand $existingBrand;
    private AsabRestaurant $existingRestaurant;
    private AsabUser $companyAdmin;
    private AsabUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Guard Test Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->plan = Plan::create([
            'code' => 'professional',
            'name_ar' => 'احترافي',
            'name_en' => 'Professional',
            'price_monthly' => 100,
            'price_annual' => 1000,
            'annual_discount_pct' => 10,
            'max_branches' => 20,
            'max_users' => 50,
            'max_brands' => 10,
            'max_restaurants' => 20,
            'storage_gb' => 10,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->subscription = CompanySubscription::create([
            'company_id' => $this->company->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
            'current_period_start' => now(),
            'current_period_end' => now()->addYear(),
            'start_date' => now(),
            'auto_renew' => true,
        ]);

        $this->existingBrand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Existing Brand',
            'abbr' => 'EB',
            'color' => '#123456',
            'status' => 'active',
        ]);

        $this->existingRestaurant = AsabRestaurant::create([
            'company_id' => $this->company->id,
            'brand_id' => $this->existingBrand->id,
            'name' => 'Existing Restaurant',
            'city' => 'Riyadh',
            'status' => 'active',
        ]);

        // Company Admin
        $this->companyAdmin = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Company Admin',
            'email' => 'admin@guard-test.invalid',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->companyAdmin->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->companyAdmin->id,
            'status' => 'active',
            'role_key' => 'company-admin',
        ]);

        // Platform Admin (admin role, company_id null)
        $this->platformAdmin = AsabUser::create([
            'company_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform@guard-test.invalid',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->platformAdmin->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);
    }

    public function test_company_admin_post_company_me_brands_returns_403_and_creates_no_record(): void
    {
        $brandCountBefore = AsabBrand::count();

        $response = $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/v1/company/me/brands', [
                'name' => 'Attempted Brand',
                'abbr' => 'ATT',
                'color' => '#FF0000',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertEquals($brandCountBefore, AsabBrand::count());
        $this->assertFalse(AsabBrand::where('name', 'Attempted Brand')->exists());
    }

    public function test_company_admin_post_company_me_restaurants_returns_403_and_creates_no_record(): void
    {
        $restaurantCountBefore = AsabRestaurant::count();

        $response = $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/v1/company/me/restaurants', [
                'brandId' => $this->existingBrand->id,
                'name' => 'Attempted Restaurant',
                'city' => 'Jeddah',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertEquals($restaurantCountBefore, AsabRestaurant::count());
        $this->assertFalse(AsabRestaurant::where('name', 'Attempted Restaurant')->exists());
    }

    public function test_company_admin_get_company_me_brands_preserves_authorized_read(): void
    {
        $response = $this->actingAs($this->companyAdmin, 'sanctum')
            ->getJson('/api/v1/company/me/brands');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'restaurants',
                ],
            ],
            'meta',
        ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('Existing Brand', $data[0]['name']);
    }

    public function test_platform_admin_can_create_brand_via_approved_admin_route(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/brands', [
                'name' => 'Platform Created Brand',
                'abbr' => 'PCB',
                'owner' => 'Owner Name',
                'ownerEmail' => 'owner@pcb.test',
            ]);

        $response->assertStatus(201);
        $this->assertTrue(AsabBrand::where('name', 'Platform Created Brand')->exists());
    }

    public function test_platform_admin_can_create_restaurant_via_approved_admin_route(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson("/api/v1/admin/brands/{$this->existingBrand->id}/restaurants", [
                'name' => 'Platform Created Restaurant',
                'city' => 'Dammam',
            ]);

        $response->assertStatus(201);
        $this->assertTrue(AsabRestaurant::where('name', 'Platform Created Restaurant')->exists());
    }
}
