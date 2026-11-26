<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\HeadMetricsService;
use Modules\Admin\Services\PipelineService;
use Modules\Branch\Models\Branch;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Expense\Models\Expense;
use Modules\Expense\Services\QuickCashExpenseService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * Verifies reconciliation of the 4 Backend main-only commits (PR #29, PR #32)
 * atop the Stage 5 merged baseline without regressing Wave 1-3 or Stage 0-5 behavior.
 */
class Stage6MainReconciliationTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    public function test_head_brand_performance_and_pipeline_aggregation_exclude_rejected_operations(): void
    {
        $company = AsabCompany::create(['name' => 'Reconcile Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id, 'name' => 'براند التوفيق', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $branch = Branch::factory()->create([
            'name' => 'فرع التوفيق', 'asab_brand_id' => $brand->id, 'asab_company_id' => $company->id,
        ]);

        Operation::create([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'sales',
            'amount' => 100000,
            'match' => 'exact',
            'status' => Operation::STATUS_APPROVED,
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);
        Operation::create([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'sales',
            'amount' => 50000,
            'match' => 'diff',
            'status' => Operation::STATUS_REJECTED,
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);
        Operation::create([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'expenses',
            'amount' => 25000,
            'match' => 'exact',
            'status' => Operation::STATUS_FINAL,
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);
        Operation::create([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'expenses',
            'amount' => 15000,
            'match' => 'diff',
            'status' => Operation::STATUS_REJECTED,
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);

        $perf = app(HeadMetricsService::class)->brandPerformance($company->id);
        $this->assertCount(1, $perf);
        $this->assertSame(100000, $perf[0]['salesHalalas']);
        $this->assertSame(25000, $perf[0]['expensesHalalas']);
        $this->assertSame(75000, $perf[0]['netHalalas']);

        $agg = collect(app(PipelineService::class)->aggregation($company->id, null, null))
            ->keyBy('moduleKey');
        $this->assertSame(100000, $agg['sales']['totalAmountHalalas']);
        $this->assertSame(1, $agg['sales']['rejectedCount']);
        $this->assertSame(25000, $agg['expenses']['totalAmountHalalas']);
        $this->assertSame(1, $agg['expenses']['rejectedCount']);
    }

    public function test_quick_cash_expense_parses_string_is_draft_boolean_and_preserves_custody_reservation(): void
    {
        $this->buildExpenseTenants();
        CustodyTransaction::create([
            'branch_manager_id' => $this->bmA1->id,
            'branch_id' => $this->bmA1->branch_id,
            'type' => 'Cash Transfer',
            'amount' => 1000,
            'is_cash_in' => true,
            'transaction_date' => now(),
        ]);

        $this->actingAs($this->bmA1, 'sanctum');
        Event::fake([ExpenseSubmittedEvent::class]);

        $service = app(QuickCashExpenseService::class);
        $submitted = $service->createQuickCashExpense([
            'expense_date' => now()->toDateString(),
            'expense_name' => 'مصروف غير مسودة',
            'total_amount' => 120,
            'net_amount' => 120,
            'vat_amount' => 0,
            'payment_method' => 'custody',
            'is_draft' => 'false',
            'items' => [['title' => 'بند', 'amount' => 120]],
        ]);

        $this->assertSame('pending', $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        Event::assertDispatched(ExpenseSubmittedEvent::class, 1);
        $this->assertSame(
            1,
            CustodyTransaction::withoutGlobalScopes()->where('related_expense_id', $submitted->id)->count()
        );

        $draft = $service->createQuickCashExpense([
            'expense_date' => now()->toDateString(),
            'expense_name' => 'مسودة صريحة',
            'total_amount' => 50,
            'net_amount' => 50,
            'vat_amount' => 0,
            'payment_method' => 'custody',
            'is_draft' => 'true',
            'items' => [['title' => 'بند مسودة', 'amount' => 50]],
        ]);

        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->submitted_at);

        $updated = $service->updateQuickCashExpense(Expense::withoutGlobalScopes()->findOrFail($draft->id), [
            'is_draft' => 'false',
        ]);
        $this->assertSame('pending', $updated->status);
        $this->assertNotNull($updated->submitted_at);
    }
}
