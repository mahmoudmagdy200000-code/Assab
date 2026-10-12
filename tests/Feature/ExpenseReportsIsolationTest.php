<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\BrandOwner\Services\ReportFileExportService;
use Modules\Custody\Models\CustodyTransaction;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * A-1 — tenant isolation of the Brand Owner Reports & Analytics surface
 * (expense report details, custody report, and the exports built from them).
 *
 * The leak being closed: these endpoints aggregated over EVERY branch, so a
 * brand owner / brand manager (and even an unlinked actor) received global
 * totals (own 100 + another tenant 7,777 = 7,877). Every report is now
 * confined, inside the DB query, to the branches the actor is assigned to.
 *
 * Fixture money (approved quick_cash, current month):
 *   Brand A1 (company A) = 100 ; Brand A2 (company A) = 500 ; Brand B1 (company B) = 7,777
 *
 * Amounts are compared with a float cast because the API serialises whole
 * values without a fraction (100.0 -> JSON 100), which assertJsonPath would
 * strict-compare as an int.
 */
class ExpenseReportsIsolationTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();

        $this->approvedQuickCash($this->bmA1, 100.00);
        $this->approvedQuickCash($this->bmA2, 500.00);
        $this->approvedQuickCash($this->bmB1, 7777.00);

        $this->custodyCashIn($this->bmA1, $this->branchA1->id, 1000.00);
        $this->custodyCashIn($this->bmA2, $this->branchA2->id, 2000.00);
        $this->custodyCashIn($this->bmB1, $this->branchB1->id, 9999.00);
    }

    private function approvedQuickCash(\Modules\BranchManagers\Models\BranchManager $manager, float $amount): void
    {
        $this->pendingExpense($manager, [
            'status' => 'approved',
            'total_amount' => $amount,
            'net_amount' => $amount,
            'vat_amount' => 0.00,
        ]);
    }

    private function custodyCashIn(\Modules\BranchManagers\Models\BranchManager $manager, string $branchId, float $amount): void
    {
        CustodyTransaction::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branchId,
            'type' => 'Cash Transfer',
            'handover_method' => 'Cash Handover',
            'amount' => $amount,
            'is_cash_in' => true,
            'transaction_date' => now(),
        ]);
    }

    private function expenseReport($actor, array $query = []): TestResponse
    {
        $qs = http_build_query(array_merge(['type' => 'quick_cash'], $query));

        return $this->actingAs($actor, 'sanctum')->getJson('/api/brand-owner/reports/expense/expenses?'.$qs);
    }

    private function total(TestResponse $r): float
    {
        return (float) $r->json('data.summary.total_amount');
    }

    // ---- expense report details -------------------------------------------

    public function test_single_brand_owner_sees_only_own_brand_total(): void
    {
        $r = $this->expenseReport($this->ownerA1)->assertStatus(200);
        $this->assertSame(100.0, $this->total($r));
        $r->assertJsonPath('data.summary.total_requests', 1);
    }

    public function test_multi_brand_owner_aggregates_only_owned_brands(): void
    {
        $r = $this->expenseReport($this->ownerA12)->assertStatus(200);
        $this->assertSame(600.0, $this->total($r));   // 100 + 500, NOT 7,877
        $r->assertJsonPath('data.summary.total_requests', 2);

        $names = collect($r->json('data.branch_comparisons'))->pluck('name');
        $this->assertContains($this->branchA1->name, $names);
        $this->assertContains($this->branchA2->name, $names);
        $this->assertNotContains($this->branchB1->name, $names);
    }

    public function test_other_company_owner_sees_only_its_own_total(): void
    {
        $r = $this->expenseReport($this->ownerB1)->assertStatus(200);
        $this->assertSame(7777.0, $this->total($r));
        $r->assertJsonPath('data.summary.total_requests', 1);
    }

    public function test_unlinked_owner_sees_no_expense_data(): void
    {
        $r = $this->expenseReport($this->ownerUnlinked)->assertStatus(200);
        $this->assertSame(0.0, $this->total($r));
        $r->assertJsonPath('data.summary.total_requests', 0)
            ->assertJsonPath('data.summary.branch', null)
            ->assertJsonPath('data.default_branch', null);
        $this->assertEmpty($r->json('data.branch_comparisons'));
    }

    public function test_brand_manager_report_scoped_to_assigned_brand(): void
    {
        // The brand manager must NOT inherit unrestricted brand-owner reach.
        $r = $this->expenseReport($this->managerA1)->assertStatus(200);
        $this->assertSame(100.0, $this->total($r));   // only Brand A1, not 7,877
        $r->assertJsonPath('data.summary.total_requests', 1);
    }

    public function test_unassigned_brand_manager_sees_no_expense_data(): void
    {
        $r = $this->expenseReport($this->managerUnassigned)->assertStatus(200);
        $this->assertSame(0.0, $this->total($r));
        $r->assertJsonPath('data.summary.total_requests', 0);
    }

    public function test_client_branch_id_cannot_broaden_scope(): void
    {
        // Owner A1 asks for another tenant's branch — must get zero, not 7,777.
        $r = $this->expenseReport($this->ownerA1, ['branch_id' => $this->branchB1->id])->assertStatus(200);
        $this->assertSame(0.0, $this->total($r));
        $r->assertJsonPath('data.summary.total_requests', 0);
        $this->assertEmpty($r->json('data.branch_comparisons'));
        // IR-PKG2-03: an out-of-scope branch_id yields an empty effective scope,
        // so default_branch/summary.branch must be null — not some other allowed
        // branch the caller did not ask for.
        $r->assertJsonPath('data.default_branch', null)
            ->assertJsonPath('data.summary.branch', null);
    }

    public function test_client_branch_id_narrows_within_scope(): void
    {
        // Owner of A1+A2 narrows to A2 → only the 500.
        $r = $this->expenseReport($this->ownerA12, ['branch_id' => $this->branchA2->id])->assertStatus(200);
        $this->assertSame(500.0, $this->total($r));
        $r->assertJsonPath('data.summary.total_requests', 1)
            ->assertJsonPath('data.filters.branch_id', $this->branchA2->id);
    }

    // ---- custody report ----------------------------------------------------

    public function test_custody_report_lists_only_owned_branches(): void
    {
        $r = $this->actingAs($this->ownerA1, 'sanctum')->getJson('/api/brand-owner/reports/custody/custody');
        $r->assertStatus(200);
        $names = collect($r->json('data.branches'))->pluck('name');
        $this->assertContains($this->branchA1->name, $names);
        $this->assertNotContains($this->branchA2->name, $names);
        $this->assertNotContains($this->branchB1->name, $names);
    }

    public function test_unlinked_owner_custody_report_is_empty(): void
    {
        $r = $this->actingAs($this->ownerUnlinked, 'sanctum')->getJson('/api/brand-owner/reports/custody/custody');
        $r->assertStatus(200);
        $this->assertEmpty($r->json('data.branches'));
    }

    // ---- exports inherit the scope ----------------------------------------

    public function test_expense_export_contains_only_authorized_records(): void
    {
        $spy = $this->swapExporter();

        $this->actingAs($this->ownerA1, 'sanctum')->postJson('/api/brand-owner/reports/expense/export', [
            'expense_type' => 'quick_cash', 'year' => (int) now()->year,
            'month_number' => (int) now()->month, 'format_type' => 'Excel',
        ])->assertStatus(200);

        // The data handed to the file writer is the scoped total, not the global one.
        $this->assertSame(100.0, (float) $spy->captured['expense']['summary']['total_amount']);
    }

    public function test_unlinked_owner_export_has_no_records(): void
    {
        $spy = $this->swapExporter();

        $this->actingAs($this->ownerUnlinked, 'sanctum')->postJson('/api/brand-owner/reports/expense/export', [
            'expense_type' => 'quick_cash', 'year' => (int) now()->year,
            'month_number' => (int) now()->month, 'format_type' => 'Excel',
        ])->assertStatus(200);

        $this->assertSame(0.0, (float) $spy->captured['expense']['summary']['total_amount']);
    }

    private function swapExporter(): ReportFileExportService
    {
        $spy = new class extends ReportFileExportService
        {
            /** @var array<string, array> */
            public array $captured = [];

            public function exportExpense(array $details, string $formatType, string $title): string
            {
                $this->captured['expense'] = $details;

                return 'brand-owner/reports/fake-expense.csv';
            }

            public function exportCustody(array $details, string $formatType, string $title): string
            {
                $this->captured['custody'] = $details;

                return 'brand-owner/reports/fake-custody.csv';
            }
        };
        $this->app->instance(ReportFileExportService::class, $spy);

        return $spy;
    }

    // ---- report reads never write -----------------------------------------

    public function test_report_reads_perform_no_financial_writes(): void
    {
        $before = $this->writeSnapshot();

        $this->expenseReport($this->ownerA1)->assertStatus(200);
        $this->expenseReport($this->ownerB1)->assertStatus(200);
        $this->actingAs($this->ownerA1, 'sanctum')->getJson('/api/brand-owner/reports/custody/custody')->assertStatus(200);

        $this->assertSame($before, $this->writeSnapshot());
    }

    // ---- branch-level manager own-branch behaviour must not regress --------

    public function test_branch_level_manager_report_stays_own_branch_only(): void
    {
        // bmA1 is a branch-level manager on Branch A1 (company A). Even though
        // Brand A1 has other branches, a branch-level manager sees ONLY their
        // own branch — never the whole brand.
        $r = $this->actingAs($this->bmA1, 'sanctum')
            ->getJson('/api/brand-owner/reports/expense/expenses?type=quick_cash')
            ->assertStatus(200);

        $this->assertSame(100.0, $this->total($r));
        $r->assertJsonPath('data.filters.branch_id', $this->branchA1->id);

        $names = collect($r->json('data.branch_comparisons'))->pluck('name');
        foreach ($names as $name) {
            $this->assertSame($this->branchA1->name, $name);
        }
    }
}
