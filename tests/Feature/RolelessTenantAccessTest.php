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

class RolelessTenantAccessTest extends TestCase
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
    private Operation $operation3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company1 = AsabCompany::create([
            'name' => 'Company One',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->company2 = AsabCompany::create([
            'name' => 'Company Two',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->brand1 = AsabBrand::create([
            'company_id' => $this->company1->id,
            'name' => 'Brand One',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->brand2 = AsabBrand::create([
            'company_id' => $this->company2->id,
            'name' => 'Brand Two',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch1 = Branch::factory()->create([
            'name' => 'Branch One',
            'asab_brand_id' => $this->brand1->id,
            'asab_company_id' => $this->company1->id,
        ]);

        $this->branch2 = Branch::factory()->create([
            'name' => 'Branch Two',
            'asab_brand_id' => $this->brand2->id,
            'asab_company_id' => $this->company2->id,
        ]);

        $this->operation1 = Operation::create([
            'public_id' => 'OP-C1-00000001',
            'company_id' => $this->company1->id,
            'branch_id' => $this->branch1->id,
            'module_key' => 'shifts',
            'amount' => 1250000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => [
                'cash_collected' => 1250000,
                'supervisor_name' => 'Supervisor Alpha',
                'secret_sentinel' => 'FINANCIAL_SECRET_ALPHA_999',
            ],
        ]);

        $this->operation2 = Operation::create([
            'public_id' => 'OP-C1-00000002',
            'company_id' => $this->company1->id,
            'branch_id' => $this->branch1->id,
            'module_key' => 'waste',
            'amount' => 45000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => [
                'waste_reason' => 'Spoilage during transport',
                'secret_sentinel' => 'WASTE_SECRET_BETA_888',
            ],
        ]);

        $this->operation3 = Operation::create([
            'public_id' => 'OP-C2-00000003',
            'company_id' => $this->company2->id,
            'branch_id' => $this->branch2->id,
            'module_key' => 'shifts',
            'amount' => 780000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => [
                'cash_collected' => 780000,
                'supervisor_name' => 'Supervisor Gamma',
                'secret_sentinel' => 'COMPANY_TWO_SECRET_777',
            ],
        ]);
    }

    /**
     * Requirement 1: Roleless authenticated user with company is denied GET /operations with 403 WRONG_ROLE.
     */
    public function test_roleless_user_with_company_is_denied_operations_index_with_403_wrong_role(): void
    {
        $rolelessUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Roleless User',
            'email' => 'roleless@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $response = $this->actingAs($rolelessUser, 'sanctum')
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
        $this->assertStringNotContainsString('FINANCIAL_SECRET_ALPHA_999', $content);
        $this->assertStringNotContainsString('WASTE_SECRET_BETA_888', $content);
        $this->assertStringNotContainsString('OP-C1-00000001', $content);
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertArrayNotHasKey('totals', $response->json());
    }

    /**
     * Requirement 2 & 3: Roleless user denied detail, attachments, and audit trail with 403 WRONG_ROLE; no financial data leaked.
     */
    public function test_roleless_user_is_denied_operation_detail_attachments_and_audit_trail(): void
    {
        $rolelessUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Roleless Detail Reader',
            'email' => 'roleless-detail@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // 1. Show operation detail
        $showResp = $this->actingAs($rolelessUser, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}");

        $showResp->assertStatus(403);
        $showResp->assertJsonPath('error.code', 'WRONG_ROLE');
        $this->assertStringNotContainsString('FINANCIAL_SECRET_ALPHA_999', $showResp->getContent());

        // 2. Attachments
        $attachResp = $this->actingAs($rolelessUser, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}/attachments");

        $attachResp->assertStatus(403);
        $attachResp->assertJsonPath('error.code', 'WRONG_ROLE');

        // 3. Audit trail
        $auditResp = $this->actingAs($rolelessUser, 'sanctum')
            ->getJson("/api/v1/operations/{$this->operation1->id}/audit-trail");

        $auditResp->assertStatus(403);
        $auditResp->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Requirement: Roleless user without company cannot bypass via platform mode.
     */
    public function test_roleless_companyless_user_is_denied_with_403_wrong_role(): void
    {
        $companylessUser = AsabUser::create([
            'company_id' => null,
            'name' => 'Companyless Roleless',
            'email' => 'companyless-roleless@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $response = $this->actingAs($companylessUser, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Audit Finding F2-A-SR-01: User with NULL scope fails closed (never normalized to 'all').
     * Tests hydrated model holding scope=null against ResolveTenant fail-closed guard.
     */
    public function test_user_with_null_scope_fails_closed_with_403_wrong_role(): void
    {
        $nullScopeUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Null Scope User',
            'email' => 'null-scope@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $assignment = new AsabUserRole([
            'user_id' => $nullScopeUser->id,
            'role_key' => 'accountant',
            'branch_ids' => [$this->branch1->id],
        ]);
        $assignment->scope = null;
        $nullScopeUser->setRelation('roleAssignments', collect([$assignment]));

        $response = $this->actingAs($nullScopeUser, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
        $this->assertStringNotContainsString('FINANCIAL_SECRET_ALPHA_999', $response->getContent());
    }

    /**
     * Audit Finding F2-A-SR-01: User with invalid/unknown scope fails closed.
     */
    public function test_user_with_invalid_or_unknown_scope_fails_closed_with_403_wrong_role(): void
    {
        $invalidScopeUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Invalid Scope User',
            'email' => 'invalid-scope@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $invalidScopeUser->id,
            'role_key' => 'accountant',
            'scope' => 'unsupported_custom_scope',
            'branch_ids' => [$this->branch1->id],
        ]);

        $response = $this->actingAs($invalidScopeUser, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Requirement: Invalid/unknown persisted role key fails closed.
     */
    public function test_user_with_unknown_role_key_is_denied_with_403_wrong_role(): void
    {
        $unknownRoleUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Unknown Role User',
            'email' => 'unknown-role@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $unknownRoleUser->id,
            'role_key' => 'unknown_custom_role',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($unknownRoleUser, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Requirement: Constrained scope with empty IDs fails closed (cannot default to all).
     */
    public function test_user_with_constrained_scope_but_empty_ids_is_denied_with_403_wrong_role(): void
    {
        $emptyScopeUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Empty Scope User',
            'email' => 'empty-scope@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $emptyScopeUser->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [],
            'brand_ids' => [],
            'restaurant_ids' => [],
        ]);

        $response = $this->actingAs($emptyScopeUser, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Requirement: Malformed scope arrays fail closed without widening or 500 error.
     */
    public function test_user_with_malformed_scope_array_is_denied_with_403_wrong_role(): void
    {
        $malformedUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Malformed Array User',
            'email' => 'malformed-array@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $malformedUser->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [12345, true], // Non-string elements
        ]);

        $response = $this->actingAs($malformedUser, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Audit Finding F2-A-SR-02: Invalid first assignment with valid later Admin must NOT skip to Admin; MUST fail closed with 403 WRONG_ROLE.
     */
    public function test_invalid_first_assignment_with_valid_later_admin_fails_closed_with_403_wrong_role(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Invalid First With Later Admin',
            'email' => 'invalid-first-admin@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // First assignment: invalid role_key
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'corrupted_role',
            'scope' => 'all',
        ]);

        // Later assignment: valid admin
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        // MUST be denied with 403 WRONG_ROLE because first authoritative assignment is invalid
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Audit Finding F2-A-SR-02: Invalid first assignment with valid later non-Admin fails closed with 403 WRONG_ROLE.
     */
    public function test_invalid_first_assignment_with_valid_later_non_admin_fails_closed_with_403_wrong_role(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Invalid First With Later Head',
            'email' => 'invalid-first-head@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // First assignment: invalid role_key
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'corrupted_unknown_role',
            'scope' => 'branch',
            'branch_ids' => [$this->branch1->id],
        ]);

        // Later assignment: valid head
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'head',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Requirement: Valid first assignment is selected; ordering is preserved.
     */
    public function test_valid_first_assignment_with_later_admin_evaluates_first_assignment_and_does_not_skip(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Valid Accountant First With Later Admin',
            'email' => 'valid-first-accountant@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // First assignment: valid accountant scoped to branch1
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branch1->id],
        ]);

        // Second assignment: admin
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        // Request succeeds (200 OK)
        $response->assertOk();
    }

    /**
     * Requirement: Explicit historical scope=all is preserved for valid roles (e.g. head, company-admin).
     */
    public function test_explicit_historical_scope_all_is_preserved_for_valid_roles(): void
    {
        $head = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Historical Head User',
            'email' => 'historical-head@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $head->id,
            'role_key' => 'head',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertOk();
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000001']);
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000002']);
        $response->assertJsonMissing(['publicId' => 'OP-C2-00000003']);
    }

    /**
     * Requirement: Valid Accountant preserves legitimate access to assigned branch.
     */
    public function test_valid_accountant_preserves_legitimate_access_to_assigned_branch(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Valid Accountant',
            'email' => 'valid-accountant@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branch1->id],
        ]);

        $response = $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertOk();
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000001']);
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000002']);
        $response->assertJsonMissing(['publicId' => 'OP-C2-00000003']);
    }

    /**
     * Requirement: Valid Head Accountant preserves legitimate company-wide access.
     */
    public function test_valid_head_preserves_legitimate_company_wide_access(): void
    {
        $head = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Valid Head',
            'email' => 'valid-head@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $head->id,
            'role_key' => 'head',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertOk();
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000001']);
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000002']);
        $response->assertJsonMissing(['publicId' => 'OP-C2-00000003']);
    }

    /**
     * Requirement: Valid Admin preserves cross-company access under existing base semantics.
     */
    public function test_valid_admin_preserves_cross_company_access(): void
    {
        $admin = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Valid Admin',
            'email' => 'valid-admin@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $admin->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertOk();
    }

    /**
     * Requirement: Supplier platform role without company is not blocked by WRONG_ROLE or WRONG_TENANT.
     */
    public function test_supplier_platform_role_without_company_is_permitted(): void
    {
        $supplier = AsabUser::create([
            'company_id' => null,
            'name' => 'Platform Supplier',
            'email' => 'supplier@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $supplier->id,
            'role_key' => 'supplier',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/v1/operations');

        // Not 403 WRONG_ROLE or WRONG_TENANT
        $this->assertNotSame(403, $response->status());
        $response->assertOk();
    }

    /**
     * Requirement: Procurement platform role without company is not blocked by WRONG_ROLE or WRONG_TENANT.
     */
    public function test_procurement_platform_role_without_company_is_permitted(): void
    {
        $procurement = AsabUser::create([
            'company_id' => null,
            'name' => 'Platform Procurement',
            'email' => 'procurement@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $procurement->id,
            'role_key' => 'procurement',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($procurement, 'sanctum')
            ->getJson('/api/v1/operations');

        // Not 403 WRONG_ROLE or WRONG_TENANT
        $this->assertNotSame(403, $response->status());
        $response->assertOk();
    }

    /**
     * Requirement: Valid tenant role without company fails with WRONG_TENANT (retaining existing contract), not WRONG_ROLE.
     */
    public function test_valid_role_without_company_returns_wrong_tenant_not_wrong_role(): void
    {
        $user = AsabUser::create([
            'company_id' => null, // No company
            'name' => 'Companyless Head',
            'email' => 'companyless-head@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'head',
            'scope' => 'all',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        // Fails with WRONG_TENANT, proving WRONG_ROLE does not swallow legitimate WRONG_TENANT
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_TENANT');
    }

    /**
     * Requirement: Missing authentication keeps existing 401 UNAUTHORIZED contract.
     */
    public function test_missing_authentication_keeps_existing_401_unauthorized(): void
    {
        $response = $this->getJson('/api/v1/operations');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * Requirement: Denied requests cause zero financial or business mutations.
     */
    public function test_denied_requests_cause_zero_business_or_financial_mutations(): void
    {
        $rolelessUser = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Zero Mutation Roleless',
            'email' => 'zero-mutation@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $opCountBefore = Operation::count();
        $op1Before = $this->operation1->fresh()->toArray();

        // Send multiple denied requests
        $this->actingAs($rolelessUser, 'sanctum')->getJson('/api/v1/operations')->assertStatus(403);
        $this->actingAs($rolelessUser, 'sanctum')->getJson("/api/v1/operations/{$this->operation1->id}")->assertStatus(403);
        $this->actingAs($rolelessUser, 'sanctum')->getJson("/api/v1/operations/{$this->operation1->id}/attachments")->assertStatus(403);
        $this->actingAs($rolelessUser, 'sanctum')->getJson("/api/v1/operations/{$this->operation1->id}/audit-trail")->assertStatus(403);

        $opCountAfter = Operation::count();
        $op1After = $this->operation1->fresh()->toArray();

        $this->assertSame($opCountBefore, $opCountAfter);
        $this->assertEquals($op1Before, $op1After);
    }

    /**
     * Audit Finding F2-A-SR-03 (Mandatory): scope=branch with empty branch_ids and populated brand_ids
     * MUST fail closed with 403 WRONG_ROLE and NOT escalate to brand-wide visibility.
     */
    public function test_sr03_scope_branch_with_empty_branch_ids_and_populated_brand_ids_fails_closed(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'SR03 Branch With Brand Only User',
            'email' => 'sr03-branch-brand@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [],
            'brand_ids' => [$this->brand1->id],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
        $this->assertStringNotContainsString('FINANCIAL_SECRET_ALPHA_999', $response->getContent());
        $this->assertStringNotContainsString('OP-C1-00000001', $response->getContent());
    }

    /**
     * Audit Finding F2-A-SR-03: scope=branch with valid branch_ids and contradictory brand_ids
     * zeroes out brand_ids in context so TenantBranchResolver cannot escalate to other branches of the brand.
     */
    public function test_sr03_scope_branch_with_valid_branch_and_contradictory_brand_does_not_escalate(): void
    {
        // Create branch 3 under brand 1 in company 1 with an operation
        $branch3 = Branch::factory()->create([
            'name' => 'Branch Three in Brand One',
            'asab_brand_id' => $this->brand1->id,
            'asab_company_id' => $this->company1->id,
        ]);

        $op3 = Operation::create([
            'public_id' => 'OP-C1-00000099',
            'company_id' => $this->company1->id,
            'branch_id' => $branch3->id,
            'module_key' => 'shifts',
            'amount' => 500000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => [
                'cash_collected' => 500000,
                'secret_sentinel' => 'BRANCH_THREE_SECRET_555',
            ],
        ]);

        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'SR03 Branch Contradictory User',
            'email' => 'sr03-branch-contra@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        // Scoped to branch 1, but carries brand 1 in brand_ids
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branch1->id],
            'brand_ids' => [$this->brand1->id],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertOk();
        // Visible: operations for branch 1
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000001']);
        // Hidden: operations for branch 3 of the same brand MUST NOT be visible
        $response->assertJsonMissing(['publicId' => 'OP-C1-00000099']);
        $this->assertStringNotContainsString('BRANCH_THREE_SECRET_555', $response->getContent());
    }

    /**
     * Audit Finding F2-A-SR-03: scope=restaurant with empty restaurant_ids fails closed.
     */
    public function test_sr03_scope_restaurant_with_empty_restaurant_ids_fails_closed(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'SR03 Restaurant Empty User',
            'email' => 'sr03-res-empty@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'restaurant',
            'restaurant_ids' => [],
            'brand_ids' => [$this->brand1->id],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Audit Finding F2-A-SR-03: scope=branch with null branch_ids and populated brand_ids fails closed.
     */
    public function test_sr03_scope_branch_with_null_branch_ids_fails_closed(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Null Branch Array User',
            'email' => 'null-branch-array@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $assignment = new AsabUserRole([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'brand_ids' => [$this->brand1->id],
        ]);
        $assignment->branch_ids = null;
        $user->setRelation('roleAssignments', collect([$assignment]));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    /**
     * Audit Finding F2-A-SR-03: Historical accountant compatibility: scope=brand with empty brand_ids
     * but populated restaurant_ids is permitted and scoped strictly to those restaurants.
     */
    public function test_sr03_historical_accountant_with_scope_brand_and_populated_restaurants_is_permitted(): void
    {
        $restaurant = \Modules\Admin\Models\AsabRestaurant::create([
            'company_id' => $this->company1->id,
            'brand_id' => $this->brand1->id,
            'name' => 'Restaurant Alpha',
            'status' => 'active',
        ]);

        $this->branch1->update(['asab_restaurant_id' => $restaurant->id]);

        $user = AsabUser::create([
            'company_id' => $this->company1->id,
            'name' => 'Historical Accountant Res User',
            'email' => 'historical-acc-res@assab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'accountant',
            'scope' => 'brand',
            'brand_ids' => [],
            'restaurant_ids' => [$restaurant->id],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/operations');

        $response->assertOk();
        $response->assertJsonFragment(['publicId' => 'OP-C1-00000001']);
    }
}
