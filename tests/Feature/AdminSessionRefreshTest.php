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

    public function test_legacy_expired_refresh_is_rejected_without_consuming_it(): void
    {
        $token = $this->adminUser->createToken('refresh', ['*'], now()->subSecond());
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $token->plainTextToken])
            ->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertSame(1, $this->adminUser->tokens()->count());
    }

    public function test_legacy_refresh_rejects_inactive_user_without_consuming_token(): void
    {
        $token = $this->adminUser->createToken('refresh');
        $this->adminUser->update(['status' => 'inactive']);
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $token->plainTextToken])
            ->assertStatus(403)->assertJsonPath('error.code', 'USER_INACTIVE');
        $this->assertSame(1, $this->adminUser->tokens()->count());
    }

    public function test_legacy_refresh_rejects_suspended_company_without_consuming_token(): void
    {
        $token = $this->adminUser->createToken('refresh');
        $this->company->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $token->plainTextToken])
            ->assertStatus(403)->assertJsonPath('error.code', 'USER_INACTIVE');
        $this->assertSame(1, $this->adminUser->tokens()->count());
    }

    public function test_legacy_wildcard_rotation_keeps_response_and_familyless_successor(): void
    {
        config(['admin.session_families_enabled' => true]);
        $token = $this->adminUser->createToken('refresh');
        // Sanctum also accepts the historical secret-only form.
        $plain = explode('|', $token->plainTextToken, 2)[1];
        $result = $this->authService->refresh($plain);
        $this->assertSame(['accessToken', 'refreshToken', 'expiresIn', 'user'], array_keys($result));
        $this->assertSame(900, $result['expiresIn']);
        $this->assertSame($this->adminUser->id, $result['user']['id']);
        $this->assertSame(2, $this->adminUser->tokens()->count());
        foreach ($this->adminUser->tokens()->get() as $successor) {
            $this->assertNull($successor->session_id);
            $this->assertSame(['*'], $successor->abilities);
        }
        $this->assertSame(0, AdminSession::count());
        $this->assertNull(PersonalAccessToken::find($token->accessToken->id));
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $token->plainTextToken])->assertStatus(401);
        $this->assertSame(2, $this->adminUser->tokens()->count());
    }

    public function test_legacy_refresh_successor_cannot_resurrect_after_access_logout(): void
    {
        config(['admin.session_families_enabled' => false]);
        $initial = $this->adminUser->createToken('refresh');
        $rotated = $this->authService->refresh($initial->plainTextToken);

        $this->withToken($rotated['accessToken'])
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertSame(
            0,
            $this->adminUser->tokens()->whereNull('session_id')->whereIn('name', ['access', 'refresh'])->count(),
        );
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $rotated['refreshToken']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
        $this->assertSame(0, $this->adminUser->tokens()->count());
    }

    public function test_legacy_rotation_rolls_back_deleted_token_and_successors(): void
    {
        $token = $this->adminUser->createToken('refresh');
        foreach (['after_delete_old_tokens', 'before_commit'] as $point) {
            try {
                $this->authService->refresh($token->plainTextToken, function ($current) use ($point) {
                    if ($current === $point) {
                        throw new \RuntimeException('LEGACY_ROLLBACK_'.$point);
                    }
                });
                $this->fail('Expected legacy rollback at '.$point);
            } catch (\RuntimeException $e) {
                $this->assertSame('LEGACY_ROLLBACK_'.$point, $e->getMessage());
            }
            $this->assertSame(1, $this->adminUser->tokens()->count());
            $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        }
    }

    public function test_legacy_refresh_rechecks_candidate_after_resolution(): void
    {
        $other = AsabUser::create([
            'name' => 'Other legacy owner', 'email' => 'other_legacy@example.com',
            'password' => 'TestPassword123!', 'status' => 'active',
        ]);
        $mutations = [
            'deleted' => null,
            'hash' => ['token' => hash('sha256', 'different-secret')],
            'owner' => ['tokenable_id' => $other->id],
            'type' => ['tokenable_type' => 'OtherModel'],
            'name' => ['name' => 'access'],
            'family' => ['session_id' => (string) \Illuminate\Support\Str::uuid()],
            'abilities' => ['abilities' => json_encode(['admin:access'])],
            'expiry' => ['expires_at' => now()->subSecond()],
        ];
        foreach ($mutations as $label => $mutation) {
            $token = $this->adminUser->createToken('refresh');
            $state = (object) ['armed' => true];
            // Mutate after the unlocked owner query, before refresh can acquire its locks.
            \Illuminate\Support\Facades\DB::listen(function ($query) use ($state, $mutation, $token) {
                if ($state->armed && str_contains($query->sql, 'select')
                    && str_contains($query->sql, 'asab_users') && ! str_contains($query->sql, 'for update')) {
                    $state->armed = false;
                    $row = \Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('id', $token->accessToken->id);
                    $mutation === null ? $row->delete() : $row->update($mutation);
                }
            });
            try {
                $this->authService->refresh($token->plainTextToken);
                $this->fail('Stale candidate accepted after '.$label);
            } catch (\Modules\Admin\Exceptions\AsabException $e) {
                $this->assertSame($label === 'expiry' ? 'SESSION_EXPIRED' : 'INVALID_TOKEN', $e->errorCode);
            } finally {
                $state->armed = false;
            }
            $this->assertSame(0, $this->adminUser->tokens()->where('name', 'access')->where('id', '!=', $token->accessToken->id)->count(), $label);
            PersonalAccessToken::where('id', $token->accessToken->id)->delete();
        }
    }


    public function test_legacy_refresh_uses_locked_user_state_after_candidate_resolution(): void
    {
        $token = $this->adminUser->createToken('refresh');
        $state = (object) ['armed' => true];
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($state) {
            if ($state->armed && str_contains($query->sql, 'select')
                && str_contains($query->sql, 'asab_users') && ! str_contains($query->sql, 'for update')) {
                $state->armed = false;
                \Illuminate\Support\Facades\DB::table('asab_users')->where('id', $this->adminUser->id)
                    ->update(['status' => 'inactive']);
            }
        });
        try {
            $this->authService->refresh($token->plainTextToken);
            $this->fail('Stale active user was accepted');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('USER_INACTIVE', $e->errorCode);
        } finally {
            $state->armed = false;
        }
        $this->assertSame(1, $this->adminUser->tokens()->count());
    }

}
