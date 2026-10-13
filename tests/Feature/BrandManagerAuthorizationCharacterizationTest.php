<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\MobileBranchScopeService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * D-2 — explicit brand-manager authorization (`MobileBranchScopeService`).
 *
 * A brand manager is authorised ONLY through an explicit per-manager delegation
 * (a brand_manager identity-map entry + a brand-manager role carrying the
 * delegated brands). There is no e-mail/owner inheritance: a same-email match to
 * a brand-owner role grants nothing. Missing/revoked/cross-company assignments
 * fail closed. Brand-owner resolution is unchanged.
 */
class BrandManagerAuthorizationCharacterizationTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private function scope(): MobileBranchScopeService
    {
        return app(MobileBranchScopeService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    public function test_unlinked_brand_manager_fails_closed(): void
    {
        $this->assertSame([], $this->scope()->visibleBranchIds($this->managerUnassigned));
    }

    public function test_explicitly_assigned_manager_resolves_only_delegated_brand(): void
    {
        // managerA1 carries an explicit brand_manager delegation to brand A1.
        $ids = $this->scope()->visibleBranchIds($this->managerA1);
        $this->assertContains($this->branchA1->id, $ids);
        $this->assertNotContains($this->branchA2->id, $ids);
        $this->assertNotContains($this->branchB1->id, $ids);
    }

    public function test_multi_brand_delegation_resolves_exactly_the_delegated_set(): void
    {
        $manager = $this->makeBrandManager('mgr.a12@tenant.test', 'Manager A1+A2');
        $this->assignBrandManager($manager, $this->companyA, [$this->brandA1->id, $this->brandA2->id]);

        $ids = $this->scope()->visibleBranchIds($manager);
        $this->assertContains($this->branchA1->id, $ids);
        $this->assertContains($this->branchA2->id, $ids);
        $this->assertNotContains($this->branchB1->id, $ids);
    }

    public function test_same_email_match_to_owner_role_grants_nothing(): void
    {
        // A manager whose e-mail matches a dashboard brand-owner role over [A1,A2]
        // but who has NO explicit brand_manager delegation must inherit NOTHING.
        $manager = $this->makeBrandManager('inherits@tenant.test', 'Non-inheriting Manager');
        $this->linkToBrands($manager, $this->companyA, [$this->brandA1->id, $this->brandA2->id], withIdentityMap: false);

        $this->assertSame([], $this->scope()->visibleBranchIds($manager));
    }

    public function test_revoked_assignment_denies_on_next_resolution(): void
    {
        $this->assertContains($this->branchA1->id, $this->scope()->visibleBranchIds($this->managerA1));

        $this->revokeBrandManager($this->managerA1);

        // Live resolution — revocation takes effect immediately, no cache/token wait.
        $this->assertSame([], $this->scope()->visibleBranchIds($this->managerA1));
    }

    public function test_cross_company_brand_in_role_is_filtered_out(): void
    {
        // Manager mapped in company A but whose role lists a company-B brand:
        // the company-consistency filter drops the cross-company brand.
        $manager = $this->makeBrandManager('mgr.x@tenant.test', 'Manager Cross');
        $this->assignBrandManager($manager, $this->companyA, [$this->brandA1->id, $this->brandB1->id]);

        $ids = $this->scope()->visibleBranchIds($manager);
        $this->assertContains($this->branchA1->id, $ids);
        $this->assertNotContains($this->branchB1->id, $ids);
    }

    // ---- R-03: resolver identity/tenant/enabled validation ----------------

    public function test_resolver_denies_a_disabled_dashboard_user(): void
    {
        // managerA1 is validly delegated; disabling the mapped dashboard user
        // must revoke access on the very next resolution (fail closed).
        $this->assertContains($this->branchA1->id, $this->scope()->visibleBranchIds($this->managerA1));

        $userId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $this->managerA1->id)->value('dashboard_id');
        AsabUser::withoutGlobalScope('tenant')->whereKey($userId)->update(['status' => 'suspended']);

        $this->assertSame([], $this->scope()->visibleBranchIds($this->managerA1));
        $this->assertSame([], $this->scope()->visibleBrandIds($this->managerA1));
    }

    public function test_resolver_denies_on_company_tag_mismatch(): void
    {
        // A map whose company tag disagrees with the dashboard user's company is
        // treated as inconsistent and grants nothing — even though the role's
        // brands are otherwise valid for that user.
        $this->assertContains($this->branchA1->id, $this->scope()->visibleBranchIds($this->managerA1));

        AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $this->managerA1->id)
            ->update(['company_id' => $this->companyB->id]);

        $this->assertSame([], $this->scope()->visibleBranchIds($this->managerA1));
    }

    public function test_resolver_denies_a_malformed_map_type(): void
    {
        // A map that does not point at an asab_user dashboard identity is not a
        // usable delegation and resolves nothing.
        $this->assertContains($this->branchA1->id, $this->scope()->visibleBranchIds($this->managerA1));

        AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->where('legacy_id', $this->managerA1->id)
            ->update(['dashboard_type' => 'legacy_account']);

        $this->assertSame([], $this->scope()->visibleBranchIds($this->managerA1));
    }

    public function test_brand_owner_resolution_is_unchanged(): void
    {
        // ownerA12 owns A1+A2; ownerUnlinked resolves nothing. (D2-INV-07)
        $ownerIds = $this->scope()->visibleBranchIds($this->ownerA12);
        $this->assertContains($this->branchA1->id, $ownerIds);
        $this->assertContains($this->branchA2->id, $ownerIds);
        $this->assertNotContains($this->branchB1->id, $ownerIds);

        $this->assertSame([], $this->scope()->visibleBranchIds($this->ownerUnlinked));
    }
}
