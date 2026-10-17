<?php

namespace Tests\Feature\Harness;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Tests\Support\Harness\AssabHarnessFixture;
use Tests\Support\Harness\AssabHarnessFixtureBuilder;
use Tests\TestCase;

final class AssabHarnessAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private AssabHarnessFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->fixture = (new AssabHarnessFixtureBuilder())->build();
    }

    public function test_every_dashboard_role_logs_in_and_me_returns_real_scoped_assignments(): void
    {
        $expected = [
            'company_admin' => ['company-admin' => 'brand'],
            'branch_manager' => ['branch' => 'branch'],
            'accountant' => ['accountant' => 'restaurant'],
            'head_accountant' => ['head' => 'brand'],
            'multi_role_finance' => ['accountant' => 'restaurant', 'head' => 'brand'],
        ];

        foreach ($expected as $key => $roleScopes) {
            $token = $this->loginDashboard($key);
            $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
            $this->assertSame($this->fixture->company()->id, $response->json('companyId'));
            $actual = collect($response->json('roles'))->mapWithKeys(fn (array $role): array => [$role['key'] => $role['scope']])->all();
            ksort($roleScopes);
            ksort($actual);
            $this->assertSame($roleScopes, $actual);
            foreach ($response->json('roles') as $role) {
                $this->assertNotSame('all', $role['scope']);
                $this->assertContains($this->fixture->branch()->id, $role['branchIds']);
            }
        }
    }

    public function test_role_endpoints_allow_the_owner_and_forbid_other_roles(): void
    {
        $cases = [
            ['company_admin', '/api/v1/company/me/brands'],
            ['branch_manager', '/api/v1/company/me/branch/overview'],
            ['accountant', '/api/v1/company/me/accountant/dashboard'],
            ['head_accountant', '/api/v1/company/me/head/dashboard'],
        ];

        foreach ($cases as [$key, $path]) {
            $this->withToken($this->loginDashboard($key))->getJson($path)->assertOk();
            $this->app['auth']->forgetGuards();
        }

        $this->withToken($this->loginDashboard('branch_manager'))
            ->getJson('/api/v1/company/me/accountant/dashboard')
            ->assertStatus(403)->assertJsonPath('error.code', 'WRONG_ROLE');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->loginDashboard('accountant'))
            ->getJson('/api/v1/company/me/head/dashboard')
            ->assertStatus(403)->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    public function test_tenant_lookup_does_not_leak_a_foreign_branch(): void
    {
        $foreignCompany = AsabCompany::create(['name' => 'Foreign tenant', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::create([
            'name' => 'Foreign branch', 'location' => 'Outside fixture',
            'asab_company_id' => $foreignCompany->id, 'status' => 'active',
        ]);

        $response = $this->withToken($this->loginDashboard('accountant'))
            ->getJson('/api/v1/company/me/lookups/branches')->assertOk();
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($this->fixture->branch()->id, $encoded);
        $this->assertStringNotContainsString($foreignBranch->id, $encoded);
    }

    public function test_restricted_user_can_authenticate_but_has_no_tenant_role_authority(): void
    {
        $token = $this->loginDashboard('restricted');
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonCount(0, 'roles');

        $this->withToken($token)->getJson('/api/v1/company/me/dashboard')
            ->assertStatus(403)->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    public function test_revoking_a_role_takes_effect_on_an_existing_access_token(): void
    {
        $token = $this->loginDashboard('accountant');
        $this->withToken($token)->getJson('/api/v1/company/me/accountant/dashboard')->assertOk();

        AsabUserRole::where('user_id', $this->fixture->user('accountant')->id)->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/company/me/accountant/dashboard')
            ->assertStatus(403)->assertJsonPath('error.code', 'WRONG_ROLE');
    }

    public function test_multi_role_user_has_union_authority_but_no_role_switch_endpoint(): void
    {
        $token = $this->loginDashboard('multi_role_finance');
        $this->withToken($token)->getJson('/api/v1/company/me/accountant/dashboard')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/company/me/head/dashboard')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/switch-role', ['role' => 'head'])->assertNotFound();
    }

    public function test_session_family_refresh_rotation_and_logout_revoke_fixture_tokens(): void
    {
        config(['admin.session_families_enabled' => true]);
        $user = $this->fixture->user('company_admin');
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => $this->fixture->runtimePassword,
        ])->assertOk();
        $oldAccess = $login->json('accessToken');
        $oldRefresh = $login->json('refreshToken');

        $refresh = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $oldRefresh])->assertOk();
        $newAccess = $refresh->json('accessToken');
        $this->assertNull(PersonalAccessToken::findToken($oldAccess));
        $this->assertNull(PersonalAccessToken::findToken($oldRefresh));
        $this->withToken($newAccess)->getJson('/api/v1/auth/me')->assertOk();

        $this->withToken($newAccess)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($newAccess)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_cashier_uses_real_legacy_login_and_cannot_use_dashboard_me(): void
    {
        $cashier = $this->fixture->cashier();
        $login = $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $cashier->email,
            'password' => $this->fixture->runtimePassword,
        ])->assertOk();
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/v1/cashier/profile')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    private function loginDashboard(string $key): string
    {
        $user = $this->fixture->user($key);
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $this->fixture->runtimePassword,
        ])->assertOk();

        return (string) $response->json('accessToken');
    }
}
