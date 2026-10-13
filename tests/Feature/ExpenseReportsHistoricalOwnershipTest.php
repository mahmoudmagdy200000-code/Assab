<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * FU-01 (soft-deleted manager grouping), FU-02 (manager-branch reassignment does
 * not move historic expense ownership) and R-01 (a branch re-tagged to another
 * brand does not move its historic expenses) for the Brand Owner readers.
 *
 * FU-01 is a fixed grouping bug. FU-02/R-01 are now IMPLEMENTED: `expenses`
 * carries an immutable origin (origin_branch_id / origin_asab_brand_id /
 * origin_asab_company_id) stamped once at creation, and every brand-actor reader
 * keys on that origin — the reports service, the approval repository and the home
 * dashboard — never on the manager's current branch nor on a branch's current
 * brand association. These tests lock that invariant in across all three readers.
 */
class ExpenseReportsHistoricalOwnershipTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
        $this->pendingExpense($this->bmA1, ['status' => 'approved', 'total_amount' => 100.00, 'net_amount' => 100.00, 'vat_amount' => 0.00]);
    }

    private function report($actor)
    {
        return $this->actingAs($actor, 'sanctum')->getJson('/api/brand-owner/reports/expense/expenses?type=quick_cash');
    }

    private function total($r): float
    {
        return (float) $r->json('data.summary.total_amount');
    }

    // ---- FU-01: fixed ------------------------------------------------------

    public function test_soft_deleted_manager_expense_groups_under_its_real_branch(): void
    {
        // Soft-delete the manager who owns the expense.
        $this->bmA1->delete();
        $this->assertSoftDeleted('branch_managers', ['id' => $this->bmA1->id]);

        $r = $this->report($this->ownerA1)->assertStatus(200);

        // Total still counts the expense (scope subquery uses withTrashed)...
        $this->assertSame(100.0, $this->total($r));

        // ...and it groups under the REAL branch, not "Unassigned" (FU-01 fix).
        $comparisons = collect($r->json('data.branch_comparisons'));
        $this->assertTrue($comparisons->contains('name', $this->branchA1->name), 'expected the real branch in comparisons');
        $this->assertFalse($comparisons->contains('name', 'Unassigned'), 'must not bucket as Unassigned');
        $this->assertSame(100.0, (float) $comparisons->firstWhere('name', $this->branchA1->name)['amount']);
    }

    // ---- FU-02: characterization (documents the gap) -----------------------

    public function test_manager_reassignment_does_not_move_historic_expense_ownership(): void
    {
        // A second, empty branch under brand A2 (a reassignment destination with
        // no active manager — the real sync service rejects occupied ones).
        $branchA2b = \Modules\Branch\Models\Branch::factory()->create([
            'name' => 'Branch A2b', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA2->id,
        ]);

        // Owner of brand A1 sees the historic expense; an A2-only owner does not.
        $ownerA2 = $this->makeBrandOwner('a2.only@tenant.test', 'Owner A2 only');
        $this->linkToBrands($ownerA2, $this->companyA, [$this->brandA2->id], withIdentityMap: true);

        $this->assertSame(100.0, $this->total($this->report($this->ownerA1)));
        $this->assertSame(0.0, $this->total($this->report($ownerA2)));

        // Supported dashboard operation (ManagerBranchSyncService) repoints the
        // manager's branch — here A1 -> an A2 branch (same company, different brand).
        $this->bmA1->forceFill(['branch_id' => $branchA2b->id])->save();

        // FU-02 FIXED: the expense carries an immutable origin captured at
        // creation, so it STAYS with brand A1 and never appears under brand A2
        // just because its manager moved.
        $this->assertSame(100.0, $this->total($this->report($this->ownerA1)));
        $this->assertSame(0.0, $this->total($this->report($ownerA2)));
    }

    // ---- R-01: branch re-tag must not move historic ownership --------------

    /**
     * The R-01 gap the manager-move test above does NOT exercise: an expense is
     * scoped by its branch's CURRENT brand. Here brand A1's only branch is
     * re-tagged to brand A2 AFTER the expense exists. With the immutable
     * origin_asab_brand_id authority the expense must stay with brand A1's owner
     * and never surface for brand A2's owner — across the reports reader, the
     * approval-list repository and the home dashboard.
     *
     * (Pre-R-01 the readers filtered origin_branch_id against the brand's CURRENT
     * branches, so after the re-tag brand A1 lost the expense and brand A2 gained
     * it — this test fails on that implementation and passes on the origin-brand one.)
     */
    public function test_branch_retag_keeps_expense_with_its_original_brand_owner(): void
    {
        $ownerA2 = $this->makeBrandOwner('a2.retag@tenant.test', 'Owner A2 only');
        $this->linkToBrands($ownerA2, $this->companyA, [$this->brandA2->id], withIdentityMap: true);

        // Baseline: brand A1's owner sees the 100.0 expense; brand A2's does not.
        $this->assertSame(100.0, $this->total($this->report($this->ownerA1)));
        $this->assertSame(0.0, $this->total($this->report($ownerA2)));

        // A supported admin action re-tags Branch A1 from brand A1 to brand A2.
        // The expense's immutable origin (brand A1) was stamped at creation and
        // is untouched by this.
        $this->branchA1->forceFill(['asab_brand_id' => $this->brandA2->id])->save();

        // Reports reader: ownership stays with brand A1, never crosses to A2.
        $this->assertSame(100.0, $this->total($this->report($this->ownerA1)), 'brand A1 owner must keep its historic expense after the branch re-tag');
        $this->assertSame(0.0, $this->total($this->report($ownerA2)), 'brand A2 owner must not gain brand A1 history via the re-tag');

        // Approval-list repository reader (/brand-owner/expenses): same invariant.
        $listA1 = $this->actingAs($this->ownerA1, 'sanctum')->getJson('/api/brand-owner/expenses')->assertStatus(200);
        $this->assertSame(1, (int) $listA1->json('meta.total'), 'brand A1 owner must still list its historic expense');

        $listA2 = $this->actingAs($ownerA2, 'sanctum')->getJson('/api/brand-owner/expenses')->assertStatus(200);
        $this->assertSame(0, (int) $listA2->json('meta.total'), 'brand A2 owner must not list brand A1 history');

        // Home dashboard reader (/brand-owner/dashboard): approved expense total.
        $homeA1 = $this->actingAs($this->ownerA1, 'sanctum')->getJson('/api/brand-owner/dashboard')->assertStatus(200);
        $this->assertSame(100.0, (float) $homeA1->json('data.total_expense_amount'), 'home dashboard must keep brand A1 history');

        $homeA2 = $this->actingAs($ownerA2, 'sanctum')->getJson('/api/brand-owner/dashboard')->assertStatus(200);
        $this->assertSame(0.0, (float) $homeA2->json('data.total_expense_amount'), 'home dashboard must not leak brand A1 history to A2');
    }

    /**
     * FU02-02 integration: drive the REAL supported move through
     * ManagerBranchSyncService::sync() (not a forceFill shortcut) and prove the
     * historic expense keeps its origin brand. sync() repoints
     * branch_managers.branch_id inside its locked transaction; because origin is
     * create-only, the already-stamped expense does not move.
     */
    public function test_real_manager_branch_sync_does_not_move_historic_expense_ownership(): void
    {
        // Empty destination branch under brand A2 (same company, different brand).
        $destBranch = \Modules\Branch\Models\Branch::factory()->create([
            'name' => 'A2 Dest', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA2->id,
        ]);

        // The dashboard identity the sync service drives (ENTITY_BRANCH_MANAGER map).
        $dashUser = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'BM Dash', 'email' => 'bm.sync@tenant.test',
            'password' => 'x', 'status' => 'active',
        ]);
        \Modules\Admin\Models\AsabIdentityMap::create([
            'company_id' => $this->companyA->id,
            'entity_type' => \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRANCH_MANAGER,
            'dashboard_type' => 'asab_user', 'dashboard_id' => $dashUser->id,
            'legacy_type' => 'branch_manager', 'legacy_id' => $this->bmA1->id,
            'match_method' => 'email', 'linked_at' => now(),
        ]);

        $ownerA2 = $this->makeBrandOwner('a2.sync@tenant.test', 'Owner A2 only');
        $this->linkToBrands($ownerA2, $this->companyA, [$this->brandA2->id], withIdentityMap: true);

        // Real supported move A1 -> an A2 branch.
        $moved = app(\Modules\Admin\Services\ManagerBranchSyncService::class)->sync($dashUser->id, $destBranch->id);
        $this->assertTrue($moved, 'the sync service should have repointed the legacy branch');
        $this->assertSame($destBranch->id, $this->bmA1->fresh()->branch_id);

        // Origin ownership is unchanged: brand A1 keeps the expense, A2 never gains it.
        $this->assertSame(100.0, $this->total($this->report($this->ownerA1)));
        $this->assertSame(0.0, $this->total($this->report($ownerA2)));
    }

    public function test_origin_columns_are_stamped_at_creation_and_not_restamped_on_update(): void
    {
        $expense = $this->pendingExpense($this->bmA2, ['status' => 'approved', 'total_amount' => 250.0, 'net_amount' => 250.0, 'vat_amount' => 0.0]);
        $this->assertSame($this->branchA2->id, $expense->origin_branch_id);
        $this->assertSame($this->brandA2->id, $expense->origin_asab_brand_id);
        $this->assertSame($this->companyA->id, $expense->origin_asab_company_id);

        // Reassign the manager, then touch the expense — origin must not move.
        $branchB2 = \Modules\Branch\Models\Branch::factory()->create([
            'asab_company_id' => $this->companyB->id, 'asab_brand_id' => $this->brandB1->id,
        ]);
        $this->bmA2->forceFill(['branch_id' => $branchB2->id])->save();
        $expense->update(['status' => 'rejected', 'rejection_reason' => 'x']);
        $expense->refresh();

        $this->assertSame($this->branchA2->id, $expense->origin_branch_id);
        $this->assertSame($this->brandA2->id, $expense->origin_asab_brand_id);
        $this->assertSame($this->companyA->id, $expense->origin_asab_company_id);
    }
}
