<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Services\BrandManagerAssignmentService;
use Modules\Admin\Services\MobileBranchScopeService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * D-2 provisioning service: explicit grant / change / revoke with an append-only
 * audit trail, company-consistent and fail-closed.
 */
class BrandManagerAssignmentServiceTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private function service(): BrandManagerAssignmentService
    {
        return app(BrandManagerAssignmentService::class);
    }

    private function scope(): MobileBranchScopeService
    {
        return app(MobileBranchScopeService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    public function test_grant_then_resolve_then_change_then_revoke_with_audit(): void
    {
        $manager = $this->makeBrandManager('prov@tenant.test', 'Provisioned Manager');
        $user = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash', 'email' => 'prov@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);

        // Grant A1.
        $this->service()->grant($manager->id, $user, [$this->brandA1->id], 'actor-1');
        $this->assertContains($this->branchA1->id, $this->scope()->visibleBranchIds($manager));
        $this->assertNotContains($this->branchA2->id, $this->scope()->visibleBranchIds($manager));

        // Change to A1 + A2.
        $this->service()->grant($manager->id, $user, [$this->brandA1->id, $this->brandA2->id], 'actor-1');
        $ids = $this->scope()->visibleBranchIds($manager);
        $this->assertContains($this->branchA1->id, $ids);
        $this->assertContains($this->branchA2->id, $ids);

        // Revoke → fail closed.
        $this->service()->revoke($manager->id, 'actor-1');
        $this->assertSame([], $this->scope()->visibleBranchIds($manager));

        // Append-only audit: grant + change + revoke = 3 rows, never overwritten.
        $rows = DB::table('asab_brand_manager_assignment_audit')->where('brand_manager_id', $manager->id)->orderBy('created_at')->get();
        $this->assertSame(['grant', 'change', 'revoke'], $rows->pluck('action')->all());
    }

    public function test_revoke_then_regrant_reactivates_the_soft_deleted_map(): void
    {
        // R-02: AsabIdentityMap uses SoftDeletes and the unique indexes include
        // trashed rows, so a plain create on re-grant would hit a duplicate-key
        // violation. grant() must reactivate the revoked map in place.
        $manager = $this->makeBrandManager('regrant@tenant.test', 'Regrant Manager');
        $user = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash RG', 'email' => 'regrant@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);

        $this->service()->grant($manager->id, $user, [$this->brandA1->id], 'actor');
        $this->service()->revoke($manager->id, 'actor');
        $this->assertSame([], $this->scope()->visibleBranchIds($manager));
        $this->assertSoftDeleted('asab_identity_map', [
            'entity_type' => \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRAND_MANAGER,
            'legacy_id' => $manager->id,
        ]);

        // Re-grant (a different brand set) must NOT throw and must restore access.
        $this->service()->grant($manager->id, $user, [$this->brandA2->id], 'actor');
        $ids = $this->scope()->visibleBranchIds($manager);
        $this->assertContains($this->branchA2->id, $ids);
        $this->assertNotContains($this->branchA1->id, $ids);

        // Exactly ONE live map row (reactivated, not duplicated).
        $this->assertSame(1, \Modules\Admin\Models\AsabIdentityMap::where('entity_type', \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $manager->id)->count());

        // Append-only audit preserves the full history: grant ×2 + revoke ×1.
        $actions = DB::table('asab_brand_manager_assignment_audit')->where('brand_manager_id', $manager->id)->pluck('action');
        $this->assertSame(2, $actions->filter(fn ($a) => $a === 'grant')->count());
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'revoke')->count());
    }

    public function test_repeated_revoke_is_idempotent_and_safe(): void
    {
        $manager = $this->makeBrandManager('dblrevoke@tenant.test', 'Double Revoke');
        $user = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash DR', 'email' => 'dblrevoke@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);

        $this->service()->grant($manager->id, $user, [$this->brandA1->id], 'actor');
        $this->service()->revoke($manager->id, 'actor');
        // A second revoke with no live map must not throw.
        $this->service()->revoke($manager->id, 'actor');

        $this->assertSame([], $this->scope()->visibleBranchIds($manager));
        $this->assertSame(0, \Modules\Admin\Models\AsabUserRole::where('user_id', $user->id)->where('role_key', 'brand-manager')->count());
    }

    public function test_reassigning_dashboard_user_moves_resolution_to_the_new_user(): void
    {
        $manager = $this->makeBrandManager('reassign@tenant.test', 'Reassign Manager');
        $user1 = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash U1', 'email' => 'u1@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);
        $user2 = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash U2', 'email' => 'u2@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);

        $this->service()->grant($manager->id, $user1, [$this->brandA1->id], 'actor');
        $this->assertContains($this->brandA1->id, $this->scope()->visibleBrandIds($manager));

        // Re-point the SAME manager's delegation at a different dashboard user.
        $this->service()->grant($manager->id, $user2, [$this->brandA2->id], 'actor');

        $brandIds = $this->scope()->visibleBrandIds($manager);
        $this->assertContains($this->brandA2->id, $brandIds);
        $this->assertNotContains($this->brandA1->id, $brandIds);

        // One live map, now pointing at user2.
        $map = \Modules\Admin\Models\AsabIdentityMap::where('entity_type', \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $manager->id)->firstOrFail();
        $this->assertSame($user2->id, $map->dashboard_id);

        // F-02: the old dashboard user keeps NO stale brand-manager role.
        $this->assertSame(0, \Modules\Admin\Models\AsabUserRole::where('user_id', $user1->id)->where('role_key', 'brand-manager')->count(), 'the previous dashboard user must not retain a stale brand-manager role');
        $this->assertSame(1, \Modules\Admin\Models\AsabUserRole::where('user_id', $user2->id)->where('role_key', 'brand-manager')->count());
    }

    public function test_grant_refuses_an_inactive_dashboard_user(): void
    {
        // R-02/R-03: a disabled dashboard account may not carry a delegation.
        $manager = $this->makeBrandManager('inactive@tenant.test', 'Inactive-user Manager');
        $user = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash INA', 'email' => 'inactive@tenant.test',
            'password' => 'x', 'status' => 'suspended',
        ]);

        try {
            $this->service()->grant($manager->id, $user, [$this->brandA1->id], 'actor');
            $this->fail('expected grant to refuse an inactive dashboard user');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('INACTIVE', $e->getMessage());
        }

        // Nothing written: no map, no role, no audit.
        $this->assertDatabaseMissing('asab_identity_map', [
            'entity_type' => \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRAND_MANAGER,
            'legacy_id' => $manager->id,
        ]);
        $this->assertSame(0, \Modules\Admin\Models\AsabUserRole::where('user_id', $user->id)->where('role_key', 'brand-manager')->count());
        $this->assertDatabaseCount('asab_brand_manager_assignment_audit', 0);
    }

    public function test_grant_refuses_cross_company_brand(): void
    {
        $manager = $this->makeBrandManager('prov2@tenant.test', 'Provisioned Manager 2');
        $user = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Dash2', 'email' => 'prov2@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);

        // brandB1 belongs to company B, user is company A → refused, nothing written.
        try {
            $this->service()->grant($manager->id, $user, [$this->brandB1->id], 'actor-1');
            $this->fail('expected cross-company grant to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CROSS_COMPANY', $e->getMessage());
        }

        $this->assertSame([], $this->scope()->visibleBranchIds($manager));
        $this->assertDatabaseMissing('asab_identity_map', [
            'entity_type' => \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRAND_MANAGER,
            'legacy_id' => $manager->id,
        ]);
    }
}
