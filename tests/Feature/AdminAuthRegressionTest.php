<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * Slice 02B — Admin Authentication Regression Coverage.
 *
 * Validates the existing Admin authentication contract:
 * - Login response structure, access & refresh token issuance
 * - Invalid credentials & inactive user rejection
 * - Refresh token rotation, structure, and consumed-token rejection
 * - Logout via valid access token and fail-closed legacy Admin-token revocation
 * - Preservation of unrelated cross-user tokens
 * - Legacy token compatibility with session_id = NULL
 */
class AdminAuthRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabUser $adminUser;
    private string $password = 'Admin@Pass2026!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Assab Holding LLC',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->adminUser = AsabUser::create([
            'name' => 'System Administrator',
            'email' => 'admin@assab.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_admin_login_success_returns_spec_envelope_with_tokens_and_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'accessToken',
                'refreshToken',
                'expiresIn',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'companyId',
                    'roles',
                ],
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['accessToken']);
        $this->assertNotEmpty($data['refreshToken']);
        $this->assertSame(900, $data['expiresIn']);
        $this->assertSame($this->adminUser->id, $data['user']['id']);
        $this->assertSame($this->company->id, $data['user']['companyId']);
    }

    public function test_admin_login_issues_distinct_access_and_refresh_tokens_with_null_session_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);

        $response->assertOk();
        $accessTokenPlain = $response->json('accessToken');
        $refreshTokenPlain = $response->json('refreshToken');

        $this->assertNotSame($accessTokenPlain, $refreshTokenPlain);

        // Introspect DB records
        $tokens = $this->adminUser->tokens()->get();
        $this->assertCount(2, $tokens, 'Login must create exactly 2 PersonalAccessToken records');

        $accessModel = $tokens->firstWhere('name', 'access');
        $refreshModel = $tokens->firstWhere('name', 'refresh');

        $this->assertNotNull($accessModel, 'Access token record must exist in DB');
        $this->assertNotNull($refreshModel, 'Refresh token record must exist in DB');

        // Slice 02 contract: session_id column exists and defaults to NULL for non-operational tokens
        $this->assertNull($accessModel->session_id, 'Access token session_id must be NULL before Slice 03');
        $this->assertNull($refreshModel->session_id, 'Refresh token session_id must be NULL before Slice 03');
    }

    public function test_admin_login_with_invalid_credentials_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertSame(0, $this->adminUser->tokens()->count(), 'No tokens must be issued on failed login');
    }

    public function test_admin_login_with_inactive_user_returns_403(): void
    {
        $inactiveUser = AsabUser::create([
            'name' => 'Inactive User',
            'email' => 'inactive@assab.test',
            'password' => $this->password,
            'status' => 'inactive',
            'company_id' => $this->company->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $inactiveUser->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->assertSame(0, $inactiveUser->tokens()->count());
    }

    public function test_valid_refresh_token_rotates_and_returns_new_token_pair(): void
    {
        // 1. Login to get initial pair
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $loginRes->assertOk();

        $originalRefreshToken = $loginRes->json('refreshToken');

        // 2. Call refresh endpoint
        $refreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $originalRefreshToken,
        ]);

        $refreshRes->assertOk()
            ->assertJsonStructure([
                'accessToken',
                'refreshToken',
                'expiresIn',
            ]);

        $this->assertSame(900, $refreshRes->json('expiresIn'), 'Refresh response must contain expected expiresIn');

        $newAccessToken = $refreshRes->json('accessToken');
        $newRefreshToken = $refreshRes->json('refreshToken');

        $this->assertNotEmpty($newAccessToken);
        $this->assertNotEmpty($newRefreshToken);
        $this->assertNotSame($originalRefreshToken, $newRefreshToken, 'Refresh token must be rotated');

        // 3. Verify original refresh token was deleted (consumed)
        $parsedOriginalToken = PersonalAccessToken::findToken($originalRefreshToken);
        $this->assertNull($parsedOriginalToken, 'Original refresh token must be deleted from DB upon rotation');

        // 4. Verify new tokens are findable and assert ownership, types, names, and session_id
        $accessModel = PersonalAccessToken::findToken($newAccessToken);
        $refreshModel = PersonalAccessToken::findToken($newRefreshToken);

        $this->assertNotNull($accessModel, 'New access token must exist in DB');
        $this->assertNotNull($refreshModel, 'New refresh token must exist in DB');

        // Verify token ownership and tokenable integrity
        $this->assertSame($this->adminUser->getMorphClass(), $accessModel->tokenable_type);
        $this->assertSame($this->adminUser->id, $accessModel->tokenable_id);
        $this->assertTrue($accessModel->tokenable->is($this->adminUser));

        $this->assertSame($this->adminUser->getMorphClass(), $refreshModel->tokenable_type);
        $this->assertSame($this->adminUser->id, $refreshModel->tokenable_id);
        $this->assertTrue($refreshModel->tokenable->is($this->adminUser));

        // Verify token names
        $this->assertSame('access', $accessModel->name);
        $this->assertSame('refresh', $refreshModel->name);

        // Verify session_id = NULL in current Compatibility Mode
        $this->assertNull($accessModel->session_id, 'Access token session_id must be NULL in current compatibility mode');
        $this->assertNull($refreshModel->session_id, 'Refresh token session_id must be NULL in current compatibility mode');
    }

    public function test_invalid_and_consumed_refresh_tokens_are_rejected(): void
    {
        // 1. Malformed / unknown token
        $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => '999|invalid_malformed_token_string',
        ])->assertStatus(401)
          ->assertJsonPath('error.code', 'INVALID_TOKEN');

        // 2. Consume a real refresh token
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $refreshToken = $loginRes->json('refreshToken');

        $firstRefresh = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refreshToken]);
        $firstRefresh->assertOk();

        // 3. Replay of consumed refresh token (replay attack / reuse)
        $replayRefresh = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refreshToken]);
        $replayRefresh->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_admin_logout_revokes_current_access_token_and_returns_204(): void
    {
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $accessToken = $loginRes->json('accessToken');

        // Verify access token can query auth/me
        $this->withToken($accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $this->adminUser->email);

        // Perform logout
        $logoutRes = $this->withToken($accessToken)->postJson('/api/v1/auth/logout');
        $logoutRes->assertStatus(204);

        // Verify token was deleted from database
        $this->assertNull(
            PersonalAccessToken::findToken($accessToken),
            'Logged out access token must be deleted from personal_access_tokens'
        );

        // Clear in-memory cached guard user so Sanctum re-evaluates token
        $this->app['auth']->forgetGuards();

        // Verify revoked token can no longer authenticate
        $this->withToken($accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_legacy_logout_revokes_same_user_familyless_admin_tokens_and_preserves_cross_user_tokens(): void
    {
        config(['admin.session_families_enabled' => false]);

        // Session 1 for adminUser
        $res1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $res1->assertOk();
        $tokenSession1 = $res1->json('accessToken');
        $refreshSession1 = $res1->json('refreshToken');

        // Session 2 for adminUser (e.g., second browser / device)
        $res2 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $res2->assertOk();
        $tokenSession2 = $res2->json('accessToken');
        $refreshSession2 = $res2->json('refreshToken');

        // Create a separate Cashier user with an active token
        $branch = Branch::create(['name' => 'Riyadh Central', 'location' => 'Riyadh']);
        $manager = BranchManager::create([
            'name' => 'Manager User',
            'email' => 'manager.test@assab.test',
            'phone' => '0501234567',
            'password' => 'manager-pass',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $cashier = Cashier::create([
            'name' => 'Active Cashier User',
            'email' => 'cashier.pos@assab.test',
            'phone' => '0599998877',
            'password' => 'cashier-pass',
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => 'active',
        ]);
        $cashierToken = $cashier->createToken('cashier-pos-device')->plainTextToken;

        $this->assertNotNull(PersonalAccessToken::findToken($tokenSession1));
        $this->assertNotNull(PersonalAccessToken::findToken($refreshSession1));
        $this->assertNotNull(PersonalAccessToken::findToken($tokenSession2));
        $this->assertNotNull(PersonalAccessToken::findToken($refreshSession2));
        $this->assertNotNull(PersonalAccessToken::findToken($cashierToken));

        // A familyless legacy Access token cannot identify one device pair. Logout
        // therefore fail-closes over every same-user familyless Admin token.
        $this->withToken($tokenSession1)->postJson('/api/v1/auth/logout')->assertStatus(204);

        $this->assertNull(PersonalAccessToken::findToken($tokenSession1));
        $this->assertNull(PersonalAccessToken::findToken($refreshSession1));
        $this->assertNull(PersonalAccessToken::findToken($tokenSession2));
        $this->assertNull(PersonalAccessToken::findToken($refreshSession2));

        // Reset cached authentication guards between requests
        $this->app['auth']->forgetGuards();

        // Neither same-user legacy Access credential may authenticate after logout.
        $this->withToken($tokenSession1)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenSession2)->getJson('/api/v1/auth/me')->assertStatus(401);

        // A token belonging to a different authenticatable type remains unrelated.
        $this->assertNotNull(
            PersonalAccessToken::findToken($cashierToken),
            'Cashier legacy token must remain active after admin logout'
        );
    }

    public function test_legacy_cashier_and_branch_manager_tokens_operate_with_null_session_id(): void
    {
        $branch = Branch::create(['name' => 'Jeddah Branch', 'location' => 'Jeddah']);

        $manager = BranchManager::create([
            'name' => 'Branch Manager User',
            'email' => 'manager.jeddah@assab.test',
            'phone' => '0544443322',
            'password' => 'manager-pass',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $cashier = Cashier::create([
            'name' => 'Cashier Jeddah',
            'email' => 'cashier.jeddah@assab.test',
            'phone' => '0544443323',
            'password' => 'cashier-pass',
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => 'active',
        ]);

        $managerTokenObj = $manager->createToken('manager-tablet');
        $cashierTokenObj = $cashier->createToken('cashier-counter-1');

        $this->assertNull($managerTokenObj->accessToken->session_id);
        $this->assertNull($cashierTokenObj->accessToken->session_id);

        $expectedIds = [$managerTokenObj->accessToken->id, $cashierTokenObj->accessToken->id];

        $dbTokens = DB::table('personal_access_tokens')
            ->whereIn('id', $expectedIds)
            ->get();

        // P3-C: Assert expected row count and both token IDs present before iterating
        $this->assertCount(2, $dbTokens, 'Expected exactly 2 legacy token records in database');
        $retrievedIds = $dbTokens->pluck('id')->all();
        $this->assertContains($managerTokenObj->accessToken->id, $retrievedIds, 'Manager token ID must be present in database');
        $this->assertContains($cashierTokenObj->accessToken->id, $retrievedIds, 'Cashier token ID must be present in database');

        // Retain existing session_id = NULL checks
        foreach ($dbTokens as $row) {
            $this->assertNull($row->session_id, 'Legacy tokens in DB must carry NULL session_id');
        }
    }
}
