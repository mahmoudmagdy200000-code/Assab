<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\AuthService;
use Modules\Notification\Services\DeviceTokenService;
use Tests\TestCase;

class AdminSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $user;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('admin.session_families_enabled', true);
        $this->auth = app(AuthService::class);

        $this->user = AsabUser::create([
            'name' => 'Admin User',
            'email' => 'admin@asab.sa',
            'password' => 'SecurePass123!',
            'status' => 'active',
        ]);
    }

    public function test_normal_bearer_logout_revokes_session_family_and_deletes_both_tokens(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];

        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session);
        $this->assertNull($session->revoked_at);

        $response = $this->withHeader('Authorization', 'Bearer ' . $issued['accessToken'])
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(204);

        $session->refresh();
        $this->assertNotNull($session->revoked_at);

        // Assert both tokens deleted
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $session->current_access_token_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $session->current_refresh_token_id]);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
    }

    public function test_expired_access_logout_with_valid_refresh_token_proof_succeeds(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];

        // Simulate expired access token by setting expires_at in the past
        $accessTokenModel = PersonalAccessToken::findToken($issued['accessToken']);
        $this->assertNotNull($accessTokenModel);
        $accessTokenModel->forceFill(['expires_at' => now()->subMinutes(10)])->save();

        // Expired Bearer header + valid refreshToken body
        $response = $this->withHeader('Authorization', 'Bearer ' . $issued['accessToken'])
            ->postJson('/api/v1/auth/logout', [
                'refreshToken' => $issued['refreshToken'],
            ]);

        $response->assertStatus(204);

        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
    }

    public function test_expired_access_logout_without_bearer_succeeds_with_valid_refresh_token(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];

        $response = $this->postJson('/api/v1/auth/logout', [
            'refreshToken' => $issued['refreshToken'],
        ]);

        $response->assertStatus(204);

        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
    }

    public function test_expired_access_logout_rejects_rotated_or_consumed_refresh_token(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $oldRefreshToken = $issued['refreshToken'];

        // Rotate via refresh
        $refreshed = $this->auth->refresh($oldRefreshToken);

        // Attempt logout with old consumed refresh token
        $response = $this->postJson('/api/v1/auth/logout', [
            'refreshToken' => $oldRefreshToken,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');

        // New active session should remain valid
        $session = AdminSession::find($issued['sessionId']);
        $this->assertNull($session->revoked_at);
        $this->assertDatabaseHas('personal_access_tokens', ['session_id' => $session->id]);
    }

    public function test_logout_rejects_guessed_session_id_or_empty_proof(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);

        // Attempt logout with sessionId only (not a valid proof)
        $response = $this->postJson('/api/v1/auth/logout', [
            'sessionId' => $issued['sessionId'],
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_is_resilient_to_fcm_device_cleanup_failure(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];

        // Mock DeviceTokenService to throw an exception
        $mock = $this->mock(DeviceTokenService::class);
        $mock->shouldReceive('unregister')->andThrow(new \RuntimeException('FCM Service Offline'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $issued['accessToken'])
            ->postJson('/api/v1/auth/logout', [
                'fcm_token' => 'sample_fcm_token_123',
            ]);

        // Revocation must still succeed with 204
        $response->assertStatus(204);

        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
    }

    public function test_logout_of_family_a_does_not_affect_independent_family_b(): void
    {
        $familyA = $this->auth->issueSessionFamily($this->user, deviceName: 'Browser Chrome');
        $familyB = $this->auth->issueSessionFamily($this->user, deviceName: 'Browser Firefox');

        $this->assertNotSame($familyA['sessionId'], $familyB['sessionId']);

        // Logout Family A
        $response = $this->withHeader('Authorization', 'Bearer ' . $familyA['accessToken'])
            ->postJson('/api/v1/auth/logout');
        $response->assertStatus(204);

        // Family A is revoked
        $sessionA = AdminSession::find($familyA['sessionId']);
        $this->assertNotNull($sessionA->revoked_at);

        // Family B is still active and untouched
        $sessionB = AdminSession::find($familyB['sessionId']);
        $this->assertNull($sessionB->revoked_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $sessionB->current_access_token_id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $sessionB->current_refresh_token_id]);

        // Family B can still refresh
        $refreshedB = $this->auth->refresh($familyB['refreshToken']);
        $this->assertNotEmpty($refreshedB['accessToken']);
    }

    public function test_existing_delete_session_by_uuid_revokes_entire_family(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];

        $response = $this->withHeader('Authorization', 'Bearer ' . $issued['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $sessionId);

        $response->assertStatus(204);

        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
    }

    public function test_existing_delete_session_by_numeric_token_id_revokes_entire_family(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];
        $session = AdminSession::find($sessionId);

        // Call DELETE /api/v1/auth/sessions/{numericTokenId} using the access token ID
        $response = $this->withHeader('Authorization', 'Bearer ' . $issued['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $session->current_access_token_id);

        $response->assertStatus(204);

        $session->refresh();
        $this->assertNotNull($session->revoked_at);
        // Both tokens deleted, leaving no orphaned refresh token
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
    }

    public function test_cannot_delete_another_users_session(): void
    {
        $otherUser = AsabUser::create([
            'name' => 'Other User',
            'email' => 'other@asab.sa',
            'password' => 'OtherSecurePass123!',
            'status' => 'active',
        ]);
        $otherIssued = $this->auth->issueSessionFamily($otherUser);

        $myIssued = $this->auth->issueSessionFamily($this->user);

        // Attempt to delete other user's session
        $response = $this->withHeader('Authorization', 'Bearer ' . $myIssued['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $otherIssued['sessionId']);

        $response->assertStatus(204); // Endpoint returns 204 no-content

        // Other user's session MUST NOT be revoked
        $otherSession = AdminSession::find($otherIssued['sessionId']);
        $this->assertNull($otherSession->revoked_at);
        $this->assertSame(2, PersonalAccessToken::where('session_id', $otherIssued['sessionId'])->count());
    }

    public function test_legacy_mode_bearer_logout_deletes_token_cleanly(): void
    {
        Config::set('admin.session_families_enabled', false);

        $token = $this->user->createToken('legacy_token');
        $plain = $token->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(204);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
