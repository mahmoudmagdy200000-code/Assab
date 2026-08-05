<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «أنشأنا محاسب جديد … لا تظهر أي علامة تجارية ولا يظهر أي
 * إنشاء شيفتات» + `WRONG_TENANT`: the accountant was created with
 * `asab_users.company_id = NULL`, so ResolveTenant refused every company
 * surface (shift configs, live, history) and their brand list was empty. The
 * wizard already requires `brands`, and brands name a company — nothing derived
 * it, and the distribution screen only wrote scope.
 */
class AccountantCompanyBindingTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = AsabUser::create([
            'name' => 'أمين النظام', 'email' => 'admin@acc.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'جورمية كافيه', 'abbr' => 'GC',
            'sub_status' => 'active', 'status' => 'active',
        ]);
    }

    public function test_an_accountant_created_without_a_company_gets_it_from_their_brands(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'نزار عبد القادر',
                'email' => 'nizar@acc.test',
                'role' => 'accountant',
                'brands' => [$this->brand->id],
            ])
            ->assertStatus(201);

        $accountant = AsabUser::findOrFail($response->json('id'));
        $this->assertSame(
            $this->company->id,
            $accountant->company_id,
            'a companyless accountant is inert — every company surface answers WRONG_TENANT',
        );
    }

    public function test_brands_from_another_company_are_refused(): void
    {
        $other = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $otherBrand = AsabBrand::create([
            'company_id' => $other->id, 'name' => 'Other Brand', 'abbr' => 'OB',
            'sub_status' => 'active', 'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'محاسب', 'email' => 'cross@acc.test', 'role' => 'accountant',
                'companyId' => $this->company->id,
                'brands' => [$otherBrand->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRAND_NOT_IN_COMPANY');

        $this->assertNull(AsabUser::firstWhere('email', 'cross@acc.test'));
    }

    public function test_brands_spanning_two_companies_are_refused(): void
    {
        $other = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $otherBrand = AsabBrand::create([
            'company_id' => $other->id, 'name' => 'Other Brand', 'abbr' => 'OB',
            'sub_status' => 'active', 'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'محاسب', 'email' => 'span@acc.test', 'role' => 'accountant',
                'brands' => [$this->brand->id, $otherBrand->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANDS_SPAN_COMPANIES');
    }

    public function test_an_unknown_brand_is_a_validation_error(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'محاسب', 'email' => 'ghost@acc.test', 'role' => 'accountant',
                'brands' => ['019f0000-0000-7000-8000-000000000000'],
            ])
            ->assertStatus(422);
    }

    /** The repair path the client already uses: «توزيع المطاعم». */
    public function test_assigning_brands_from_distribution_binds_the_company(): void
    {
        $accountant = $this->companylessAccountant();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/accountants/{$accountant->id}/assignments", [
                'brands' => [$this->brand->id],
            ])
            ->assertSuccessful();

        $this->assertSame($this->company->id, $accountant->fresh()->company_id);
    }

    public function test_assigning_restaurants_binds_the_company_too(): void
    {
        $accountant = $this->companylessAccountant();
        $restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'جورمية الرياض', 'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/accountants/{$accountant->id}/assignments", [
                'restaurants' => [$restaurant->id],
            ])
            ->assertSuccessful();

        $this->assertSame($this->company->id, $accountant->fresh()->company_id);
    }

    /** The distribution screen needs the head's NAME and the brand chips. */
    public function test_the_distribution_payload_names_the_head_and_the_brands(): void
    {
        $head = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'خالد العمري',
            'email' => 'head@acc.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $head->id, 'role_key' => 'head', 'scope' => 'all']);

        $accountant = $this->companylessAccountant(['reports_to_id' => $head->id]);
        AsabUserRole::where('user_id', $accountant->id)->update(['brand_ids' => [$this->brand->id]]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/distribution')
            ->assertSuccessful()
            ->assertJsonPath('accountants.0.headName', 'خالد العمري')
            ->assertJsonPath('accountants.0.brandsNamed.0.name', 'جورمية كافيه')
            ->assertJsonPath('accountants.0.needsCompany', true);
    }

    public function test_the_repair_command_fills_the_company_from_the_brand_scope(): void
    {
        $accountant = $this->companylessAccountant();
        AsabUserRole::where('user_id', $accountant->id)->update(['brand_ids' => [$this->brand->id]]);

        $this->artisan('asab:repair-user-companies --dry-run')->assertSuccessful();
        $this->assertNull($accountant->fresh()->company_id, 'dry run must not write');

        $this->artisan('asab:repair-user-companies')->assertSuccessful();
        $this->assertSame($this->company->id, $accountant->fresh()->company_id);
    }

    /** A platform role legitimately has no company — the repair must skip it. */
    public function test_the_repair_command_leaves_platform_accounts_alone(): void
    {
        $procurement = AsabUser::create([
            'company_id' => null, 'name' => 'مدير المشتريات', 'email' => 'proc@acc.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $procurement->id, 'role_key' => 'procurement', 'scope' => 'all']);

        $this->artisan('asab:repair-user-companies')->assertSuccessful();

        $this->assertNull($procurement->fresh()->company_id);
    }

    private function companylessAccountant(array $overrides = []): AsabUser
    {
        $user = AsabUser::create(array_merge([
            'company_id' => null, 'name' => 'نزار عبد القادر', 'email' => 'nizar-legacy@acc.test',
            'password' => 'secret-password', 'status' => 'active',
        ], $overrides));
        AsabUserRole::create([
            'user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [], 'restaurant_ids' => [],
        ]);

        return $user;
    }
}
