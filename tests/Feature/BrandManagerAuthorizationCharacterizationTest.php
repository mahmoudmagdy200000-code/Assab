<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Services\MobileBranchScopeService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * IR-PKG2-02 / D-2 — characterization of the CURRENT brand-manager authority
 * resolution (`MobileBranchScopeService::visibleBranchIds`). These tests do not
 * change behaviour; they pin down exactly what is safe today and the one gap
 * that keeps D-2 BLOCKED (pending the shared-infra design).
 *
 * Safe today: an unlinked manager and a cross-company manager resolve correctly.
 * Gap: a brand manager inherits brands purely by e-mail match to a dashboard
 * `brand-owner` role, with no explicit per-manager assignment — so the manager
 * gets whatever brands that owner role holds.
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
        // managerUnassigned has no identity map and no same-email dashboard user.
        $this->assertSame([], $this->scope()->visibleBranchIds($this->managerUnassigned));
    }

    public function test_assigned_brand_manager_resolves_only_its_brand(): void
    {
        // managerA1 is same-email linked to a brand-owner role holding [A1].
        $ids = $this->scope()->visibleBranchIds($this->managerA1);
        $this->assertContains($this->branchA1->id, $ids);
        $this->assertNotContains($this->branchA2->id, $ids);
        $this->assertNotContains($this->branchB1->id, $ids);
    }

    public function test_cross_company_manager_sees_only_its_company_brand(): void
    {
        $managerB = $this->makeBrandManager('mgr.b@tenant.test', 'Manager B');
        $this->linkToBrands($managerB, $this->companyB, [$this->brandB1->id], withIdentityMap: false);

        $ids = $this->scope()->visibleBranchIds($managerB);
        $this->assertSame([$this->branchB1->id], $ids);
        $this->assertNotContains($this->branchA1->id, $ids);
        $this->assertNotContains($this->branchA2->id, $ids);
    }

    /**
     * DOCUMENTS THE D-2 GAP (not a fix): a brand manager whose e-mail matches a
     * dashboard brand-owner role holding [A1, A2] inherits BOTH brands, with no
     * explicit per-manager assignment. The proper model (explicit brand-manager
     * role + identity-map entity) requires the gated shared-infra change.
     */
    public function test_gap_same_email_manager_inherits_full_owner_brand_set(): void
    {
        $manager = $this->makeBrandManager('inherits@tenant.test', 'Inheriting Manager');
        // Same-email dashboard user carries a brand-owner role over A1 AND A2.
        $this->linkToBrands($manager, $this->companyA, [$this->brandA1->id, $this->brandA2->id], withIdentityMap: false);

        $ids = $this->scope()->visibleBranchIds($manager);

        // Current behaviour: inherits both. This is the gap D-2 must close by
        // requiring an explicit per-manager assignment rather than e-mail match.
        $this->assertContains($this->branchA1->id, $ids);
        $this->assertContains($this->branchA2->id, $ids);
    }
}
