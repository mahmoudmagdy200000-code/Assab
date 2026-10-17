<?php

namespace Tests\Feature\Harness;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Services\Testing\StagingHarnessProvisioner;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use RuntimeException;
use Tests\Support\Harness\AssabHarnessFixtureBuilder;
use Tests\TestCase;

final class StagingHarnessProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_fixture_is_idempotent_hashed_least_privilege_and_non_financial(): void
    {
        $builder = new AssabHarnessFixtureBuilder();
        $first = $builder->build();
        $counts = $this->fixtureCounts();
        $second = app(StagingHarnessProvisioner::class)->provision('assab-harness-ci', $first->runtimePassword);

        $this->assertSame($first->manifest['definitionSha256'], $second['definitionSha256']);
        $this->assertSame($counts, $this->fixtureCounts());
        $this->assertSame($second['branchManagerId'], $first->branchManager()->id);
        $this->assertSame(
            $second['legacyDependencies']['branchManagerRosterEmployeeId'],
            DB::table('asab_employees')->where('asab_user_id', $first->user('branch_manager')->id)->value('id'),
        );
        $this->assertCount(6, $second['accounts']);
        $this->assertSame(6, CompanyUser::withoutGlobalScopes()->where('company_id', $second['companyId'])->count());
        $this->assertSame(6, AsabUserRole::whereIn('user_id', collect($second['accounts'])->pluck('id'))->count());
        $this->assertFalse(AsabUserRole::whereIn('user_id', collect($second['accounts'])->pluck('id'))->where('scope', 'all')->exists());
        $this->assertSame(0, $first->user('restricted')->roleAssignments()->count());
        $this->assertTrue(Hash::check($first->runtimePassword, $first->user('company_admin')->fresh()->password));
        $this->assertTrue(Hash::check($first->runtimePassword, $first->cashier()->fresh()->password));
        $this->assertNotSame($first->runtimePassword, $first->user('company_admin')->fresh()->password);
        $this->assertSame(1, AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRANCH_MANAGER)->count());
        $this->assertSame(1, BranchManager::where('email', $first->user('branch_manager')->email)->count());
        $this->assertSame(1, Cashier::where('email', $second['cashier']['email'])->count());
        $this->assertStringNotContainsString($first->runtimePassword, json_encode($second, JSON_THROW_ON_ERROR));

        foreach (['cashier_shifts', 'cashier_custody_transactions', 'personal_ledger_transactions', 'shift_report_cash_counts'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Fixture bootstrap must not create rows in {$table}.");
        }
    }

    public function test_missing_canonical_roles_rolls_back_without_running_a_seeder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical role definitions are missing');

        try {
            app(StagingHarnessProvisioner::class)->provision('missing-roles-fixture', 'H!'.Str::random(30));
        } finally {
            $this->assertSame(0, DB::table('asab_companies')->count());
            $this->assertSame(0, DB::table('asab_users')->count());
        }
    }

    public function test_existing_non_fixture_email_collision_rolls_back(): void
    {
        (new \Modules\Admin\Database\Seeders\AsabRolePermissionSeeder())->run();
        $service = app(StagingHarnessProvisioner::class);
        $plan = $service->plan('collision-fixture');
        AsabUser::create([
            'name' => 'Existing user',
            'email' => $plan['accounts']['accountant']['email'],
            'password' => 'H!'.Str::random(30),
            'status' => 'active',
        ]);

        try {
            $service->provision('collision-fixture', 'H!'.Str::random(30));
            $this->fail('A non-fixture email collision must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('non-fixture user', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('asab_companies')->count());
        $this->assertSame(1, DB::table('asab_users')->count());
    }

    public function test_console_command_is_registered_but_refuses_the_testing_sqlite_target(): void
    {
        $this->artisan('asab:harness:provision-staging', ['--json' => true])
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('asab_companies')->count());
    }

    /** @return array<string, int> */
    private function fixtureCounts(): array
    {
        return collect([
            'asab_companies', 'asab_brands', 'asab_restaurants', 'branches',
            'asab_users', 'asab_user_roles', 'asab_company_users',
            'branch_managers', 'cashiers', 'shifts', 'asab_identity_map', 'asab_employees',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
