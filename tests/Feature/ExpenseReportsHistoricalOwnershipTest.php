<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * FU-01 (soft-deleted manager grouping) and FU-02 (manager-branch reassignment
 * reattributes historic expenses) for the Brand Owner reports.
 *
 * FU-01 is a fixed grouping bug (regression test). FU-02 is a CHARACTERIZATION
 * of a confirmed, un-remediated gap: `expenses` has no immutable branch/brand,
 * so a historic expense follows its manager when the (supported)
 * ManagerBranchSyncService repoints `branch_managers.branch_id`. The fix is a
 * schema + backfill change, delivered as design-only (see
 * pkg2-expense-branch-ownership-design.md); the test here documents the gap.
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

    public function test_gap_manager_reassignment_reattributes_historic_expense_across_brands(): void
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

        // GAP: the historic expense silently LEAVES brand A1's report and now
        // appears under brand A2 — attribution follows the manager's CURRENT
        // branch because the expense stores no immutable branch/brand of its own.
        $this->assertSame(0.0, $this->total($this->report($this->ownerA1)));
        $this->assertSame(100.0, $this->total($this->report($ownerA2)));
    }
}
