<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\ApiKeyService;
use Modules\Admin\Services\AuthService;
use Modules\Notification\Services\DeviceTokenService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdminSessionRevocationTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshDatabaseUsingTrait;
    }

    private AsabUser $user;
    private AuthService $auth;
    private string $barriers;

    /** @var array<int, Process> */
    private array $workers = [];

    /** @var array<int, AsabUser> */
    private array $concurrencyUsers = [];

    public function refreshDatabase(): void
    {
        if (! $this->isConcurrencyTest()) {
            $this->refreshDatabaseUsingTrait();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isConcurrencyTest()
            && ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB/InnoDB independent connections required');
        }
        Config::set('admin.session_families_enabled', true);
        $this->auth = app(AuthService::class);

        $this->user = AsabUser::create([
            'name' => 'Admin User',
            'email' => 'admin@asab.sa',
            'password' => 'SecurePass123!',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ($this->concurrencyUsers as $user) {
            $user->tokens()->delete();
            $user->forceDelete();
        }
        if ($this->isConcurrencyTest() && isset($this->user) && $this->user->exists) {
            $this->user->tokens()->delete();
            $this->user->forceDelete();
        }
        if (isset($this->barriers)) {
            foreach (glob($this->barriers.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->barriers);
        }
        parent::tearDown();
    }

    private function isConcurrencyTest(): bool
    {
        return str_contains($this->name(), 'legacy_logout_concurrency');
    }

    private function setUpConcurrencyEvidence(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertStringStartsWith('s12_fs12_', DB::connection()->getDatabaseName());
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, DB::transactionLevel(), 'Concurrency fixtures must be committed');
        foreach (['asab_users', 'personal_access_tokens'] as $table) {
            $engine = DB::selectOne(
                'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table],
            );
            $this->assertNotNull($engine);
            $this->assertSame('InnoDB', $engine->engine);
        }

        $this->barriers = sys_get_temp_dir().'/s12-fs12-'.Str::uuid();
        mkdir($this->barriers, 0700);
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

    public function test_legacy_access_logout_revokes_all_identifiable_familyless_admin_access_and_refresh_tokens(): void
    {
        Config::set('admin.session_families_enabled', false);
        $access = $this->user->createToken('access');
        $secondAccess = $this->user->createToken('access', ['admin:access']);
        $refresh = $this->user->createToken('refresh');
        $secondRefresh = $this->user->createToken('refresh', ['admin:refresh']);
        $impersonation = $this->user->createToken('impersonation');
        $unrelated = $this->user->createToken('legacy_token');

        $this->withToken($access->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        foreach ([$access, $secondAccess, $refresh, $secondRefresh] as $revoked) {
            $this->assertDatabaseMissing('personal_access_tokens', ['id' => $revoked->accessToken->id]);
        }
        foreach ([$impersonation, $unrelated] as $preserved) {
            $this->assertDatabaseHas('personal_access_tokens', ['id' => $preserved->accessToken->id]);
        }
    }

    public function test_legacy_access_logout_preserves_family_linked_sessions(): void
    {
        $family = $this->auth->issueSessionFamily($this->user, deviceName: 'Independent family');
        $legacyAccess = $this->user->createToken('access');
        $legacyRefresh = $this->user->createToken('refresh');

        $this->withToken($legacyAccess->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        $session = AdminSession::findOrFail($family['sessionId']);
        $this->assertNull($session->revoked_at);
        $this->assertSame(2, PersonalAccessToken::where('session_id', $session->id)->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $legacyAccess->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $legacyRefresh->accessToken->id]);
    }

    public function test_legacy_access_logout_has_no_cross_user_or_cross_company_collateral_and_preserves_api_keys(): void
    {
        $companyA = AsabCompany::create(['name' => 'Company A', 'status' => 'active']);
        $companyB = AsabCompany::create(['name' => 'Company B', 'status' => 'active']);
        $this->user->forceFill(['company_id' => $companyA->id])->save();
        $other = AsabUser::create([
            'name' => 'Other company admin', 'email' => 'other-company@example.test',
            'password' => 'OtherSecurePass123!', 'status' => 'active', 'company_id' => $companyB->id,
        ]);
        $myAccess = $this->user->createToken('access');
        $myRefresh = $this->user->createToken('refresh');
        $otherAccess = $other->createToken('access');
        $otherRefresh = $other->createToken('refresh');
        $apiKey = app(ApiKeyService::class)->create($companyA->id, 'ERP integration', ['operations:read'], null, $this->user->id)['model'];

        $this->withToken($myAccess->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $myAccess->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $myRefresh->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherAccess->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherRefresh->accessToken->id]);
        $this->assertDatabaseHas('asab_api_keys', ['id' => $apiKey->id, 'revoked_at' => null]);
    }

    public function test_d08_does_not_run_user_callback_for_invalid_legacy_refresh_proof(): void
    {
        $refresh = $this->user->createToken('refresh', ['admin:access']);
        $callbackCount = 0;

        try {
            $this->auth->logoutWithRefreshToken($refresh->plainTextToken, function () use (&$callbackCount): void {
                $callbackCount++;
            });
            $this->fail('Invalid refresh proof was accepted');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('INVALID_TOKEN', $e->errorCode);
        }

        $this->assertSame(0, $callbackCount);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $refresh->accessToken->id]);
    }

    public function test_d08_does_not_run_user_callback_for_invalid_family_refresh_proof(): void
    {
        $family = $this->auth->issueSessionFamily($this->user);
        PersonalAccessToken::findToken($family['refreshToken'])
            ->forceFill(['abilities' => ['*']])
            ->save();
        $callbackCount = 0;

        try {
            $this->auth->logoutWithRefreshToken($family['refreshToken'], function () use (&$callbackCount): void {
                $callbackCount++;
            });
            $this->fail('Invalid family refresh proof was accepted');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('INVALID_TOKEN', $e->errorCode);
        }

        $this->assertSame(0, $callbackCount);
        $this->assertNull(AdminSession::findOrFail($family['sessionId'])->revoked_at);
    }

    public function test_d08_rechecks_legacy_refresh_secret_under_locks_before_callback(): void
    {
        $refresh = $this->user->createToken('refresh');
        $callbackCount = 0;
        $state = (object) ['armed' => true];
        DB::listen(function ($query) use ($state, $refresh): void {
            if ($state->armed && str_contains($query->sql, 'select')
                && str_contains($query->sql, 'asab_users') && ! str_contains($query->sql, 'for update')) {
                $state->armed = false;
                DB::table('personal_access_tokens')->where('id', $refresh->accessToken->id)
                    ->update(['token' => hash('sha256', 'changed-after-resolution')]);
            }
        });

        try {
            $this->auth->logoutWithRefreshToken($refresh->plainTextToken, function () use (&$callbackCount): void {
                $callbackCount++;
            });
            $this->fail('Changed legacy refresh proof was accepted');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('INVALID_TOKEN', $e->errorCode);
        } finally {
            $state->armed = false;
        }

        $this->assertSame(0, $callbackCount);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $refresh->accessToken->id]);
    }

    public function test_d08_rechecks_family_refresh_ownership_and_secret_under_locks_before_callback(): void
    {
        $family = $this->auth->issueSessionFamily($this->user);
        $refresh = PersonalAccessToken::findToken($family['refreshToken']);
        $callbackCount = 0;
        $state = (object) ['armed' => true];
        DB::listen(function ($query) use ($state, $refresh): void {
            if ($state->armed && str_contains($query->sql, 'select')
                && str_contains($query->sql, 'asab_users') && ! str_contains($query->sql, 'for update')) {
                $state->armed = false;
                DB::table('personal_access_tokens')->where('id', $refresh->id)
                    ->update(['token' => hash('sha256', 'changed-family-proof')]);
            }
        });

        try {
            $this->auth->logoutWithRefreshToken($family['refreshToken'], function () use (&$callbackCount): void {
                $callbackCount++;
            });
            $this->fail('Changed family refresh proof was accepted');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('INVALID_TOKEN', $e->errorCode);
        } finally {
            $state->armed = false;
        }

        $this->assertSame(0, $callbackCount);
        $this->assertNull(AdminSession::findOrFail($family['sessionId'])->revoked_at);
    }

    public function test_refresh_first_then_d08_logout_with_current_legacy_proof_revokes_all_successors(): void
    {
        Config::set('admin.session_families_enabled', false);
        $initialRefresh = $this->user->createToken('refresh');
        $rotated = $this->auth->refresh($initialRefresh->plainTextToken);
        $unrelated = $this->user->createToken('impersonation');
        $callbackCount = 0;

        $this->auth->logoutWithRefreshToken($rotated['refreshToken'], function (AsabUser $resolved) use (&$callbackCount): void {
            $callbackCount++;
            $this->assertSame($this->user->id, $resolved->id);
        });

        $this->assertSame(1, $callbackCount);
        $this->assertSame(0, $this->user->tokens()->whereNull('session_id')->whereIn('name', ['access', 'refresh'])->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $unrelated->accessToken->id]);
    }

    public function test_consumed_legacy_refresh_proof_is_rejected_without_harming_unrelated_sessions(): void
    {
        Config::set('admin.session_families_enabled', false);
        $initial = $this->user->createToken('refresh');
        $rotated = $this->auth->refresh($initial->plainTextToken);
        $family = $this->auth->issueSessionFamily($this->user, deviceName: 'Unaffected family');

        try {
            $this->auth->logoutWithRefreshToken($initial->plainTextToken);
            $this->fail('Consumed proof was accepted');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('INVALID_TOKEN', $e->errorCode);
        }

        $this->assertNotNull(PersonalAccessToken::findToken($rotated['accessToken']));
        $this->assertNotNull(PersonalAccessToken::findToken($rotated['refreshToken']));
        $this->assertNull(AdminSession::findOrFail($family['sessionId'])->revoked_at);
        $this->assertSame(2, PersonalAccessToken::where('session_id', $family['sessionId'])->count());
    }

    public function test_delete_session_by_legacy_numeric_alias_after_refresh_rotation_revokes_family(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];
        $session = AdminSession::find($sessionId);
        $legacyId = $session->legacy_access_token_id;

        // Perform refresh rotation: this advances generation and deletes the initial access token
        $refreshed = $this->auth->refresh($issued['refreshToken']);
        $this->assertNotEmpty($refreshed['accessToken']);

        // Confirm initial access token was deleted from personal_access_tokens
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $legacyId]);

        // Attempt deletion using legacy numeric ID alias via authenticated bearer (using new accessToken)
        $response = $this->withHeader('Authorization', 'Bearer ' . $refreshed['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $legacyId);

        $response->assertStatus(204);

        $session->refresh();
        $this->assertNotNull($session->revoked_at, 'Session family must be revoked when deleted via legacy alias even after refresh rotation');
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count(), 'All current tokens in family must be deleted');
    }

    public function test_delete_session_by_current_numeric_token_after_refresh_rotation_revokes_family(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user);
        $sessionId = $issued['sessionId'];

        // Perform refresh rotation: advances generation, issues new access token ID
        $refreshed = $this->auth->refresh($issued['refreshToken']);
        $session = AdminSession::find($sessionId);
        $currentAccessId = $session->current_access_token_id;

        // Delete session via current active numeric access token ID
        $response = $this->withHeader('Authorization', 'Bearer ' . $refreshed['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $currentAccessId);

        $response->assertStatus(204);

        $session->refresh();
        $this->assertNotNull($session->revoked_at, 'Session must be revoked when deleted via current numeric token');
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count(), 'All tokens in family must be deleted');
    }

    public function test_cannot_delete_another_users_session_by_numeric_id(): void
    {
        $otherUser = AsabUser::create([
            'name' => 'Other User Numeric',
            'email' => 'other_numeric@asab.sa',
            'password' => 'OtherSecurePass123!',
            'status' => 'active',
        ]);
        $otherIssued = $this->auth->issueSessionFamily($otherUser);
        $otherSession = AdminSession::find($otherIssued['sessionId']);
        $otherLegacyId = $otherSession->legacy_access_token_id;
        $otherCurrentId = $otherSession->current_access_token_id;

        $myIssued = $this->auth->issueSessionFamily($this->user);

        // Attempt 1: Attempt to delete other user's session via other user's legacy token ID
        $response1 = $this->withHeader('Authorization', 'Bearer ' . $myIssued['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $otherLegacyId);
        $response1->assertStatus(204);

        // Attempt 2: Attempt to delete other user's session via other user's current access token ID
        $response2 = $this->withHeader('Authorization', 'Bearer ' . $myIssued['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $otherCurrentId);
        $response2->assertStatus(204);

        // Assert other user's session remains untouched and NOT revoked
        $otherSession->refresh();
        $this->assertNull($otherSession->revoked_at, 'Other user session must NOT be revoked');
        $this->assertSame(2, PersonalAccessToken::where('session_id', $otherSession->id)->count(), 'Other user tokens must remain intact');
    }

    public function test_repeated_delete_session_is_idempotent_and_returns_204(): void
    {
        $issued = $this->auth->issueSessionFamily($this->user, deviceName: 'Target family');
        $independent = $this->auth->issueSessionFamily($this->user, deviceName: 'Independent authenticator');
        $sessionId = $issued['sessionId'];
        $session = AdminSession::find($sessionId);
        $legacyId = $session->legacy_access_token_id;

        // Use a separate family so authentication remains independently valid after each DELETE.
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $independent['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $sessionId);
        $res1->assertStatus(204);

        $res2 = $this->withHeader('Authorization', 'Bearer ' . $independent['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $sessionId);
        $res2->assertStatus(204);

        $res3 = $this->withHeader('Authorization', 'Bearer ' . $independent['accessToken'])
            ->deleteJson('/api/v1/auth/sessions/' . $legacyId);
        $res3->assertStatus(204);

        $session->refresh();
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $sessionId)->count());
        $this->assertNull(AdminSession::findOrFail($independent['sessionId'])->revoked_at);
        $this->assertSame(2, PersonalAccessToken::where('session_id', $independent['sessionId'])->count());
    }

    public function test_delete_familyless_token_by_numeric_id_deletes_token_cleanly(): void
    {
        // Create an unlinked, standalone PersonalAccessToken (no session_id)
        $standaloneToken = $this->user->createToken('standalone_token');
        $plain = $standaloneToken->plainTextToken;
        $tokenId = $standaloneToken->accessToken->id;

        $this->assertNull($standaloneToken->accessToken->session_id);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        // Delete via DELETE /api/v1/auth/sessions/{numericId} using the token itself as Bearer
        $response = $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->deleteJson('/api/v1/auth/sessions/' . $tokenId);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    /** Real-process proof of legacy Refresh/Access-Logout serialization on MariaDB/InnoDB. */
    public function test_legacy_logout_concurrency_refresh_first_removes_committed_successors(): void
    {
        $this->setUpConcurrencyEvidence();
        [$user, $access, $refresh] = $this->fixture();
        $refreshWorker = $this->worker('refresh_first', 'refresh', $user, $access, $refresh, true);
        $this->awaitFile('refresh_first.held');

        $logoutWorker = $this->worker('logout_second', 'logout', $user, $access, $refresh, false);
        $this->awaitLockWait('logout_second');
        $this->release('refresh_first.held_go');

        $refreshResult = $this->workerResult($refreshWorker, 'refresh_first');
        $logoutResult = $this->workerResult($logoutWorker, 'logout_second');
        $this->assertSame('OK', $refreshResult['status']);
        $this->assertSame('OK', $logoutResult['status']);
        $this->assertNotSame($refreshResult['connection_id'], $logoutResult['connection_id']);
        $this->assertSame(0, $user->tokens()->count(), 'Logout must remove the committed refresh successors');
        $this->record('refresh-first/access-logout-second', [$refreshResult, $logoutResult]);
    }

    public function test_legacy_logout_concurrency_logout_first_prevents_waiting_refresh_successors(): void
    {
        $this->setUpConcurrencyEvidence();
        [$user, $access, $refresh] = $this->fixture();
        $logoutWorker = $this->worker('logout_first', 'logout', $user, $access, $refresh, true);
        $this->awaitFile('logout_first.held');

        $refreshWorker = $this->worker('refresh_second', 'refresh', $user, $access, $refresh, false);
        $this->awaitLockWait('refresh_second');
        $this->release('logout_first.held_go');

        $logoutResult = $this->workerResult($logoutWorker, 'logout_first');
        $refreshResult = $this->workerResult($refreshWorker, 'refresh_second');
        $this->assertSame('OK', $logoutResult['status']);
        $this->assertSame('INVALID_TOKEN', $refreshResult['status']);
        $this->assertNotSame($logoutResult['connection_id'], $refreshResult['connection_id']);
        $this->assertSame(0, $user->tokens()->count(), 'No refresh successor may be issued after logout');
        $this->record('access-logout-first/refresh-second', [$logoutResult, $refreshResult]);
    }

    public function test_legacy_logout_concurrency_d08_first_prevents_waiting_refresh_successors(): void
    {
        $this->setUpConcurrencyEvidence();
        [$user, $access, $refresh] = $this->fixture();
        $logoutWorker = $this->worker('d08_first', 'd08_logout', $user, $access, $refresh, true);
        $this->awaitFile('d08_first.held');

        $refreshWorker = $this->worker('d08_refresh_second', 'refresh', $user, $access, $refresh, false);
        $this->awaitLockWait('d08_refresh_second');
        $this->release('d08_first.held_go');

        $logoutResult = $this->workerResult($logoutWorker, 'd08_first');
        $refreshResult = $this->workerResult($refreshWorker, 'd08_refresh_second');
        $this->assertSame('OK', $logoutResult['status']);
        $this->assertSame('INVALID_TOKEN', $refreshResult['status']);
        $this->assertNotSame($logoutResult['connection_id'], $refreshResult['connection_id']);
        $this->assertSame(0, $user->tokens()->count(), 'D08 logout must prevent refresh resurrection');
        $this->record('d08-logout-first/refresh-second', [$logoutResult, $refreshResult]);
    }

    /** @return array{AsabUser, string, string} */
    private function fixture(): array
    {
        $user = AsabUser::create([
            'name' => 'Legacy logout concurrency', 'email' => Str::uuid().'@example.test',
            'password' => 'ConcurrencyPassword123!', 'status' => 'active',
        ]);
        $this->concurrencyUsers[] = $user;
        $issued = app(AuthService::class)->issueTokens($user);

        return [$user, $issued['accessToken'], $issued['refreshToken']];
    }

    private function worker(
        string $name,
        string $action,
        AsabUser $user,
        string $access,
        string $refresh,
        bool $hold,
    ): Process {
        $code = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['admin.session_families_enabled' => false]);
$dir = getenv('S12_BARRIERS');
$name = getenv('S12_NAME');
$action = getenv('S12_ACTION');
$signal = function (string $suffix, string $value = 'ready') use ($dir, $name): void {
    file_put_contents($dir.'/'.$name.'.'.$suffix, $value);
};
$wait = function (string $suffix) use ($dir, $name): void {
    $deadline = microtime(true) + 20;
    while (! is_file($dir.'/'.$name.'.'.$suffix)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout '.$suffix); }
        usleep(10000);
    }
};
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION innodb_lock_wait_timeout = 15');
$connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
$isolation = $db->selectOne('SELECT @@tx_isolation AS level')->level;
$signal('connection', (string) $connection);
$auth = app(Modules\Admin\Services\AuthService::class);
$user = Modules\Admin\Models\AsabUser::findOrFail(getenv('S12_USER'));
$barrierUsed = false;
Illuminate\Support\Facades\DB::listen(function ($query) use ($action, &$barrierUsed, $signal, $wait): void {
    if ($barrierUsed || getenv('S12_HOLD') !== '1') { return; }
    if (in_array($action, ['logout', 'd08_logout'], true) && str_contains($query->sql, 'select')
        && str_contains($query->sql, 'asab_users') && str_contains($query->sql, 'for update')) {
        $barrierUsed = true;
        $signal('held');
        $wait('held_go');
    }
});
try {
    if ($action === 'refresh') {
        $auth->refresh(getenv('S12_REFRESH'), function (string $point) use ($signal, $wait): void {
            if ($point === 'after_delete_old_tokens' && getenv('S12_HOLD') === '1') {
                $signal('held');
                $wait('held_go');
            }
        });
    } elseif ($action === 'logout') {
        $token = Laravel\Sanctum\PersonalAccessToken::findToken(getenv('S12_ACCESS'));
        if (getenv('S12_HOLD') === '1') {
            $db->transaction(fn () => $auth->logout($user, $token));
        } else {
            $auth->logout($user, $token);
        }
    } else {
        if (getenv('S12_HOLD') === '1') {
            $db->transaction(fn () => $auth->logoutWithRefreshToken(getenv('S12_REFRESH')));
        } else {
            $auth->logoutWithRefreshToken(getenv('S12_REFRESH'));
        }
    }
    $status = 'OK';
} catch (Modules\Admin\Exceptions\AsabException $e) {
    $status = $e->errorCode;
}
echo json_encode(['status' => $status, 'connection_id' => $connection, 'isolation' => $isolation, 'action' => $action]);
WORKER;

        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'S12_BARRIERS' => $this->barriers,
            'S12_NAME' => $name,
            'S12_ACTION' => $action,
            'S12_USER' => $user->id,
            'S12_ACCESS' => $access,
            'S12_REFRESH' => $refresh,
            'S12_HOLD' => $hold ? '1' : '0',
        ]);
        $worker->setTimeout(30);
        $worker->start();
        $this->workers[] = $worker;

        return $worker;
    }

    private function awaitFile(string $suffix): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($this->barriers.'/'.$suffix)) {
            if (microtime(true) > $deadline) {
                $errors = implode("\n", array_map(fn (Process $worker) => $worker->getErrorOutput(), $this->workers));
                $this->fail('Barrier timeout '.$suffix.' '.$errors);
            }
            usleep(10000);
        }
    }

    private function release(string $suffix): void
    {
        file_put_contents($this->barriers.'/'.$suffix, 'go');
    }

    private function awaitLockWait(string $worker): void
    {
        $this->awaitFile($worker.'.connection');
        $id = (int) file_get_contents($this->barriers.'/'.$worker.'.connection');
        $deadline = microtime(true) + 10;
        do {
            $waiting = DB::selectOne(
                'SELECT trx_query AS query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = ?',
                [$id, 'LOCK WAIT'],
            );
            if ($waiting) {
                $this->assertStringContainsString('asab_users', $waiting->query);
                fwrite(STDOUT, "\nS12_LOCK_WAIT ".json_encode(['connection_id' => $id, 'table' => 'asab_users'])."\n");

                return;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        $this->fail('Expected an InnoDB user-row lock wait for connection '.$id);
    }

    /** @return array{status: string, connection_id: int, isolation: string, action: string} */
    private function workerResult(Process $worker, string $name): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $name.': '.$worker->getErrorOutput());

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function record(string $scenario, array $results): void
    {
        $record = [
            'scenario' => $scenario,
            'results' => $results,
            'version' => DB::selectOne('SELECT VERSION() AS version')->version,
            'engines' => DB::select(
                "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('asab_users', 'personal_access_tokens')",
            ),
            'token_count' => $this->concurrencyUsers[count($this->concurrencyUsers) - 1]->tokens()->count(),
            'observer_connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            'barrier' => 'observed independent connection waiting on the user row lock',
        ];
        fwrite(STDOUT, "\nS12_FS12_CONCURRENCY ".json_encode($record)."\n");
    }
}

