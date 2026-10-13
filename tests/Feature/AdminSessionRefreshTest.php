<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\AuthService;
use Tests\TestCase;

class AdminSessionRefreshTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabUser $adminUser;
    private AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.session_families_enabled' => true,
        ]);

        $this->company = AsabCompany::create([
            'name' => 'Slice04 Test Co',
            'status' => 'active',
        ]);

        $this->adminUser = AsabUser::create([
            'name' => 'Slice04 Admin',
            'email' => 'slice04_admin@example.com',
            'password' => 'SecretPassword123!',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $this->authService = app(AuthService::class);
    }

    public function test_valid_family_refresh_rotates_and_issues_new_pair(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $oldRefreshTokenPlain = $issued['refreshToken'];
        $oldSessionId = $issued['sessionId'];

        $sessionBefore = AdminSession::findOrFail($oldSessionId);
        $this->assertSame(0, $sessionBefore->generation);
        $oldAccessId = $sessionBefore->current_access_token_id;
        $oldRefreshId = $sessionBefore->current_refresh_token_id;

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $oldRefreshTokenPlain,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertNotEmpty($data['accessToken']);
        $this->assertNotEmpty($data['refreshToken']);
        $this->assertNotEquals($oldRefreshTokenPlain, $data['refreshToken']);
        $this->assertSame($oldSessionId, $data['sessionId']);
        $this->assertLessThanOrEqual(900, $data['expiresIn']);
        $this->assertNotEmpty($data['expiresAt']);
        $this->assertNotEmpty($data['sessionExpiresAt']);

        // Verify Database State
        $sessionAfter = AdminSession::findOrFail($oldSessionId);
        $this->assertSame(1, $sessionAfter->generation);
        $this->assertNotEquals($oldAccessId, $sessionAfter->current_access_token_id);
        $this->assertNotEquals($oldRefreshId, $sessionAfter->current_refresh_token_id);

        // Old tokens must be deleted
        $this->assertNull(PersonalAccessToken::find($oldAccessId));
        $this->assertNull(PersonalAccessToken::find($oldRefreshId));

        // New tokens must exist with exact abilities and session linkage
        $newAccessToken = PersonalAccessToken::findOrFail($sessionAfter->current_access_token_id);
        $this->assertSame(['admin:access'], $newAccessToken->abilities);
        $this->assertSame($oldSessionId, $newAccessToken->session_id);

        $newRefreshToken = PersonalAccessToken::findOrFail($sessionAfter->current_refresh_token_id);
        $this->assertSame(['admin:refresh'], $newRefreshToken->abilities);
        $this->assertSame($oldSessionId, $newRefreshToken->session_id);
    }

    public function test_sequential_double_refresh_on_old_token_fails_401(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $oldRefreshTokenPlain = $issued['refreshToken'];

        // First rotation succeeds
        $res1 = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $oldRefreshTokenPlain,
        ]);
        $res1->assertStatus(200);

        // Replay of old consumed token must fail 401 INVALID_TOKEN
        $res2 = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $oldRefreshTokenPlain,
        ]);
        $res2->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_refresh_with_access_token_fails_401(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $accessTokenPlain = $issued['accessToken'];

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $accessTokenPlain,
        ]);

        $res->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_refresh_with_wildcard_or_wrong_abilities_rejected_401(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $session = AdminSession::findOrFail($issued['sessionId']);

        $tokenRow = PersonalAccessToken::findOrFail($session->current_refresh_token_id);
        $tokenRow->forceFill(['abilities' => ['*']])->save();

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $res->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_refresh_with_expired_family_fails_401(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $session = AdminSession::findOrFail($issued['sessionId']);
        $session->forceFill(['expires_at' => now()->subMinutes(5)])->save();

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $res->assertStatus(401)
            ->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    public function test_refresh_with_revoked_family_fails_401(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $session = AdminSession::findOrFail($issued['sessionId']);
        $session->forceFill(['revoked_at' => now()->subMinute()])->save();

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $res->assertStatus(401)
            ->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    public function test_refresh_with_inactive_user_status_fails_403(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $this->adminUser->forceFill(['status' => 'suspended'])->save();

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('error.code', 'USER_INACTIVE');
    }

    public function test_refresh_with_suspended_company_fails_403(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $this->company->forceFill(['status' => 'suspended'])->save();

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('error.code', 'USER_INACTIVE');
    }

    public function test_legacy_admin_refresh_preserved_in_compatibility_mode(): void
    {
        config(['admin.session_families_enabled' => false]);

        // Create legacy token without session_id
        $legacyNewToken = $this->adminUser->createToken('refresh', ['admin:refresh']);
        $this->assertNull($legacyNewToken->accessToken->session_id);

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $legacyNewToken->plainTextToken,
        ]);

        $res->assertStatus(200);
        $data = $res->json();
        $this->assertNotEmpty($data['accessToken']);
        $this->assertNotEmpty($data['refreshToken']);
        $this->assertSame(900, $data['expiresIn']);
        $this->assertArrayNotHasKey('sessionId', $data);
    }

    public function test_feature_flag_false_does_not_convert_family_token_to_legacy(): void
    {
        // Issue family token while flag is true
        config(['admin.session_families_enabled' => true]);
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);

        // Turn flag off
        config(['admin.session_families_enabled' => false]);

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $res->assertStatus(200);
        $data = $res->json();
        // Must maintain family attributes and generation
        $this->assertSame($issued['sessionId'], $data['sessionId']);
        $session = AdminSession::findOrFail($issued['sessionId']);
        $this->assertSame(1, $session->generation);
    }

    public function test_rollback_injection_points_preserve_exact_database_coherence(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $refreshTokenPlain = $issued['refreshToken'];
        $sessionId = $issued['sessionId'];

        $points = [
            'after_delete_old_tokens',
            'after_create_access_token',
            'after_update_session_pointers',
            'before_commit',
        ];

        foreach ($points as $point) {
            $sessionBefore = AdminSession::findOrFail($sessionId);
            $genBefore = $sessionBefore->generation;
            $oldAccessId = $sessionBefore->current_access_token_id;
            $oldRefreshId = $sessionBefore->current_refresh_token_id;

            try {
                $this->authService->refresh($refreshTokenPlain, function ($currentPoint) use ($point) {
                    if ($currentPoint === $point) {
                        throw new \RuntimeException("INJECTED_ROLLBACK_AT_$point");
                    }
                });
                $this->fail("Expected exception was not thrown for point $point");
            } catch (\RuntimeException $e) {
                $this->assertSame("INJECTED_ROLLBACK_AT_$point", $e->getMessage());
            }

            // Verify database state rolled back completely
            $sessionAfter = AdminSession::findOrFail($sessionId);
            $this->assertSame($genBefore, $sessionAfter->generation);
            $this->assertSame($oldAccessId, $sessionAfter->current_access_token_id);
            $this->assertSame($oldRefreshId, $sessionAfter->current_refresh_token_id);

            $this->assertNotNull(PersonalAccessToken::find($oldAccessId));
            $this->assertNotNull(PersonalAccessToken::find($oldRefreshId));
        }
    }

    public function test_lost_response_after_commit_leaves_single_successor(): void
    {
        $issued = $this->authService->issueSessionFamily($this->adminUser, false);
        $oldRefreshTokenPlain = $issued['refreshToken'];
        $sessionId = $issued['sessionId'];

        // 1. Rotation commits successfully
        $res1 = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $oldRefreshTokenPlain,
        ]);
        $res1->assertStatus(200);
        $newRefreshTokenPlain = $res1->json('refreshToken');

        // Verify exactly one active access token and one active refresh token exist for this session
        $tokens = PersonalAccessToken::where('session_id', $sessionId)->get();
        $this->assertCount(2, $tokens);
        $this->assertSame(1, $tokens->where('name', 'access')->count());
        $this->assertSame(1, $tokens->where('name', 'refresh')->count());

        // 2. Client replaying the old token receives 401, not a second issuance
        $res2 = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $oldRefreshTokenPlain,
        ]);
        $res2->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');

        // Tokens count remains exactly 2
        $this->assertCount(2, PersonalAccessToken::where('session_id', $sessionId)->get());

        // 3. Client using the new rotated token succeeds in rotating to generation 2
        $res3 = $this->postJson('/api/v1/auth/refresh', [
            'refreshToken' => $newRefreshTokenPlain,
        ]);
        $res3->assertStatus(200);
        $sessionAfter = AdminSession::findOrFail($sessionId);
        $this->assertSame(2, $sessionAfter->generation);
    }
}
