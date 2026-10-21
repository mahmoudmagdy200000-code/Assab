<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

class ScopedAdminTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company1;
    private AsabCompany $company2;
    private AsabBrand $brand1;
    private AsabBrand $brand2;
    private Branch $branch1;
    private Branch $branch2;
    private Operation $operation1;
    private Operation $operation2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company1 = AsabCompany::create([
            'name' => 'Company Alpha',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->company2 = AsabCompany::create([
            'name' => 'Company Beta',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->brand1 = AsabBrand::create([
            'company_id' => $this->company1->id,
            'name' => 'Brand Alpha',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->brand2 = AsabBrand::create([
            'company_id' => $this->company2->id,
            'name' => 'Brand Beta',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch1 = Branch::factory()->create([
            'name' => 'Branch Alpha 1',
            'asab_brand_id' => $this->brand1->id,
            'asab_company_id' => $this->company1->id,
        ]);

        $this->branch2 = Branch::factory()->create([
            'name' => 'Branch Beta 1',
            'asab_brand_id' => $this->brand2->id,
            'asab_company_id' => $this->company2->id,
        ]);

        $this->operation1 = Operation::create([
            'public_id' => 'OP-ALPHA-00000001',
            'company_id' => $this->company1->id,
            'branch_id' => $this->branch1->id,
            'module_key' => 'shifts',
            'amount' => 1000000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => [
                'cash_collected' => 1000000,
                'secret_sentinel' => 'ALPHA_SECRET_FINANCIAL_111',
            ],
        ]);

        $this->operation2 = Operation::create([
            'public_id' => 'OP-BETA-00000002',
            'company_id' => $this->company2->id,
            'branch_id' => $this->branch2->id,
            'module_key' => 'shifts',
            'amount' => 2000000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => [
                'cash_collected' => 2000000,
                'secret_sentinel' => 'BETA_SECRET_FINANCIAL_222',
            ],
        ]);
    }

    /**
     * Case 1: Multi-Company Scoped Admin
     * An admin assigned to multiple brands spanning multiple companies sees operations across all assigned companies.
     */
    public function test_multi_company_scoped_admin_can_access_operations_across_assigned_companies(): void
    {
        $scopedAdmin = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Multi Company Admin',
            'email' => 'multi-admin@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $scopedAdmin->id,
            'role_key' => 'admin',
            'scope' => 'brand',
            'brand_ids' => [$this->brand1->id, $this->brand2->id],
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['shifts'],
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('OP-ALPHA-00000001', $content);
        $this->assertStringContainsString('OP-BETA-00000002', $content);

        // Show detail returns payload
        $showAlpha = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}");
        $showAlpha->assertStatus(200);
        $this->assertStringContainsString('ALPHA_SECRET_FINANCIAL_111', $showAlpha->getContent());

        $showBeta = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation2->id}");
        $showBeta->assertStatus(200);
        $this->assertStringContainsString('BETA_SECRET_FINANCIAL_222', $showBeta->getContent());
    }

    /**
     * Case 2: Foreign Company Isolation for Scoped Admin
     * A scoped admin restricted to Brand 1 (Company 1) cannot read Company 2 data or target Company 2 via companyId param.
     */
    public function test_scoped_admin_cannot_access_foreign_company_data(): void
    {
        $scopedAdmin = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Single Company Admin',
            'email' => 'scoped-admin-1@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $scopedAdmin->id,
            'role_key' => 'admin',
            'scope' => 'brand',
            'brand_ids' => [$this->brand1->id],
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['shifts'],
            'created_at' => now()->subMinutes(10),
        ]);

        // 1. Index should contain Brand 1, but NOT Brand 2 / Company 2
        $response = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('OP-ALPHA-00000001', $content);
        $this->assertStringNotContainsString('OP-BETA-00000002', $content);

        // Show allowed operation
        $showAllowed = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}");
        $showAllowed->assertStatus(200);
        $this->assertStringContainsString('ALPHA_SECRET_FINANCIAL_111', $showAllowed->getContent());

        // 2. Direct show of foreign operation must fail with 404 (due to BelongsToTenant scope filtering out foreign rows)
        $showResp = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation2->id}");

        $showResp->assertStatus(404);
        $this->assertStringNotContainsString('BETA_SECRET_FINANCIAL_222', $showResp->getContent());

        // 3. Attempting to query exceptions for foreign company must be denied with 403 FORBIDDEN
        $exResp = $this->actingAs($scopedAdmin, 'sanctum')
            ->getJson("/api/v1/exceptions?companyId={$this->company2->id}");

        $exResp->assertStatus(403);
        $exResp->assertJsonPath('error.code', 'FORBIDDEN');
    }

    /**
     * Case 3: Admin Role in Later Grant
     * A user whose first grant is branch-scoped cannot escalate privileges from a later admin grant.
     */
    public function test_user_with_admin_role_in_later_grant_is_constrained_by_first_grant(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Branch User First',
            'email' => 'branch-first@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // First grant: branch role on branch 1
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'branch',
            'scope' => 'branch',
            'brand_ids' => [],
            'restaurant_ids' => [],
            'branch_ids' => [$this->branch1->id],
            'module_keys' => ['shifts'],
            'created_at' => now()->subHours(2),
        ]);

        // Second grant: global admin (created later)
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'admin',
            'scope' => 'all',
            'brand_ids' => [],
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['shifts'],
            'created_at' => now()->subHour(),
        ]);

        // User must NOT have global admin powers; they are bound to the first branch assignment
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(200);
        $content = $response->getContent();
        // Visible: Branch 1 data
        $this->assertStringContainsString('OP-ALPHA-00000001', $content);
        // Inaccessible: Company 2 / Branch 2 data
        $this->assertStringNotContainsString('OP-BETA-00000002', $content);

        // Show allowed branch 1 operation
        $showResp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}");
        $showResp->assertStatus(200);
        $this->assertStringContainsString('ALPHA_SECRET_FINANCIAL_111', $showResp->getContent());
    }

    /**
     * Case 4: Explicit Valid Global Admin
     * An admin with scope=all legitimately reads across companies.
     */
    public function test_explicit_valid_global_admin_can_access_all_companies(): void
    {
        $globalAdmin = AsabUser::create([
            'name' => 'Platform Global Admin',
            'email' => 'global-admin@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $globalAdmin->id,
            'role_key' => 'admin',
            'scope' => 'all',
            'brand_ids' => [],
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['shifts'],
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($globalAdmin, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('OP-ALPHA-00000001', $content);
        $this->assertStringContainsString('OP-BETA-00000002', $content);

        $showAlpha = $this->actingAs($globalAdmin, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}");
        $showAlpha->assertStatus(200);
        $this->assertStringContainsString('ALPHA_SECRET_FINANCIAL_111', $showAlpha->getContent());

        $showBeta = $this->actingAs($globalAdmin, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation2->id}");
        $showBeta->assertStatus(200);
        $this->assertStringContainsString('BETA_SECRET_FINANCIAL_222', $showBeta->getContent());
    }

    /**
     * Case 5: Invalid First Grant Without Promotion
     * If the first persisted assignment is invalid, the request fails closed with 403 WRONG_ROLE
     * and is NOT promoted to a subsequent valid admin assignment.
     */
    public function test_invalid_first_grant_fails_closed_without_promotion_to_later_admin_grant(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Corrupt Grant User',
            'email' => 'corrupt-first@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // First grant: invalid role_key
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'invalid-role-not-recognized',
            'scope' => 'all',
            'brand_ids' => [],
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['shifts'],
            'created_at' => now()->subHours(2),
        ]);

        // Second grant: valid global admin
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'admin',
            'scope' => 'all',
            'brand_ids' => [],
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['shifts'],
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'WRONG_ROLE',
                'message' => 'You do not have permission to access this resource',
                'messageAr' => 'ليس لديك صلاحية للوصول',
            ],
        ]);

        $content = $response->getContent();
        $this->assertStringNotContainsString('ALPHA_SECRET_FINANCIAL_111', $content);
        $this->assertStringNotContainsString('BETA_SECRET_FINANCIAL_222', $content);
    }
}
