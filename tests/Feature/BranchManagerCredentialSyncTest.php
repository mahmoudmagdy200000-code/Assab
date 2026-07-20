<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\UserPasswordResetNotification;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\AuthService as MobileAuthService;
use Tests\TestCase;

/**
 * One emailed password opens both worlds for role=branch (client requirement:
 * "the branch manager can log in on mobile and dashboard with the same
 * password"). branch_managers was login-capable but had NO production create
 * path — the identity-map migration called branch_manager "intentionally out of
 * v1" for exactly that reason. The client reversed that decision.
 */
class BranchManagerCredentialSyncTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'BM Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->admin = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'أدمن',
            'email' => 'admin@bm.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
    }

    private function createBranchUser(string $email, ?string $branchId = null, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', array_merge([
            'name' => 'مدير الفرع',
            'email' => $email,
            'role' => 'branch',
            'companyId' => $this->company->id,
            'branches' => [$branchId ?? $this->branch->id],
        ], $extra));
    }

    private function emailedPassword(AsabUser $user): string
    {
        $captured = null;
        Notification::assertSentTo($user, UserPasswordResetNotification::class, function ($notification) use (&$captured) {
            $captured = (new \ReflectionProperty($notification, 'temporaryPassword'))->getValue($notification);

            return true;
        });

        return $captured;
    }

    public function test_adding_a_branch_manager_emails_a_password_that_opens_both_worlds(): void
    {
        Notification::fake();

        $this->createBranchUser('bm@bm.test')->assertCreated();

        $user = AsabUser::where('email', 'bm@bm.test')->firstOrFail();
        $password = $this->emailedPassword($user);

        $this->assertNotNull($password, 'the new user must be emailed a password');
        $this->assertTrue(Hash::check($password, $user->password), 'the emailed password must open the dashboard');

        $manager = BranchManager::where('email', 'bm@bm.test')->first();
        $this->assertNotNull($manager, 'the legacy branch_managers row must be created');
        $this->assertTrue(Hash::check($password, $manager->password), 'the emailed password must open the mobile app');
        $this->assertTrue($manager->is_first_login, 'an admin-issued password must force a reset on the app');
    }

    public function test_the_assigned_branch_id_drops_straight_into_the_legacy_fk(): void
    {
        Notification::fake();

        $this->createBranchUser('fk@bm.test')->assertCreated();

        // There is no asab_branches table: AsabUserRole.branch_ids[0] IS a
        // branches.id, so no translation layer is involved.
        $this->assertSame($this->branch->id, BranchManager::where('email', 'fk@bm.test')->value('branch_id'));
    }

    public function test_the_identity_map_link_is_written_with_the_branch_manager_entity_type(): void
    {
        Notification::fake();

        $this->createBranchUser('map@bm.test')->assertCreated();

        $user = AsabUser::where('email', 'map@bm.test')->firstOrFail();
        $manager = BranchManager::where('email', 'map@bm.test')->firstOrFail();

        $row = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRANCH_MANAGER)
            ->where('dashboard_id', $user->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($manager->id, $row->legacy_id);
        $this->assertSame('asab_user', $row->dashboard_type);
        $this->assertSame('branch_manager', $row->legacy_type);
    }

    public function test_soft_deleted_manager_email_is_restored_instead_of_colliding(): void
    {
        Notification::fake();
        // branch_managers.email is UNIQUE regardless of deleted_at, so a trashed
        // row still owns the address and create() would hit the index.
        $trashed = BranchManager::factory()->create(['email' => 'back@bm.test', 'branch_id' => $this->branch->id]);
        $trashed->delete();

        $this->createBranchUser('back@bm.test')->assertCreated();

        $user = AsabUser::where('email', 'back@bm.test')->firstOrFail();
        $manager = BranchManager::where('email', 'back@bm.test')->first();

        $this->assertNotNull($manager, 'the trashed row must be restored, not duplicated');
        $this->assertSame($trashed->id, $manager->id);
        $this->assertNull($manager->deleted_at);
        $this->assertSame(1, BranchManager::withTrashed()->where('email', 'back@bm.test')->count());
        $this->assertTrue(Hash::check($this->emailedPassword($user), $manager->password));
    }

    public function test_password_set_in_the_mobile_app_also_opens_the_dashboard(): void
    {
        Notification::fake();
        $this->createBranchUser('firstlogin@bm.test')->assertCreated();

        $user = AsabUser::where('email', 'firstlogin@bm.test')->firstOrFail();
        $manager = BranchManager::where('email', 'firstlogin@bm.test')->firstOrFail();

        app(MobileAuthService::class)->resetPasswordFirstLogin($manager, 'chosen-by-manager');

        $this->assertTrue(
            Hash::check('chosen-by-manager', $user->fresh()->password),
            'the first-login reset must be mirrored onto the dashboard credential',
        );
    }

    public function test_a_branch_from_another_company_is_refused(): void
    {
        Notification::fake();
        // Zero-trust: store() validated `branches` for existence only, and
        // branch_managers.branch_id is a real FK from here on.
        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $other->id]);

        $this->createBranchUser('cross@bm.test', $foreignBranch->id)->assertStatus(422);

        $this->assertNull(AsabUser::where('email', 'cross@bm.test')->first(), 'the transaction must roll back');
        $this->assertNull(BranchManager::where('email', 'cross@bm.test')->first());
    }

    public function test_a_nonexistent_branch_is_a_validation_error_not_a_foreign_key_crash(): void
    {
        Notification::fake();

        $this->createBranchUser('ghost@bm.test', (string) \Illuminate\Support\Str::uuid())
            ->assertStatus(422);
    }

    public function test_a_duplicate_phone_is_dropped_rather_than_failing_the_whole_creation(): void
    {
        Notification::fake();
        // branch_managers.phone is UNIQUE (unlike suppliers.phone).
        BranchManager::factory()->create(['phone' => '+966500000099', 'branch_id' => $this->branch->id]);

        $this->createBranchUser('phone@bm.test', null, ['phone' => '+966500000099'])->assertCreated();

        $manager = BranchManager::where('email', 'phone@bm.test')->firstOrFail();
        $this->assertNull($manager->phone, 'the colliding phone must be dropped, not 500');
    }

    /**
     * The reported «تعذر الاتصال بالخادم»: a second dashboard user for a legacy
     * manager already claimed in asab_identity_map hit unique(entity_type,
     * legacy_id) as an uncaught QueryException, which left the browser reporting
     * an unreachable server. linkSupplier had guarded this since day one;
     * linkBranchManager did not.
     */
    public function test_a_second_user_for_an_already_linked_manager_is_a_422_not_a_500(): void
    {
        Notification::fake();

        // A legacy manager already linked to some other dashboard account. The
        // new user reaches that same row because ensureManager matches on email.
        $manager = BranchManager::factory()->create([
            'email' => 'claimed@bm.test',
            'branch_id' => $this->branch->id,
        ]);
        $owner = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'المالك الأصلي',
            'email' => 'owner@bm.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabIdentityMap::create([
            'entity_type' => AsabIdentityMap::ENTITY_BRANCH_MANAGER,
            'dashboard_type' => 'asab_user',
            'dashboard_id' => $owner->id,
            'legacy_type' => 'branch_manager',
            'legacy_id' => $manager->id,
            'company_id' => $this->company->id,
            'match_method' => 'email',
            'linked_at' => now(),
        ]);

        $this->createBranchUser('claimed@bm.test')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_MANAGER_LOGIN_AMBIGUOUS');

        $this->assertNull(AsabUser::where('email', 'claimed@bm.test')->first(), 'the transaction must roll back');
        $this->assertSame(
            $owner->id,
            AsabIdentityMap::where('legacy_id', $manager->id)->value('dashboard_id'),
            'the original link must survive',
        );
    }

    /**
     * Deleting a user must release its identity link, or re-creating the same
     * account trips the new ambiguity guard and can never be provisioned again.
     */
    public function test_recreating_a_deleted_branch_manager_succeeds(): void
    {
        Notification::fake();

        $this->createBranchUser('recreate@bm.test')->assertCreated();
        $user = AsabUser::where('email', 'recreate@bm.test')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$user->id}")
            ->assertStatus(204);

        $this->assertSame(
            0,
            AsabIdentityMap::withTrashed()->where('dashboard_id', $user->id)->count(),
            'the link must be released, not left claiming the legacy row',
        );

        $this->createBranchUser('recreate2@bm.test')->assertCreated();
    }

    /**
     * Reusing a legacy manager overwrites its branch_id and password. Across
     * companies that hands one tenant's manager to another and locks the
     * original owner out, so it fails closed rather than repointing silently.
     */
    public function test_an_email_belonging_to_another_companys_manager_is_rejected(): void
    {
        Notification::fake();

        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['asab_company_id' => $other->id]);
        $foreign = BranchManager::factory()->create([
            'email' => 'foreign@bm.test',
            'branch_id' => $otherBranch->id,
        ]);

        $this->createBranchUser('foreign@bm.test')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_MANAGER_IN_OTHER_COMPANY');

        $this->assertSame($otherBranch->id, $foreign->fresh()->branch_id, 'the foreign manager must not be repointed');
        $this->assertNull(AsabUser::where('email', 'foreign@bm.test')->first(), 'the transaction must roll back');
    }
}
