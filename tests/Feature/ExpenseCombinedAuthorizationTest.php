<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * D-2 + FU-02 combined authorization (HTTP level).
 *
 * Access to an expense is allowed only when BOTH hold:
 *   (1) the actor currently has explicit permission for the brand, AND
 *   (2) the expense's IMMUTABLE origin brand is that authorised brand.
 *
 * Neither the creator's identity nor the creating manager's CURRENT branch
 * grants access.
 */
class ExpenseCombinedAuthorizationTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private \Modules\BrandOwner\Models\BrandManager $managerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();

        // A brand manager explicitly delegated company B / brand B1.
        $this->managerB = $this->makeBrandManager('mgr.b1@tenant.test', 'Manager B1');
        $this->assignBrandManager($this->managerB, $this->companyB, [$this->brandB1->id]);
    }

    /** An expense created on brand A1, whose manager is THEN moved to a company-B branch. */
    private function a1ExpenseWithManagerMovedToB(): \Modules\Expense\Models\Expense
    {
        $expense = $this->pendingExpense($this->bmA1); // origin = branch A1
        $this->assertSame($this->branchA1->id, $expense->origin_branch_id);

        $branchB2 = Branch::factory()->create([
            'asab_company_id' => $this->companyB->id, 'asab_brand_id' => $this->brandB1->id,
        ]);
        $this->bmA1->forceFill(['branch_id' => $branchB2->id])->save();

        return $expense->fresh();
    }

    private function as($actor)
    {
        return $this->actingAs($actor, 'sanctum');
    }

    public function test_combined01_manager_assigned_origin_brand_accesses_despite_creator_moved(): void
    {
        $expense = $this->a1ExpenseWithManagerMovedToB();

        // managerA1 is delegated brand A1 = the expense's origin → access, even
        // though the creating manager now works in company B.
        $this->as($this->managerA1)->getJson("/api/brand-owner/expenses/{$expense->id}")
            ->assertOk()->assertJsonPath('data.id', $expense->id);
        $this->as($this->ownerA1)->getJson("/api/brand-owner/expenses/{$expense->id}")->assertOk();
    }

    public function test_combined02_manager_assigned_other_brand_cannot_access_by_uuid(): void
    {
        $expense = $this->a1ExpenseWithManagerMovedToB();
        $before = $this->writeSnapshot();

        // managerB is delegated brand B1 only; the expense origin is A1 — denied,
        // even though the creating manager now sits on a B branch. Uniform 404.
        $this->as($this->managerB)->getJson("/api/brand-owner/expenses/{$expense->id}")->assertStatus(404);
        $this->as($this->managerB)->postJson("/api/brand-owner/expenses/{$expense->id}/approve")->assertStatus(404);
        $this->as($this->managerB)->postJson("/api/brand-owner/expenses/{$expense->id}/reject", ['reason' => 'nope reason over ten'])->assertStatus(404);
        $this->as($this->ownerB1)->getJson("/api/brand-owner/expenses/{$expense->id}")->assertStatus(404);

        $this->assertSame($before, $this->writeSnapshot(), 'a denied cross-brand access must perform zero writes');
    }

    public function test_combined03_revocation_between_requests_denies_fresh_request(): void
    {
        $expense = $this->pendingExpense($this->bmA1); // origin A1

        $this->as($this->managerA1)->getJson("/api/brand-owner/expenses/{$expense->id}")->assertOk();

        $this->revokeBrandManager($this->managerA1);

        // Fresh authorization on the very next request — no cached scope.
        $this->as($this->managerA1)->getJson("/api/brand-owner/expenses/{$expense->id}")->assertStatus(404);
        $this->as($this->managerA1)->postJson("/api/brand-owner/expenses/{$expense->id}/approve")->assertStatus(404);
    }

    public function test_combined04_one_valid_decision_no_duplicate_effect(): void
    {
        $expense = $this->pendingExpense($this->bmA1); // origin A1

        $this->as($this->managerA1)->postJson("/api/brand-owner/expenses/{$expense->id}/approve")->assertOk();
        $this->assertSame('approved', $expense->fresh()->status);

        $snap = $this->writeSnapshot();
        // A second decision is refused (already decided) and changes nothing.
        $this->as($this->managerA1)->postJson("/api/brand-owner/expenses/{$expense->id}/approve")->assertStatus(400);
        $this->as($this->managerA1)->postJson("/api/brand-owner/expenses/{$expense->id}/reject", ['reason' => 'too late to reject now'])->assertStatus(400);
        $this->assertSame($snap, $this->writeSnapshot());
        $this->assertSame('approved', $expense->fresh()->status);
    }
}
