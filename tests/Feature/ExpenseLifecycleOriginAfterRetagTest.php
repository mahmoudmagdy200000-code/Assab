<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Expense\Models\Expense;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * F04V3-01: the origin guard validates the IMMUTABLE stored snapshot, NOT the
 * branch's CURRENT brand tag. A legitimate branch re-tag must not block the
 * original brand owner / creating manager from approving or resubmitting an
 * already-captured historical expense; the new brand must still be denied; and a
 * genuinely inconsistent snapshot (brand/company mismatch) must still be refused.
 */
class ExpenseLifecycleOriginAfterRetagTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    private function approve($actor, Expense $e)
    {
        return $this->actingAs($actor, 'sanctum')->postJson("/api/brand-owner/expenses/{$e->id}/approve");
    }

    public function test_original_brand_owner_can_approve_pending_after_branch_retag(): void
    {
        // Pending expense captured at Branch A1 under Brand A1 (origin stamped A1).
        $expense = $this->pendingExpense($this->bmA1, [
            'status' => 'pending', 'total_amount' => 100, 'net_amount' => 100, 'vat_amount' => 0,
        ]);
        $this->assertSame($this->brandA1->id, $expense->origin_asab_brand_id);

        $ownerA2 = $this->makeBrandOwner('a2.lifecycle@tenant.test', 'Owner A2 only');
        $this->linkToBrands($ownerA2, $this->companyA, [$this->brandA2->id], withIdentityMap: true);

        // A supported admin action re-tags Branch A1 to Brand A2 AFTER capture.
        $this->branchA1->forceFill(['asab_brand_id' => $this->brandA2->id])->save();

        // The NEW brand's owner can neither see nor approve it (brand scope keys
        // on the immutable origin brand A1).
        $this->approve($ownerA2, $expense)->assertStatus(404);

        // The ORIGINAL brand's owner still approves it — the live re-tag must not
        // invalidate the valid historical snapshot (F04V3-01 fix).
        Event::fake();
        $this->approve($this->ownerA1, $expense)->assertStatus(200);
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_original_manager_can_resubmit_rejected_after_branch_retag(): void
    {
        $expense = $this->pendingExpense($this->bmA1, [
            'status' => 'rejected', 'total_amount' => 100, 'net_amount' => 100, 'vat_amount' => 0,
        ]);
        $this->assertSame($this->brandA1->id, $expense->origin_asab_brand_id);

        $this->branchA1->forceFill(['asab_brand_id' => $this->brandA2->id])->save();

        Event::fake();
        $this->actingAs($this->bmA1, 'sanctum')
            ->postJson("/api/branch-manager/expenses/{$expense->id}/resubmit")
            ->assertStatus(200);

        $this->assertSame('pending', $expense->fresh()->status, 'the original manager must be able to resubmit after a re-tag');
    }

    public function test_inconsistent_snapshot_is_still_blocked_on_approve(): void
    {
        // A genuinely inconsistent stored snapshot: origin brand A1 but origin
        // company tampered to company B (brand A1 actually belongs to company A).
        $expense = $this->pendingExpense($this->bmA1, [
            'status' => 'pending', 'total_amount' => 100, 'net_amount' => 100, 'vat_amount' => 0,
        ]);
        Expense::where('id', $expense->id)->update(['origin_asab_company_id' => $this->companyB->id]);

        // ownerA1 can reach it (origin brand A1) but approval is refused: the
        // stable brand->company consistency check still fails.
        $res = $this->approve($this->ownerA1, $expense->fresh());
        $this->assertContains($res->status(), [400, 422]);
        $this->assertSame('pending', $expense->fresh()->status);
    }
}
