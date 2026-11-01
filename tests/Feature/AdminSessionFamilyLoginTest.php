<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\AuthService;
use Modules\Admin\Services\TwoFactorService;
use Tests\TestCase;

/**
 * Slice 03 — Admin Session Family Login, 2FA, Remember Me & Expiration Tests.
 *
 * Verifies:
 * - Phase 1: Isolated activation behind config('admin.session_families_enabled')
 * - Phase 2: Session family issuance, exact abilities, UUID, legacy alias, transactions
 * - Phase 3: 24h vs 7d Remember Me, bounded 900s access token, boundary expiration
 * - Phase 4: Secure 2FA challenge binding, atomic consumption, no elevation, backup code single-use
 * - Phase 5: Concurrency protection, user row locks, eligibility re-checks
 */
class AdminSessionFamilyLoginTest extends TestCase
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

        \Modules\Admin\Models\CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['admin.session_families_enabled' => false]);
        parent::tearDown();
    }

    // =========================================================================
    // SECTION A: ADMIN LOGIN & SESSION FAMILY ISSUANCE
    // =========================================================================

    public function test_login_when_families_disabled_uses_legacy_compatibility_mode(): void
    {
        config(['admin.session_families_enabled' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'accessToken',
                'refreshToken',
                'expiresIn',
                'user',
            ])
            ->assertJsonMissing(['sessionId'])
            ->assertJsonMissing(['sessionExpiresAt']);

        $this->assertSame(0, AdminSession::count(), 'No AdminSession must be created in Compatibility Mode');

        $tokens = $this->adminUser->tokens()->get();
        $this->assertCount(2, $tokens);
        foreach ($tokens as $t) {
            $this->assertNull($t->session_id, 'Tokens in Compatibility Mode must have NULL session_id');
        }
    }

    public function test_admin_login_issues_session_family_with_isolated_activation(): void
    {
        config(['admin.session_families_enabled' => true]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'accessToken',
                'refreshToken',
                'expiresIn',
                'sessionId',
                'expiresAt',
                'sessionExpiresAt',
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
        $this->assertNotSame($data['accessToken'], $data['refreshToken']);
        $this->assertSame(900, $data['expiresIn']);
        $this->assertTrue(Str::isUuid($data['sessionId']), 'sessionId must be a valid UUID');
        $this->assertSame($this->adminUser->id, $data['user']['id']);
        $this->assertSame($this->company->id, $data['user']['companyId']);

        // Verify AdminSession in database
        $session = AdminSession::find($data['sessionId']);
        $this->assertNotNull($session, 'AdminSession must exist in DB');
        $this->assertSame($this->adminUser->id, $session->user_id);
        $this->assertSame($this->company->id, $session->company_id);
        $this->assertFalse($session->is_remember_me);
        $this->assertSame(0, $session->generation);
        $this->assertNull($session->revoked_at);

        // Verify tokens in DB
        $tokens = PersonalAccessToken::where('session_id', $session->id)->get();
        $this->assertCount(2, $tokens, 'Session must link exactly two tokens');

        $accessToken = $tokens->firstWhere('name', 'access');
        $refreshToken = $tokens->firstWhere('name', 'refresh');

        $this->assertNotNull($accessToken);
        $this->assertNotNull($refreshToken);

        // Abilities
        $this->assertSame(['admin:access'], $accessToken->abilities, 'Access token must have exactly [admin:access]');
        $this->assertSame(['admin:refresh'], $refreshToken->abilities, 'Refresh token must have exactly [admin:refresh]');
        $this->assertNotContains('*', $accessToken->abilities, 'Access token must not contain wildcard');
        $this->assertNotContains('*', $refreshToken->abilities, 'Refresh token must not contain wildcard');

        // Session references and D09 initial alias
        $this->assertSame($accessToken->id, $session->current_access_token_id);
        $this->assertSame($refreshToken->id, $session->current_refresh_token_id);
        $this->assertSame($accessToken->id, $session->legacy_access_token_id);
    }

    public function test_separate_logins_create_independent_session_families(): void
    {
        config(['admin.session_families_enabled' => true]);

        $res1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $res1->assertOk();
        $sessionId1 = $res1->json('sessionId');

        $res2 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $res2->assertOk();
        $sessionId2 = $res2->json('sessionId');

        $this->assertNotSame($sessionId1, $sessionId2, 'Separate logins must create distinct session IDs');
        $this->assertSame(2, AdminSession::where('user_id', $this->adminUser->id)->count());

        $session1Tokens = PersonalAccessToken::where('session_id', $sessionId1)->pluck('id')->all();
        $session2Tokens = PersonalAccessToken::where('session_id', $sessionId2)->pluck('id')->all();

        $this->assertCount(2, $session1Tokens);
        $this->assertCount(2, $session2Tokens);
        $this->assertEmpty(array_intersect($session1Tokens, $session2Tokens));
    }

    public function test_platform_admin_with_null_company_issues_session_family(): void
    {
        config(['admin.session_families_enabled' => true]);

        $platformUser = AsabUser::create([
            'name' => 'Platform Super Admin',
            'email' => 'super@assab.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $platformUser->email,
            'password' => $this->password,
        ]);

        $response->assertOk();
        $sessionId = $response->json('sessionId');
        $this->assertNotNull($sessionId);

        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($platformUser->id, $session->user_id);
        $this->assertNull($session->company_id, 'Platform admin session must persist with null company_id');
    }

    public function test_failed_login_issues_no_family_and_no_tokens(): void
    {
        config(['admin.session_families_enabled' => true]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertSame(0, AdminSession::count(), 'Failed login must create no AdminSession');
        $this->assertSame(0, PersonalAccessToken::count(), 'Failed login must create no tokens');
    }

    public function test_inactive_user_cannot_receive_session_family(): void
    {
        config(['admin.session_families_enabled' => true]);

        $inactiveUser = AsabUser::create([
            'name' => 'Inactive Administrator',
            'email' => 'inactive.admin@assab.test',
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

        $this->assertSame(0, AdminSession::where('user_id', $inactiveUser->id)->count());
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $inactiveUser->id)->count());
    }

    public function test_suspended_company_user_cannot_receive_session_family(): void
    {
        config(['admin.session_families_enabled' => true]);

        $suspendedCompany = AsabCompany::create([
            'name' => 'Suspended LLC',
            'plan' => 'Enterprise',
            'status' => 'suspended',
        ]);

        $companyUser = AsabUser::create([
            'name' => 'Suspended Company Admin',
            'email' => 'suspended.company@assab.test',
            'password' => $this->password,
            'status' => 'active',
            'company_id' => $suspendedCompany->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $companyUser->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->assertSame(0, AdminSession::where('user_id', $companyUser->id)->count());
    }

    public function test_transaction_rollback_leaves_no_partial_session_or_tokens(): void
    {
        config(['admin.session_families_enabled' => true]);

        /** @var AuthService $authService */
        $authService = app(AuthService::class);

        $initialSessions = AdminSession::count();
        $initialTokens = PersonalAccessToken::count();

        // Simulate a failure inside issuance by hooking into an event or calling a broken transaction
        try {
            DB::transaction(function () use ($authService) {
                $authService->issueSessionFamily($this->adminUser, false);
                throw new \RuntimeException('Simulated post-issuance failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated post-issuance failure', $e->getMessage());
        }

        $this->assertSame($initialSessions, AdminSession::count(), 'Rollback must leave no AdminSession records');
        $this->assertSame($initialTokens, PersonalAccessToken::count(), 'Rollback must leave no PersonalAccessToken records');
    }

    public function test_legacy_access_token_id_alias_is_immutable_once_assigned(): void
    {
        config(['admin.session_families_enabled' => true]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $response->assertOk();
        $sessionId = $response->json('sessionId');

        $session = AdminSession::find($sessionId);
        $originalAlias = $session->legacy_access_token_id;
        $this->assertNotNull($originalAlias);

        // Attempt explicit write to mutate the alias
        $session->update([
            'legacy_access_token_id' => 99999,
            'generation' => 1,
        ]);

        $fresh = $session->fresh();
        $this->assertSame(1, $fresh->generation);
        $this->assertSame($originalAlias, $fresh->legacy_access_token_id, 'legacy_access_token_id must remain immutable once assigned');
    }

    // =========================================================================
    // SECTION B: EXPIRATION AND REMEMBER ME
    // =========================================================================

    public function test_remember_me_omitted_or_false_sets_24h_family_lifetime(): void
    {
        config(['admin.session_families_enabled' => true]);

        $baseTime = Carbon::create(2026, 10, 12, 10, 0, 0);
        Carbon::setTestNow($baseTime);

        // 1. Omitted rememberMe
        $resOmitted = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $resOmitted->assertOk();
        $session1 = AdminSession::find($resOmitted->json('sessionId'));
        $this->assertFalse($session1->is_remember_me);
        $this->assertEquals($baseTime->copy()->addHours(24), $session1->expires_at);
        $this->assertSame($session1->expires_at->toIso8601String(), $resOmitted->json('sessionExpiresAt'));

        // 2. Explicit false
        $resFalse = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => false,
        ]);
        $resFalse->assertOk();
        $session2 = AdminSession::find($resFalse->json('sessionId'));
        $this->assertFalse($session2->is_remember_me);
        $this->assertEquals($baseTime->copy()->addHours(24), $session2->expires_at);
        $this->assertSame($session2->expires_at->toIso8601String(), $resFalse->json('sessionExpiresAt'));
    }

    public function test_remember_me_true_sets_7d_family_lifetime(): void
    {
        config(['admin.session_families_enabled' => true]);

        $baseTime = Carbon::create(2026, 10, 12, 10, 0, 0);
        Carbon::setTestNow($baseTime);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => true,
        ]);
        $response->assertOk();

        $session = AdminSession::find($response->json('sessionId'));
        $this->assertTrue($session->is_remember_me);
        $expectedSessionExpiry = $baseTime->copy()->addDays(7);
        $this->assertEquals($expectedSessionExpiry, $session->expires_at);
        $this->assertSame($session->expires_at->toIso8601String(), $response->json('sessionExpiresAt'));
    }

    public function test_access_token_ttl_bounded_by_900s_and_session_expiration(): void
    {
        config(['admin.session_families_enabled' => true]);

        $baseTime = Carbon::create(2026, 10, 12, 10, 0, 0);
        Carbon::setTestNow($baseTime);

        // Standard 24h login: remaining time = 86400s > 900s
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => false,
        ]);
        $response->assertOk();

        $this->assertSame(900, $response->json('expiresIn'));
        $expectedAccessExpiry = $baseTime->copy()->addSeconds(900);
        $this->assertSame($expectedAccessExpiry->toIso8601String(), $response->json('expiresAt'));

        $session = AdminSession::find($response->json('sessionId'));
        $pat = PersonalAccessToken::find($session->current_access_token_id);
        $this->assertEquals($expectedAccessExpiry, $pat->expires_at);

        $patRefresh = PersonalAccessToken::find($session->current_refresh_token_id);
        $this->assertEquals($session->expires_at, $patRefresh->expires_at);
    }

    public function test_access_token_ttl_bounded_when_session_has_less_than_900s_remaining(): void
    {
        config(['admin.session_families_enabled' => true]);

        $baseTime = Carbon::create(2026, 10, 12, 10, 0, 0);
        Carbon::setTestNow($baseTime);

        /** @var AuthService $authService */
        $authService = app(AuthService::class);

        // Exercise production issueSessionFamily directly with an overrideExpiresAt set to 300s into the future
        $shortExpiry = $baseTime->copy()->addSeconds(300);
        $result = $authService->issueSessionFamily($this->adminUser, false, null, null, null, $shortExpiry);

        $this->assertSame(300, $result['expiresIn']);
        $this->assertEquals($shortExpiry->toIso8601String(), $result['sessionExpiresAt']);

        $session = AdminSession::find($result['sessionId']);
        $patAccess = PersonalAccessToken::find($session->current_access_token_id);
        $this->assertEquals($shortExpiry, $patAccess->expires_at);

        $patRefresh = PersonalAccessToken::find($session->current_refresh_token_id);
        $this->assertEquals($shortExpiry, $patRefresh->expires_at);
    }

    public function test_issue_session_family_rejects_expiration_extension_beyond_policy_maximum(): void
    {
        config(['admin.session_families_enabled' => true]);

        $baseTime = Carbon::create(2026, 10, 12, 10, 0, 0);
        Carbon::setTestNow($baseTime);

        /** @var AuthService $authService */
        $authService = app(AuthService::class);

        // 1. Standard mode (rememberMe = false) cannot exceed 24 hours (e.g. 24h + 1 second or 30 days)
        try {
            $illegal30Days = $baseTime->copy()->addDays(30);
            $authService->issueSessionFamily($this->adminUser, false, null, null, null, $illegal30Days);
            $this->fail('Expected AsabException EXPIRATION_OVERRIDE_EXCEEDED was not thrown for 30-day override');
        } catch (AsabException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('EXPIRATION_OVERRIDE_EXCEEDED', $e->errorCode);
        }

        // 2. Remember Me mode (rememberMe = true) cannot exceed 7 days (e.g. 8 days)
        try {
            $illegal8Days = $baseTime->copy()->addDays(8);
            $authService->issueSessionFamily($this->adminUser, true, null, null, null, $illegal8Days);
            $this->fail('Expected AsabException EXPIRATION_OVERRIDE_EXCEEDED was not thrown for 8-day override');
        } catch (AsabException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('EXPIRATION_OVERRIDE_EXCEEDED', $e->errorCode);
        }
    }

    public function test_session_active_lifecycle_at_expiration_boundary(): void
    {
        $baseTime = Carbon::create(2026, 10, 12, 12, 0, 0);
        Carbon::setTestNow($baseTime);

        $session = AdminSession::create([
            'user_id' => $this->adminUser->id,
            'expires_at' => $baseTime->copy()->addHours(24),
            'revoked_at' => null,
        ]);

        $expiry = $session->expires_at;

        // 1. 1 second before expiration
        Carbon::setTestNow($expiry->copy()->subSecond());
        $this->assertTrue($session->isActive());
        $this->assertFalse($session->isExpired());

        // 2. Exactly at expiration boundary
        Carbon::setTestNow($expiry);
        $this->assertFalse($session->isActive(), 'Session must NOT be active when now == expires_at');
        $this->assertTrue($session->isExpired(), 'Session is expired when now == expires_at');

        // 3. 1 second after expiration
        Carbon::setTestNow($expiry->copy()->addSecond());
        $this->assertFalse($session->isActive());
        $this->assertTrue($session->isExpired());
    }

    public function test_issuance_fails_if_family_has_no_remaining_lifetime(): void
    {
        config(['admin.session_families_enabled' => true]);

        $baseTime = Carbon::create(2026, 10, 12, 12, 0, 0);
        Carbon::setTestNow($baseTime);

        /** @var AuthService $authService */
        $authService = app(AuthService::class);

        try {
            // Invoking production issueSessionFamily where sessionExpiresAt <= now must throw SESSION_EXPIRED
            $authService->issueSessionFamily($this->adminUser, false, null, null, null, $baseTime);
            $this->fail('Expected AsabException was not thrown');
        } catch (AsabException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('SESSION_EXPIRED', $e->errorCode);
        }
    }

    // =========================================================================
    // SECTION C: SECURE 2FA LOGIN
    // =========================================================================

    private function enableTotpForUser(AsabUser $user): string
    {
        /** @var TwoFactorService $twoFactor */
        $twoFactor = app(TwoFactorService::class);
        $setup = $twoFactor->setup($user, 'totp');
        $secret = $setup['secret'];

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);
        $twoFactor->confirm($user->fresh(), $code);

        return $secret;
    }

    public function test_2fa_challenge_creation_issues_no_tokens_and_binds_context(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => true,
        ]);

        $response->assertOk()
            ->assertExactJson([
                'requires2fa' => true,
                'twoFactorToken' => $response->json('twoFactorToken'),
            ]);

        $token = $response->json('twoFactorToken');
        $this->assertNotEmpty($token);
        $this->assertSame(48, strlen($token));

        // Ensure NO tokens and NO AdminSession created yet
        $this->assertSame(0, AdminSession::count(), 'Challenge creation must create NO AdminSession');
        $this->assertSame(0, PersonalAccessToken::count(), 'Challenge creation must create NO tokens');

        // Check cache payload binding
        $challenge = Cache::get('2fa-challenge:'.$token);
        $this->assertIsArray($challenge);
        $this->assertSame($this->adminUser->id, $challenge['user_id']);
        $this->assertTrue($challenge['remember_me'], 'Challenge must bind explicit rememberMe choice');
        $this->assertSame('pending', $challenge['state']);
        $this->assertNotEmpty($challenge['credential_version']);
    }

    public function test_2fa_challenge_completion_issues_session_family(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        // Step 1: Challenge
        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => false,
        ]);
        $step1->assertOk();
        $twoFactorToken = $step1->json('twoFactorToken');

        // Step 2: Completion
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);

        $step2->assertOk()
            ->assertJsonStructure([
                'accessToken',
                'refreshToken',
                'expiresIn',
                'sessionId',
                'expiresAt',
                'sessionExpiresAt',
                'user',
            ]);

        $sessionId = $step2->json('sessionId');
        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($this->adminUser->id, $session->user_id);
        $this->assertFalse($session->is_remember_me);

        // Challenge must be consumed from cache
        $this->assertNull(Cache::get('2fa-challenge:'.$twoFactorToken));
        $this->assertTrue(Cache::get('2fa-consumed:'.$twoFactorToken));
    }

    public function test_2fa_remember_me_cannot_be_upgraded_during_completion(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        // Step 1: rememberMe = false
        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => false,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Step 2: Attempt elevation with rememberMe = true
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
            'rememberMe' => true, // Attempted elevation
        ]);
        $step2->assertOk();

        $session = AdminSession::find($step2->json('sessionId'));
        $this->assertFalse($session->is_remember_me, 'Client must NOT be allowed to upgrade rememberMe during completion');
        $this->assertEquals(now()->addHours(24)->startOfMinute(), $session->expires_at->startOfMinute(), 'Session lifetime must remain 24h, not 7d');
    }

    public function test_2fa_remember_me_true_is_honored_from_step1(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        // Step 1: rememberMe = true
        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'rememberMe' => true,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Step 2: Completion
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step2->assertOk();

        $session = AdminSession::find($step2->json('sessionId'));
        $this->assertTrue($session->is_remember_me);
        $this->assertEquals(now()->addDays(7)->startOfMinute(), $session->expires_at->startOfMinute());
    }

    public function test_2fa_incorrect_code_rejected_and_challenge_consumed(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Step 2: Incorrect code
        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => '000000',
        ]);
        $step2->assertStatus(422)
            ->assertJsonPath('error.code', 'TWO_FACTOR_INVALID_CODE');

        $this->assertSame(0, AdminSession::count());

        // Step 3: Replay with same token even with valid code must fail because challenge was consumed
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        $step3 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $validCode,
        ]);
        $step3->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');
    }

    public function test_2fa_reused_challenge_rejected(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $firstAttempt = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $firstAttempt->assertOk();

        // Replay
        $replayAttempt = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $replayAttempt->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');
    }

    public function test_2fa_expired_challenge_rejected(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Advance time past 300s TTL
        Carbon::setTestNow(now()->addSeconds(301));

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step2->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');
    }

    public function test_2fa_credential_change_between_challenge_and_completion_rejected(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Alter credentials between step 1 and step 2
        $this->adminUser->forceFill(['password' => 'BrandNewPassword2026!'])->save();

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step2->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');

        $this->assertSame(0, AdminSession::count());
    }

    public function test_2fa_inactive_account_at_completion_rejected(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Deactivate user between step 1 and step 2
        $this->adminUser->forceFill(['status' => 'inactive'])->save();

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step2->assertStatus(403)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->assertSame(0, AdminSession::count());
    }

    public function test_2fa_backup_codes_cannot_be_consumed_twice(): void
    {
        config(['admin.session_families_enabled' => true]);

        /** @var TwoFactorService $twoFactor */
        $twoFactor = app(TwoFactorService::class);
        $setup = $twoFactor->setup($this->adminUser, 'totp');
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $confirm = $twoFactor->confirm($this->adminUser->fresh(), $google2fa->getCurrentOtp($setup['secret']));
        $backupCode = $confirm['backupCodes'][0];

        // Login 1 using backup code
        $step1A = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $token1 = $step1A->json('twoFactorToken');

        $complete1 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $token1,
            'code' => $backupCode,
        ]);
        $complete1->assertOk();

        // Login 2 trying to reuse the SAME backup code
        $step1B = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $token2 = $step1B->json('twoFactorToken');

        $complete2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $token2,
            'code' => $backupCode,
        ]);
        $complete2->assertStatus(422)
            ->assertJsonPath('error.code', 'TWO_FACTOR_INVALID_CODE');
    }

    /**
     * Sequential concurrency simulation:
     * Under the test environment (CACHE_STORE=array, DB=sqlite :memory:), this test
     * executes sequential attempts on the same challenge token within the single test process,
     * verifying that atomic invalidation and 2fa-consumed state block subsequent admission.
     * Note: True multi-process worker concurrency across independent OS processes requires
     * a shared external cache store and live database connection.
     */
    public function test_2fa_concurrent_challenge_completion_produces_at_most_one_family(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        /** @var AuthService $authService */
        $authService = app(AuthService::class);

        // Attempt 1: succeeds
        $res1 = $authService->login('', '', $code, $twoFactorToken);
        $this->assertArrayHasKey('sessionId', $res1);

        // Attempt 2: simulates a parallel worker with the same challenge token
        try {
            $authService->login('', '', $code, $twoFactorToken);
            $this->fail('Second attempt must fail with AsabException');
        } catch (AsabException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('TWO_FACTOR_TOKEN_INVALID', $e->errorCode);
        }

        $this->assertSame(1, AdminSession::where('user_id', $this->adminUser->id)->count(), 'Concurrent completion must yield at most one session family');
    }

    public function test_2fa_challenge_created_in_family_mode_cannot_be_completed_in_compatibility_mode(): void
    {
        // 1. Issue challenge in Family Mode
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');
        $this->assertNotNull($twoFactorToken);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        // 2. Switch to Compatibility Mode (Legacy path)
        config(['admin.session_families_enabled' => false]);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);

        $step2->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');

        $this->assertSame(0, AdminSession::count(), 'No session family must be created across mode boundary');
        $this->assertSame(0, $this->adminUser->tokens()->count(), 'No tokens must be issued across mode boundary');
    }

    public function test_2fa_challenge_created_in_compatibility_mode_cannot_be_completed_in_family_mode(): void
    {
        // 1. Issue challenge in Compatibility Mode
        config(['admin.session_families_enabled' => false]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');
        $this->assertNotNull($twoFactorToken);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        // 2. Switch to Family Mode
        config(['admin.session_families_enabled' => true]);

        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);

        $step2->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');

        $this->assertSame(0, AdminSession::count(), 'No session family must be created from legacy challenge');
    }

    public function test_2fa_challenge_consumed_in_family_mode_cannot_be_reused_in_compatibility_mode(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        // Consume in Family Mode
        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step2->assertOk();
        $this->assertSame(1, AdminSession::count());

        // Switch to Compatibility Mode and attempt reuse
        config(['admin.session_families_enabled' => false]);

        $step3 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step3->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');
    }

    public function test_direct_password_and_code_respects_admission_boundary(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
            'code' => $code,
            'rememberMe' => true,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'accessToken',
                'refreshToken',
                'expiresIn',
                'sessionId',
                'expiresAt',
                'sessionExpiresAt',
                'user',
            ]);

        $session = AdminSession::find($response->json('sessionId'));
        $this->assertNotNull($session);
        $this->assertTrue($session->is_remember_me);

        $accessToken = PersonalAccessToken::find($session->current_access_token_id);
        $refreshToken = PersonalAccessToken::find($session->current_refresh_token_id);

        $this->assertSame(['admin:access'], $accessToken->abilities);
        $this->assertSame(['admin:refresh'], $refreshToken->abilities);
    }

    public function test_login_re_evaluates_2fa_under_user_lock_if_enabled_after_preliminary_read(): void
    {
        config(['admin.session_families_enabled' => true]);

        // Ensure user starts with NO 2FA enabled
        $this->adminUser->update([
            'two_factor_method' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_secret' => null,
        ]);
        $this->assertFalse($this->adminUser->twoFactorEnabled());

        // Concurrently enable 2FA on the DB row when preliminary read completes (before lockForUpdate runs)
        $hookTriggered = false;
        \Modules\Admin\Models\AsabUser::retrieved(function ($model) use (&$hookTriggered) {
            if (! $hookTriggered && $model->id === $this->adminUser->id) {
                $hookTriggered = true;
                \Illuminate\Support\Facades\DB::table('asab_users')
                    ->where('id', $model->id)
                    ->update([
                        'two_factor_method' => 'totp',
                        'two_factor_confirmed_at' => now(),
                        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                    ]);
            }
        });

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);

        $this->assertTrue($hookTriggered, 'Concurrency hook must have triggered');

        // Verify that token issuance was halted, requiring 2FA challenge instead
        $response->assertOk()
            ->assertJson([
                'requires2fa' => true,
            ])
            ->assertJsonStructure(['twoFactorToken']);

        $this->assertArrayNotHasKey('accessToken', $response->json());
        $this->assertArrayNotHasKey('sessionId', $response->json());

        // Assert no sessions or tokens were created in this race condition
        $this->assertSame(0, AdminSession::count(), 'No session family must be created when 2FA is re-evaluated under lock');
        $this->assertSame(0, $this->adminUser->tokens()->count(), 'No tokens must be issued when 2FA is re-evaluated under lock');
    }

    public function test_complete_2fa_atomic_conditional_reservation_prevents_duplicate_consumption(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');
        $this->assertNotNull($twoFactorToken);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        // First completion succeeds and consumes challenge atomically
        $step2 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step2->assertOk()
            ->assertJsonStructure(['accessToken', 'refreshToken', 'sessionId']);

        $this->assertSame(1, AdminSession::count());
        $this->assertSame(2, $this->adminUser->tokens()->count());

        // Second completion with identical token must be unconditionally rejected with 401 TWO_FACTOR_TOKEN_INVALID
        $step3 = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);
        $step3->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');

        // Verify still exactly 1 session and 2 tokens exist (no duplicate issuance)
        $this->assertSame(1, AdminSession::count());
        $this->assertSame(2, $this->adminUser->tokens()->count());
    }

    public function test_complete_2fa_rejects_when_atomic_reservation_already_present_simulating_expired_lock(): void
    {
        config(['admin.session_families_enabled' => true]);
        $secret = $this->enableTotpForUser($this->adminUser);

        $step1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminUser->email,
            'password' => $this->password,
        ]);
        $twoFactorToken = $step1->json('twoFactorToken');

        // Simulate Worker A already reserved the challenge via Cache::add before Worker B executes
        \Illuminate\Support\Facades\Cache::add('2fa-consumed:'.$twoFactorToken, true, 300);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $code = $google2fa->getCurrentOtp($secret);

        // Worker B attempts completion: Cache::add fails, must immediately throw TWO_FACTOR_TOKEN_INVALID without touching DB
        $response = $this->postJson('/api/v1/auth/login', [
            'twoFactorToken' => $twoFactorToken,
            'code' => $code,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'TWO_FACTOR_TOKEN_INVALID');

        $this->assertSame(0, AdminSession::count(), 'Worker B must not create any session when reservation fails');
        $this->assertSame(0, $this->adminUser->tokens()->count(), 'Worker B must not issue any tokens when reservation fails');
    }
}
