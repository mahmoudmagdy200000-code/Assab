<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BrandOwner\Services\Financial\FinancialDataService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * F-01 — the financial-statements reader (FinancialDataService) must attribute an
 * expense to the branch that OWNED it at creation (immutable origin_branch_id),
 * not the manager's current branch. A supported manager move must not move the
 * expense's cost between branches' P&L. Formulas/ledgers are unchanged; only the
 * branch predicate keys on the immutable origin.
 */
class FinancialStatementOriginOwnershipTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private function data(): FinancialDataService
    {
        return app(FinancialDataService::class);
    }

    public function test_direct_cost_follows_the_immutable_origin_branch_after_a_manager_move(): void
    {
        $this->buildExpenseTenants();

        // An approved invoice-type (direct-cost) expense created at Branch A1.
        $this->pendingExpense($this->bmA1, [
            'expense_type' => 'single_invoice', 'status' => 'approved',
            'total_amount' => 300.0, 'net_amount' => 300.0, 'vat_amount' => 0.0,
        ]);

        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        // Baseline: the cost belongs to Branch A1, not Branch A2.
        $this->assertSame(300.0, $this->data()->directCost($this->branchA1->id, $start, $end));
        $this->assertSame(0.0, $this->data()->directCost($this->branchA2->id, $start, $end));

        // Supported move: the manager is reassigned to Branch A2 (empty destination).
        $destB = \Modules\Branch\Models\Branch::factory()->create([
            'name' => 'A2 empty', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA2->id,
        ]);
        $this->bmA1->forceFill(['branch_id' => $destB->id])->save();

        // The cost stays on Branch A1 (origin) and never appears on the destination.
        $this->assertSame(300.0, $this->data()->directCost($this->branchA1->id, $start, $end), 'direct cost must stay with the origin branch after the manager moves');
        $this->assertSame(0.0, $this->data()->directCost($destB->id, $start, $end), 'the destination branch must not inherit the historic cost');
    }

    public function test_supplier_obligations_follow_the_immutable_origin_branch(): void
    {
        $this->buildExpenseTenants();

        // A pending, supplier-paid invoice obligation created at Branch A1.
        $this->pendingExpense($this->bmA1, [
            'expense_type' => 'single_invoice', 'status' => 'pending', 'payment_method' => 'supplier',
            'total_amount' => 150.0, 'net_amount' => 150.0, 'vat_amount' => 0.0,
        ]);

        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        // Empty destination (the observer rejects an occupied branch).
        $destB = \Modules\Branch\Models\Branch::factory()->create([
            'name' => 'A2 empty obl', 'asab_company_id' => $this->companyA->id, 'asab_brand_id' => $this->brandA2->id,
        ]);
        $this->bmA1->forceFill(['branch_id' => $destB->id])->save();

        // Obligation still totals under Branch A1 (origin), not the manager's new branch.
        $this->assertSame(150.0, $this->data()->supplierObligations($this->branchA1->id, $start, $end)['total']);
        $this->assertSame(0.0, $this->data()->supplierObligations($destB->id, $start, $end)['total']);
    }
}
