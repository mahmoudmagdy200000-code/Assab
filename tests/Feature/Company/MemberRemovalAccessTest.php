<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Services\AuthService;
use Tests\TestCase;

/**
 * Task BE-01 (Rule MEM-01 / Finding BE-N1) Acceptance Test Suite:
 * - Immediate team member revocation & session invalidation
 * - Soft archiving on member removal without row deletion
 * - Rejection of toggling archived members
 * - Fail-closed login & refresh rejection (HTTP 403 MEMBERSHIP_INACTIVE)
 * - Old bearer token rejection (HTTP 401)
 * - Financial & historical data preservation
 * - Multi-tenant and Platform Admin isolation
 */
class MemberRemovalAccessTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;
    private AsabCompany $companyB;
    private AsabUser $adminA;
    private AsabUser $memberA;
    private CompanyUser $companyUserA;
    private string $password = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create([
            'name' => 'Alpha Corporation',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->companyB = AsabCompany::create([
            'name' => 'Beta Corporation',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        // Company Admin for Company A
        $this->adminA = AsabUser::create([
            'name' => 'Admin Alpha',
            'email' => 'admin@alpha.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => $this->companyA->id,
        ]);
        AsabUserRole::create([
            'user_id' => $this->adminA->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);

        // Regular Member (Accountant) in Company A
        $this->memberA = AsabUser::create([
            'name' => 'Accountant Alpha',
            'email' => 'accountant@alpha.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => $this->companyA->id,
        ]);
        AsabUserRole::create([
            'user_id' => $this->memberA->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        $this->companyUserA = CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->memberA->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
    }

    /**
     * 1. Setting member status to inactive immediately revokes all active session families
     * and deletes access/refresh tokens for this company membership.
     */
    public function test_deactivating_member_sets_status_inactive_and_revokes_sessions_and_tokens(): void
    {
        // Issue active session family and tokens for memberA
        $session = AdminSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->memberA->id,
            'company_id' => $this->companyA->id,
            'generation' => 1,
            'expires_at' => now()->addDays(7),
        ]);

        $tokenResult = $this->memberA->createToken('access', ['asab:accountant']);
        $session->update([
            'current_access_token_id' => $tokenResult->accessToken->id,
        ]);
        $tokenResult->accessToken->forceFill(['session_id' => $session->id])->save();

        // Act: Admin deactivates memberA via toggle-status
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$this->companyUserA->id}/toggle-status");

        $response->assertOk()
            ->assertJson([
                'id' => $this->companyUserA->id,
                'status' => 'inactive',
            ]);

        // Assert member status is inactive
        $this->assertSame('inactive', $this->companyUserA->fresh()->status);

        // Assert session family is revoked
        $this->assertNotNull($session->fresh()->revoked_at, 'Session family revoked_at must be set');

        // Assert tokens are deleted
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->memberA->id)->count(), 'Active tokens must be deleted');
    }

    /**
     * 2. Deleting a member executes a soft archive without row deletion, revoking sessions & tokens.
     */
    public function test_archiving_member_via_destroy_sets_status_archived_without_row_deletion_and_revokes_tokens(): void
    {
        $session = AdminSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->memberA->id,
            'company_id' => $this->companyA->id,
            'generation' => 1,
            'expires_at' => now()->addDays(7),
        ]);

        $tokenResult = $this->memberA->createToken('access', ['asab:accountant']);
        $session->update(['current_access_token_id' => $tokenResult->accessToken->id]);
        $tokenResult->accessToken->forceFill(['session_id' => $session->id])->save();

        // Act: Admin removes memberA via destroy
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/v1/company/me/users/{$this->companyUserA->id}");

        $response->assertNoContent();

        // Assert row still exists in database and status is 'archived'
        $this->assertDatabaseHas('asab_company_users', [
            'id' => $this->companyUserA->id,
            'status' => 'archived',
        ]);

        // Assert session family is revoked
        $this->assertNotNull($session->fresh()->revoked_at, 'Session family revoked_at must be set');

        // Assert tokens are deleted
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->memberA->id)->count());
    }

    /**
     * 3. toggleStatus explicitly rejects toggling an archived member with HTTP 409 CANNOT_TOGGLE_ARCHIVED.
     */
    public function test_toggle_status_rejects_archived_member_with_409(): void
    {
        $this->companyUserA->update(['status' => 'archived']);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$this->companyUserA->id}/toggle-status");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CANNOT_TOGGLE_ARCHIVED');

        // Verify status remained archived
        $this->assertSame('archived', $this->companyUserA->fresh()->status);
    }

    /**
     * 4. Both Legacy tokens (session_id = null) and Session Family tokens are invalidated.
     */
    public function test_legacy_tokens_and_session_family_tokens_both_invalidated(): void
    {
        // Legacy token: session_id = null, arbitrary abilities
        $legacyToken = $this->memberA->createToken('access', ['*'])->accessToken;
        $this->assertNull($legacyToken->session_id);

        // Session Family token
        $session = AdminSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->memberA->id,
            'company_id' => $this->companyA->id,
            'generation' => 1,
            'expires_at' => now()->addDays(7),
        ]);
        $familyToken = $this->memberA->createToken('access', ['admin:access'])->accessToken;
        $familyToken->forceFill(['session_id' => $session->id])->save();

        $this->assertSame(2, PersonalAccessToken::where('tokenable_id', $this->memberA->id)->count());

        // Deactivate member
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$this->companyUserA->id}/toggle-status")
            ->assertOk();

        // Both tokens must be completely gone
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->memberA->id)->count());
    }

    /**
     * 5. Any API request using an invalidated access token returns HTTP 401 Unauthorized.
     */
    public function test_old_access_token_rejected_with_401(): void
    {
        $tokenResult = $this->memberA->createToken('access', ['asab:accountant']);
        $plainTextToken = $tokenResult->plainTextToken;

        // Deactivate member
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$this->companyUserA->id}/toggle-status")
            ->assertOk();

        // Reset auth guard in test environment so request authenticates via Bearer header
        app('auth')->forgetGuards();

        // Attempt API request with the old bearer token
        $response = $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/api/v1/company/me/users');

        $response->assertStatus(401);
    }

    /**
     * 6. Login and refresh token attempts for inactive or archived member return HTTP 403 MEMBERSHIP_INACTIVE.
     */
    public function test_login_and_refresh_for_inactive_or_archived_member_fails_with_403_membership_inactive(): void
    {
        // Case 6A: Inactive member
        $this->companyUserA->update(['status' => 'inactive']);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $this->memberA->email,
            'password' => $this->password,
        ]);

        $loginResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Case 6B: Archived member
        $this->companyUserA->update(['status' => 'archived']);

        $loginResponseArchived = $this->postJson('/api/v1/auth/login', [
            'email' => $this->memberA->email,
            'password' => $this->password,
        ]);

        $loginResponseArchived->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Case 6C: Refresh attempt for inactive member
        $refreshObj = $this->memberA->createToken('refresh', ['admin:refresh']);
        $refreshResponse = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $refreshObj->plainTextToken,
        ]);

        $refreshResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');
    }

    /**
     * 7. Historical records and employee references remain 100% intact when member is archived.
     */
    public function test_historical_records_preserved_on_member_archive(): void
    {
        // Archive member
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/v1/company/me/users/{$this->companyUserA->id}")
            ->assertNoContent();

        // Assert member row exists with archived status
        $archivedCu = CompanyUser::find($this->companyUserA->id);
        $this->assertNotNull($archivedCu);
        $this->assertSame('archived', $archivedCu->status);
        $this->assertSame($this->memberA->id, $archivedCu->user_id);
        $this->assertSame($this->companyA->id, $archivedCu->company_id);

        // Assert underlying AsabUser is NOT deleted
        $this->assertNotNull(AsabUser::find($this->memberA->id));
    }

    /**
     * 8. Tenant and Platform Admin isolation:
     * - Platform Admin (company_id = null) is not blocked
     * - Independent memberships in Company B are not affected by deactivation in Company A
     */
    public function test_tenant_and_platform_admin_isolation(): void
    {
        // Platform Admin without company_id
        $platformAdmin = AsabUser::create([
            'name' => 'Platform Superadmin',
            'email' => 'superadmin@platform.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => null,
        ]);
        AsabUserRole::create([
            'user_id' => $platformAdmin->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        // Platform Admin can log in successfully
        $platformLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $platformAdmin->email,
            'password' => $this->password,
        ]);
        $platformLogin->assertOk();

        // User multi-company membership: memberA also has active membership in Company B
        $companyUserB = CompanyUser::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->memberA->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $sessionB = AdminSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->memberA->id,
            'company_id' => $this->companyB->id,
            'generation' => 1,
            'expires_at' => now()->addDays(7),
        ]);
        $tokenB = $this->memberA->createToken('access_company_b', ['asab:accountant']);
        $tokenB->accessToken->forceFill(['session_id' => $sessionB->id])->save();
        $sessionB->update(['current_access_token_id' => $tokenB->accessToken->id]);

        // Deactivate membership in Company A
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$this->companyUserA->id}/toggle-status")
            ->assertOk();

        // Session and token for Company B MUST remain active
        $this->assertNull($sessionB->fresh()->revoked_at, 'Company B session must NOT be revoked');
        $this->assertNotNull(PersonalAccessToken::find($tokenB->accessToken->id), 'Company B token must NOT be deleted');
    }

    /**
     * 9. Security Rule MEM-01: Full Fail-Closed Membership & Platform Admin Matrix:
     * - Company user with company_id but MISSING membership record in asab_company_users:
     *     - Login rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     *     - Token refresh rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     *     - Protected company API access rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     * - Inactive member:
     *     - Login rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     *     - Refresh rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     *     - Protected company API access rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     * - Archived member:
     *     - Login rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     *     - Refresh rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     *     - Protected company API access rejected (HTTP 403 MEMBERSHIP_INACTIVE)
     * - Old tokens after deactivation:
     *     - Revoked/deleted token rejected with HTTP 401 Unauthorized
     * - Valid active company member:
     *     - Login succeeds (HTTP 200 OK)
     *     - Refresh succeeds (HTTP 200 OK)
     *     - Protected company API access allowed (HTTP 200 OK)
     * - Legitimate Platform Admin (company_id === null):
     *     - Login succeeds (HTTP 200 OK)
     *     - Refresh succeeds (HTTP 200 OK)
     *     - Protected company API access fails closed with HTTP 403 WRONG_TENANT (no company)
     */
    public function test_fail_closed_membership_and_platform_admin_security_matrix(): void
    {
        // Case A: Company user with company_id but MISSING membership record
        $userWithoutMembership = AsabUser::create([
            'name' => 'Orphan User',
            'email' => 'orphan@alpha.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => $this->companyA->id,
        ]);
        AsabUserRole::create([
            'user_id' => $userWithoutMembership->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        $this->assertNull(CompanyUser::where('company_id', $this->companyA->id)->where('user_id', $userWithoutMembership->id)->first());

        // Login MUST be rejected with 403 MEMBERSHIP_INACTIVE
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $userWithoutMembership->email,
            'password' => $this->password,
        ]);
        $loginRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Refresh MUST be rejected with 403 MEMBERSHIP_INACTIVE
        $orphanRefresh = $userWithoutMembership->createToken('refresh', ['admin:refresh']);
        $refreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $orphanRefresh->plainTextToken,
        ]);
        $refreshRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Protected company API access with bearer token MUST be rejected with 403 MEMBERSHIP_INACTIVE
        app('auth')->forgetGuards();
        $orphanAccess = $userWithoutMembership->createToken('access', ['admin:access']);
        $apiRes = $this->withHeader('Authorization', 'Bearer '.$orphanAccess->plainTextToken)
            ->getJson('/api/v1/company/me/users');
        $apiRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Case B: Inactive company member
        // Assign company-admin role so authorization passes to the membership check
        $this->memberA->roleAssignments()->delete();
        AsabUserRole::create([
            'user_id' => $this->memberA->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        $this->companyUserA->update(['status' => 'inactive']);

        $inactiveLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $this->memberA->email,
            'password' => $this->password,
        ]);
        $inactiveLogin->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        $inactiveRefresh = $this->memberA->createToken('refresh', ['admin:refresh']);
        $inactiveRefreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $inactiveRefresh->plainTextToken,
        ]);
        $inactiveRefreshRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        app('auth')->forgetGuards();
        $inactiveAccess = $this->memberA->createToken('access', ['admin:access']);
        $inactiveApiRes = $this->withHeader('Authorization', 'Bearer '.$inactiveAccess->plainTextToken)
            ->getJson('/api/v1/company/me/users');
        $inactiveApiRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Case C: Archived company member
        $this->companyUserA->update(['status' => 'archived']);

        $archivedLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $this->memberA->email,
            'password' => $this->password,
        ]);
        $archivedLogin->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        $archivedRefresh = $this->memberA->createToken('refresh', ['admin:refresh']);
        $archivedRefreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $archivedRefresh->plainTextToken,
        ]);
        $archivedRefreshRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        app('auth')->forgetGuards();
        $archivedAccess = $this->memberA->createToken('access', ['admin:access']);
        $archivedApiRes = $this->withHeader('Authorization', 'Bearer '.$archivedAccess->plainTextToken)
            ->getJson('/api/v1/company/me/users');
        $archivedApiRes->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBERSHIP_INACTIVE');

        // Case D: Valid active company member
        $this->companyUserA->update(['status' => 'active']);

        $activeLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $this->memberA->email,
            'password' => $this->password,
        ]);
        $activeLogin->assertOk();
        $this->assertNotEmpty($activeLogin->json('accessToken'));
        $this->assertNotEmpty($activeLogin->json('refreshToken'));

        $activeRefresh = $this->memberA->createToken('refresh', ['admin:refresh']);
        $activeRefreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $activeRefresh->plainTextToken,
        ]);
        $activeRefreshRes->assertOk();

        app('auth')->forgetGuards();
        $activeAccess = $this->memberA->createToken('access', ['admin:access']);
        $activeApiRes = $this->withHeader('Authorization', 'Bearer '.$activeAccess->plainTextToken)
            ->getJson('/api/v1/company/me/users');
        $activeApiRes->assertOk();

        // Case E: Old tokens after deactivation are revoked/deleted and return 401
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/company/me/users/{$this->companyUserA->id}/toggle-status")
            ->assertOk();

        app('auth')->forgetGuards();

        $oldTokenApiRes = $this->withHeader('Authorization', 'Bearer '.$activeAccess->plainTextToken)
            ->getJson('/api/v1/company/me/users');
        $oldTokenApiRes->assertStatus(401);

        // Case F: Legitimate Platform Admin (company_id === null)
        $platformAdmin = AsabUser::create([
            'name' => 'True Platform Admin',
            'email' => 'true.admin@platform.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => null,
        ]);
        AsabUserRole::create([
            'user_id' => $platformAdmin->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        // Platform Admin can log in
        $platformLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $platformAdmin->email,
            'password' => $this->password,
        ]);
        $platformLogin->assertOk();

        // Platform Admin can refresh
        $platformRefresh = $platformAdmin->createToken('refresh', ['admin:refresh']);
        $platformRefreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $platformRefresh->plainTextToken,
        ]);
        $platformRefreshRes->assertOk();

        // Platform Admin (role: admin, company_id: null) CANNOT access company admin surface (403 WRONG_ROLE)
        $platformCompanyApi = $this->actingAs($platformAdmin, 'sanctum')
            ->getJson('/api/v1/company/me/users');
        $platformCompanyApi->assertStatus(403)
            ->assertJsonPath('error.code', 'WRONG_ROLE');

        // Companyless user even with company-admin role CANNOT access company surface (ResolveTenant rejects with 403 WRONG_TENANT)
        $companylessAdmin = AsabUser::create([
            'name' => 'Companyless Admin',
            'email' => 'companyless.admin@platform.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => null,
        ]);
        AsabUserRole::create([
            'user_id' => $companylessAdmin->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        $companylessApi = $this->actingAs($companylessAdmin, 'sanctum')
            ->getJson('/api/v1/company/me/users');
        $companylessApi->assertStatus(403)
            ->assertJsonPath('error.code', 'WRONG_TENANT');
    }
}
