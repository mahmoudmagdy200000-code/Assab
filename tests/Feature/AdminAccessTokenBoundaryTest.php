<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\AuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTokenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $user;
    private array $family;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.session_families_enabled' => true]);
        $this->user = AsabUser::create([
            'name' => 'Boundary Admin', 'email' => 'boundary@example.test',
            'password' => 'SecurePassword123!', 'status' => 'active',
        ]);
        $this->user->roleAssignments()->create(['role_key' => 'admin', 'scope' => 'all']);
        $this->family = app(AuthService::class)->issueSessionFamily($this->user);
    }

    public static function protectedRoutes(): array
    {
        return [['/api/v1/auth/me'], ['/api/v1/auth/sessions'], ['/api/v1/admin/companies']];
    }

    #[DataProvider('protectedRoutes')]
    public function test_family_refresh_bearer_is_denied_before_any_mutation(string $path): void
    {
        $before = $this->state();
        $this->withToken($this->family['refreshToken'])->getJson($path)
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN')
            ->assertJsonStructure(['error' => ['code', 'message', 'messageAr'], 'requestId']);
        $this->assertSame($before, $this->state());
    }

    public static function refreshLogoutCases(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('refreshLogoutCases')]
    public function test_refresh_bearer_cannot_logout_even_with_body_proof(bool $body, bool $expired): void
    {
        if ($expired) {
            PersonalAccessToken::findToken($this->family['refreshToken'])->forceFill(['expires_at' => now()->subMinute()])->save();
        }
        $before = $this->state();
        $this->withToken($this->family['refreshToken'])->postJson('/api/v1/auth/logout',
            $body ? ['refreshToken' => $this->family['refreshToken']] : [])
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('protectedRoutes')]
    public function test_valid_family_access_bearer_is_accepted(string $path): void
    {
        $this->withToken($this->family['accessToken'])->getJson($path)->assertOk();
    }

    public function test_valid_family_access_bearer_logout_revokes_family(): void
    {
        $this->withToken($this->family['accessToken'])->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertNotNull(AdminSession::find($this->family['sessionId'])->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $this->family['sessionId'])->count());
    }

    public static function invalidAccessStates(): array
    {
        return array_map(fn ($state) => [$state], [
            'name', 'abilities', 'wildcard', 'extra_ability', 'missing_family', 'wrong_owner',
            'wrong_pointer', 'revoked_family', 'expired_family', 'family_expiry_boundary', 'non_asab_owner',
            'expired_token', 'token_expiry_boundary', 'null_token_expiry',
        ]);
    }

    #[DataProvider('invalidAccessStates')]
    public function test_invalid_family_access_is_denied_without_mutation(string $state): void
    {
        $token = PersonalAccessToken::findToken($this->family['accessToken']);
        $session = AdminSession::find($this->family['sessionId']);
        match ($state) {
            'name' => $token->forceFill(['name' => 'other'])->save(),
            'abilities' => $token->forceFill(['abilities' => ['admin:refresh']])->save(),
            'wildcard' => $token->forceFill(['abilities' => ['*']])->save(),
            'extra_ability' => $token->forceFill(['abilities' => ['admin:access', 'admin:refresh']])->save(),
            'missing_family' => $token->forceFill(['session_id' => '00000000-0000-4000-8000-000000000000'])->save(),
            'wrong_owner' => $session->forceFill(['user_id' => AsabUser::create([
                'name' => 'Other', 'email' => 'other@example.test', 'password' => 'Password123!', 'status' => 'active',
            ])->id])->save(),
            'wrong_pointer' => $session->forceFill(['current_access_token_id' => $session->current_refresh_token_id])->save(),
            'revoked_family' => $session->forceFill(['revoked_at' => now()])->save(),
            'expired_family' => $session->forceFill(['expires_at' => now()->subMinute()])->save(),
            'family_expiry_boundary' => $this->expireFamilyAtBoundary($session),
            'non_asab_owner' => $token->forceFill([
                'tokenable_type' => \App\Models\User::class,
                'tokenable_id' => \App\Models\User::factory()->create()->id,
            ])->save(),
            'expired_token' => $token->forceFill(['expires_at' => now()->subMinute()])->save(),
            'token_expiry_boundary' => $this->expireAtBoundary($token),
            'null_token_expiry' => $token->forceFill(['expires_at' => null])->save(),
        };
        $before = $this->state();
        $this->withToken($this->family['accessToken'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertSame($before, $this->state());
        // Bad family linkage/type must also never enter Bearer Logout.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->family['accessToken'])->postJson('/api/v1/auth/logout')->assertStatus(401);
        $this->assertSame($before, $this->state());
    }

    private function expireAtBoundary(PersonalAccessToken $token): bool
    {
        $this->travelTo(now()->startOfSecond());
        return $token->forceFill(['expires_at' => now()])->save();
    }

    private function expireFamilyAtBoundary(AdminSession $session): bool
    {
        $this->travelTo(now()->startOfSecond());
        return $session->forceFill(['expires_at' => now()])->save();
    }

    public function test_hash_only_refresh_bearer_representation_cannot_bypass_boundary(): void
    {
        [, $secret] = explode('|', $this->family['refreshToken'], 2);
        $before = $this->state();
        $this->withToken($secret)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->postJson('/api/v1/auth/logout', ['refreshToken' => $this->family['refreshToken']])->assertStatus(401);
        $this->assertSame($before, $this->state());
    }

    public function test_expired_access_bearer_does_not_authorize_invalid_d08_body(): void
    {
        $this->expireAtBoundary(PersonalAccessToken::findToken($this->family['accessToken']));
        $before = $this->state();
        $this->withToken($this->family['accessToken'])->postJson('/api/v1/auth/logout', ['refreshToken' => 'invalid'])->assertStatus(401);
        $this->assertSame($before, $this->state());
    }

    public static function d08Cases(): array
    {
        return [['none'], ['expired'], ['boundary']];
    }

    #[DataProvider('d08Cases')]
    public function test_d08_body_proof_works_without_valid_access_bearer(string $bearerState): void
    {
        if ($bearerState !== 'none') {
            $token = PersonalAccessToken::findToken($this->family['accessToken']);
            if ($bearerState === 'boundary') {
                $this->expireAtBoundary($token);
            } else {
                $token->forceFill(['expires_at' => now()->subMinute()])->save();
            }
            $this->withToken($this->family['accessToken']);
        }
        $this->postJson('/api/v1/auth/logout', ['refreshToken' => $this->family['refreshToken']])->assertNoContent();
        $this->assertNotNull(AdminSession::find($this->family['sessionId'])->revoked_at);
        $this->assertSame(0, PersonalAccessToken::where('session_id', $this->family['sessionId'])->count());
    }

    public static function legacyAccessCases(): array
    {
        return [['access', false], ['access', true]];
    }

    #[DataProvider('legacyAccessCases')]
    public function test_familyless_access_compatibility_is_preserved(string $name, bool $flag): void
    {
        config(['admin.session_families_enabled' => $flag]);
        $legacy = $this->user->createToken($name);
        $this->withToken($legacy->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($legacy->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertNull(PersonalAccessToken::find($legacy->accessToken->id));
        $this->assertNull(AdminSession::find($this->family['sessionId'])->revoked_at);
    }

    public static function familylessRefreshCases(): array
    {
        return [
            [false, ['*']],
            [false, ['admin:refresh']],
            [true, ['*']],
            [true, ['admin:refresh']],
        ];
    }

    #[DataProvider('familylessRefreshCases')]
    public function test_familyless_refresh_bearer_is_denied_on_protected_admin_apis(bool $flag, array $abilities): void
    {
        config(['admin.session_families_enabled' => $flag]);
        $legacy = $this->user->createToken('refresh', $abilities);
        $before = $this->state();

        foreach (['/api/v1/auth/me', '/api/v1/auth/sessions', '/api/v1/admin/companies'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->withToken($legacy->plainTextToken)->getJson($path)
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'INVALID_TOKEN');
        }

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('familylessRefreshCases')]
    public function test_familyless_refresh_bearer_cannot_enter_logout_even_with_valid_body_proof(bool $flag, array $abilities): void
    {
        config(['admin.session_families_enabled' => $flag]);
        $legacy = $this->user->createToken('refresh', $abilities);
        $before = $this->state();

        $this->withToken($legacy->plainTextToken)
            ->postJson('/api/v1/auth/logout', ['refreshToken' => $legacy->plainTextToken])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');

        $this->assertSame($before, $this->state());
    }

    public function test_feature_flag_off_does_not_downgrade_family_boundary(): void
    {
        config(['admin.session_families_enabled' => false]);
        $this->withToken($this->family['refreshToken'])->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_routes_have_boundary_before_authentication_and_public_flows_are_untouched(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['/api/v1/auth/me', '/api/v1/auth/sessions', '/api/v1/admin/companies'] as $path) {
            $route = $routes->match(\Illuminate\Http\Request::create($path, 'GET'));
            $middleware = $route->gatherMiddleware();
            $this->assertContains('asab.access', $middleware);
            $this->assertLessThan(array_search('auth:sanctum', $middleware), array_search('asab.access', $middleware));
            $resolved = app('router')->gatherRouteMiddleware($route);
            $this->assertLessThan(
                array_search(\Illuminate\Auth\Middleware\Authenticate::class.':sanctum', $resolved),
                array_search(\Modules\Admin\Http\Middleware\EnsureAdminAccessToken::class, $resolved),
            );
        }
        $logout = $routes->match(\Illuminate\Http\Request::create('/api/v1/auth/logout', 'POST'));
        $this->assertContains('asab.access:logout', $logout->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $logout->gatherMiddleware());
        foreach (['auth/login', 'auth/refresh', 'auth/sso/google/callback', 'company/invitations/accept'] as $path) {
            $route = $routes->match(\Illuminate\Http\Request::create('/api/v1/'.$path, 'POST'));
            $this->assertFalse((bool) array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'asab.access')));
        }
    }

    private function state(): array
    {
        return [
            DB::table('personal_access_tokens')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('admin_sessions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('asab_users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
