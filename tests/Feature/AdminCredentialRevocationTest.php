<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\AuthService;
use Modules\Admin\Services\CredentialSyncService;
use Modules\Admin\Services\IdentityMapService;
use Tests\TestCase;

class AdminCredentialRevocationTest extends TestCase
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
            'password' => 'OldSecurePass123!',
            'status' => 'active',
        ]);
    }

    public function test_change_password_updates_password_and_revokes_all_admin_sessions(): void
    {
        $family1 = $this->auth->issueSessionFamily($this->user, deviceName: 'Device 1');
        $family2 = $this->auth->issueSessionFamily($this->user, deviceName: 'Device 2');

        $response = $this->withHeader('Authorization', 'Bearer ' . $family1['accessToken'])
            ->postJson('/api/v1/auth/change-password', [
                'currentPassword' => 'OldSecurePass123!',
                'newPassword' => 'NewSecurePass456!',
            ]);

        $response->assertStatus(204);

        $this->user->refresh();
        $this->assertTrue(Hash::check('NewSecurePass456!', $this->user->password));

        // Both sessions revoked
        $session1 = AdminSession::find($family1['sessionId']);
        $session2 = AdminSession::find($family2['sessionId']);
        $this->assertNotNull($session1->revoked_at);
        $this->assertNotNull($session2->revoked_at);

        // All tokens deleted
        $this->assertSame(0, $this->user->tokens()->count());

        // Refresh with old refresh token fails
        $this->expectException(AsabException::class);
        $this->auth->refresh($family1['refreshToken']);
    }

    public function test_change_password_rejects_incorrect_current_password(): void
    {
        $family = $this->auth->issueSessionFamily($this->user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $family['accessToken'])
            ->postJson('/api/v1/auth/change-password', [
                'currentPassword' => 'WrongPassword123!',
                'newPassword' => 'NewSecurePass456!',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_PASSWORD');

        // Password not changed
        $this->user->refresh();
        $this->assertTrue(Hash::check('OldSecurePass123!', $this->user->password));

        // Session not revoked
        $session = AdminSession::find($family['sessionId']);
        $this->assertNull($session->revoked_at);
    }

    public function test_reset_password_succeeds_before_60_minute_boundary(): void
    {
        $family = $this->auth->issueSessionFamily($this->user);

        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => $token,
            'created_at' => now()->subMinutes(59), // 59 minutes old - valid
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'newPassword' => 'BrandNewPass789!',
        ]);

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertTrue(Hash::check('BrandNewPass789!', $this->user->password));

        // Session revoked and tokens deleted
        $session = AdminSession::find($family['sessionId']);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, $this->user->tokens()->count());

        // Reset token record consumed
        $this->assertDatabaseMissing('password_reset_tokens', ['token' => $token]);
    }

    public function test_reset_password_fails_at_and_after_60_minute_boundary(): void
    {
        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => $token,
            'created_at' => now()->subMinutes(60), // Exactly 60 minutes old - expired
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'newPassword' => 'BrandNewPass789!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');

        // Clear row before testing 61 minutes
        DB::table('password_reset_tokens')->where('email', $this->user->email)->delete();

        // Try at 61 minutes
        $token61 = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => $token61,
            'created_at' => now()->subMinutes(61),
        ]);

        $response61 = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token61,
            'newPassword' => 'BrandNewPass789!',
        ]);

        $response61->assertStatus(422);
        $response61->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_reset_password_is_single_use(): void
    {
        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => $token,
            'created_at' => now()->subMinutes(10),
        ]);

        // First use: succeeds
        $response1 = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'newPassword' => 'FirstResetPass1!',
        ]);
        $response1->assertStatus(200);

        // Second use: fails with 422 INVALID_TOKEN
        $response2 = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'newPassword' => 'SecondResetPass2!',
        ]);
        $response2->assertStatus(422);
        $response2->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_reset_password_does_not_delete_newer_concurrent_reset_token(): void
    {
        $oldToken = Str::random(64);
        $newToken = Str::random(64);

        // Initial token in table
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => $oldToken,
            'created_at' => now()->subMinutes(15),
        ]);

        // A newer reset request arrived and overwrote the table with newToken
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $this->user->email],
            ['token' => $newToken, 'created_at' => now()->subMinutes(1)],
        );

        // Attempting to consume oldToken must fail with INVALID_TOKEN
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $oldToken,
            'newPassword' => 'FirstResetPass1!',
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');

        // The newer token must remain untouched in the database
        $this->assertDatabaseHas('password_reset_tokens', ['token' => $newToken]);

        // And the newer token can be consumed successfully
        $responseNew = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $newToken,
            'newPassword' => 'BrandNewPass789!',
        ]);
        $responseNew->assertStatus(200);
        $this->assertDatabaseMissing('password_reset_tokens', ['token' => $newToken]);
    }

    public function test_old_2fa_challenge_fails_after_password_reset(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => 'BASE32SECRET3232',
            'two_factor_confirmed_at' => now(),
            'two_factor_method' => 'totp',
        ])->save();

        // Initiate login to get 2FA challenge
        $initial = $this->auth->login($this->user->email, 'OldSecurePass123!');
        $this->assertTrue($initial['requires2fa'] ?? false);
        $twoFactorToken = $initial['twoFactorToken'];

        // Now password is reset before completing 2FA
        $resetToken = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => $resetToken,
            'created_at' => now(),
        ]);
        $this->auth->resetPassword($resetToken, 'AfterResetPass456!');

        // Attempt to complete old 2FA challenge
        $this->expectException(AsabException::class);
        $this->auth->login(
            email: '',
            password: '',
            code: '123456',
            twoFactorToken: $twoFactorToken
        );
    }

    public function test_mobile_credential_pull_revokes_active_admin_sessions(): void
    {
        $family = $this->auth->issueSessionFamily($this->user);
        $sessionId = $family['sessionId'];

        // Mock IdentityMapService to link legacy supplier to this admin user
        $identityMock = $this->mock(IdentityMapService::class);
        $identityMock->shouldReceive('dashboardIdFor')
            ->with('supplier', 99)
            ->andReturn($this->user->id);

        $legacyModel = new class extends Model {
            protected $table = 'suppliers';
            public $timestamps = false;
            public function getKey() { return 99; }
        };
        $legacyModel->password = Hash::make('MobileNewPassword789!');

        $syncService = app(CredentialSyncService::class);
        $result = $syncService->pullFromMobile('supplier', $legacyModel);

        $this->assertTrue($result);

        $this->user->refresh();
        $this->assertTrue(Hash::check('MobileNewPassword789!', $this->user->password));

        // Active admin session must be revoked
        $session = AdminSession::find($sessionId);
        $this->assertNotNull($session->revoked_at);

        // Tokens deleted
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_old_2fa_challenge_fails_after_user_deactivation(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => 'BASE32SECRET3232',
            'two_factor_confirmed_at' => now(),
            'two_factor_method' => 'totp',
        ])->save();

        // 1. Initiate 2FA login
        $initial = $this->auth->login($this->user->email, 'OldSecurePass123!');
        $this->assertTrue($initial['requires2fa'] ?? false);
        $twoFactorToken = $initial['twoFactorToken'];

        // 2. User is deactivated before challenge completion
        $this->user->forceFill(['status' => 'inactive'])->save();

        // 3. Completing 2FA fails with USER_INACTIVE (403)
        $this->expectException(AsabException::class);
        try {
            $this->auth->login(
                email: '',
                password: '',
                code: '123456',
                twoFactorToken: $twoFactorToken
            );
        } catch (AsabException $e) {
            $this->assertSame('USER_INACTIVE', $e->errorCode);
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function test_old_2fa_challenge_fails_after_company_suspension(): void
    {
        $company = \Modules\Admin\Models\AsabCompany::create([
            'name' => 'Suspended Co',
            'status' => 'active',
        ]);
        $this->user->forceFill([
            'company_id' => $company->id,
            'two_factor_secret' => 'BASE32SECRET3232',
            'two_factor_confirmed_at' => now(),
            'two_factor_method' => 'totp',
        ])->save();

        // 1. Initiate 2FA login
        $initial = $this->auth->login($this->user->email, 'OldSecurePass123!');
        $this->assertTrue($initial['requires2fa'] ?? false);
        $twoFactorToken = $initial['twoFactorToken'];

        // 2. Company is suspended before challenge completion
        $company->forceFill(['status' => 'suspended'])->save();

        // 3. Completing 2FA fails with USER_INACTIVE (403)
        $this->expectException(AsabException::class);
        try {
            $this->auth->login(
                email: '',
                password: '',
                code: '123456',
                twoFactorToken: $twoFactorToken
            );
        } catch (AsabException $e) {
            $this->assertSame('USER_INACTIVE', $e->errorCode);
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function test_direct_password_plus_code_login_fails_after_password_change(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => 'BASE32SECRET3232',
            'two_factor_confirmed_at' => now(),
            'two_factor_method' => 'totp',
        ])->save();

        // Password changed before login attempt
        $this->user->forceFill(['password' => Hash::make('BrandNewPassword999!')])->save();

        // Direct password + code login using old password must fail 401 INVALID_CREDENTIALS
        $this->expectException(AsabException::class);
        try {
            $this->auth->login(
                email: $this->user->email,
                password: 'OldSecurePass123!',
                code: '123456'
            );
        } catch (AsabException $e) {
            $this->assertSame('INVALID_CREDENTIALS', $e->errorCode);
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }
}
