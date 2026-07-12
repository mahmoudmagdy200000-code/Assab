<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T04 — ACC-1: channel reconciliation, the matching-table columns, sales KPIs,
 * the day-completeness banner and the operation attachments panel.
 */
class SalesReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Sales Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA = Branch::factory()->create(['name' => 'فرع الرياض - العليا', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع جدة - الحمراء', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@sales.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
    }

    private function salesOp(int $amount = 1834000, array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'sales',
            'amount' => $amount,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'submitted_at' => now(),
            'operation_date' => now(),
        ], $attrs));
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    // ── §4.3 channel enum ────────────────────────────────────────────────────

    public function test_reconciliation_accepts_the_canonical_channel_rows(): void
    {
        $op = $this->salesOp(1000000);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 420000, 'posAmountHalalas' => 420000],
                ['key' => 'bank', 'actualAmountHalalas' => 400000, 'posAmountHalalas' => 400000],
                ['key' => 'jahez', 'actualAmountHalalas' => 120000, 'posAmountHalalas' => 135000],
                ['key' => 'ninja', 'actualAmountHalalas' => 60000, 'posAmountHalalas' => 60000],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('totalCollectionHalalas', 1000000)
            ->assertJsonPath('varianceHalalas', 0)
            ->assertJsonPath('match', 'exact');

        $detail = $this->acc()->getJson("/api/v1/operations/{$op->id}")->assertOk()->json('reconciliation');

        $jahez = collect($detail['channels'])->firstWhere('key', 'jahez');
        $this->assertSame('جاهز', $jahez['labelAr']);
        $this->assertSame('🟡', $jahez['icon']);
        $this->assertSame(-15000, $jahez['diffHalalas']);
        $this->assertSame('diff', $jahez['status']);

        $this->assertSame('مطابق', $detail['totals']['statusLabelAr']);
        $this->assertFalse($detail['isLocked']);
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $op = $this->salesOp();

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [['key' => 'careem', 'actualAmountHalalas' => 1000]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_SALES_CHANNEL')
            ->assertJsonPath('error.details.allowed.0', 'pos');
    }

    public function test_the_legacy_cash_bank_delivery_body_still_works(): void
    {
        $op = $this->salesOp(500000);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'cashHalalas' => 200000,
            'bankHalalas' => 250000,
            'deliveryApps' => [['name' => 'هنقرستيشن', 'amountHalalas' => 50000]],
        ])->assertOk()->assertJsonPath('varianceHalalas', 0);

        $channels = $this->acc()->getJson("/api/v1/operations/{$op->id}")
            ->assertOk()->json('reconciliation.channels');

        $this->assertSame(['cash', 'bank', 'hungerstation'], array_column($channels, 'key'));
    }

    // ── T04.7 match recompute ────────────────────────────────────────────────

    public function test_a_shortfall_flips_the_match_badge_and_writes_a_diff_note(): void
    {
        $op = $this->salesOp(1000000);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 965000]],
        ])->assertOk()->assertJsonPath('varianceHalalas', -35000)->assertJsonPath('match', 'diff');

        $op->refresh();
        $this->assertSame('diff', $op->match);
        $this->assertSame('نقص في التحصيل: 350.00 ر.س', $op->diff_note);

        // Reconciling to zero clears the badge again.
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 1000000]],
        ])->assertOk()->assertJsonPath('match', 'exact');

        $op->refresh();
        $this->assertSame('exact', $op->match);
        $this->assertNull($op->diff_note);
    }

    public function test_the_branch_total_is_locked_and_final_operations_refuse_edits(): void
    {
        $op = $this->salesOp(1000000);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'amount' => 1,
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 1000000]],
        ])->assertOk();
        $this->assertSame(1000000, $op->fresh()->amount);

        $final = $this->salesOp(500000, ['status' => Operation::STATUS_FINAL]);
        $this->acc()->patchJson("/api/v1/company/me/operations/{$final->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');
    }

    // ── T04.6 matching-table columns ─────────────────────────────────────────

    public function test_sales_rows_carry_the_matching_table_columns(): void
    {
        $op = $this->salesOp(1000000);
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 400000],
                ['key' => 'bank', 'actualAmountHalalas' => 400000],
                ['key' => 'talabat', 'actualAmountHalalas' => 150000],
            ],
        ])->assertOk();

        $row = collect($this->acc()->getJson('/api/v1/operations?moduleKey=sales')->assertOk()->json('data'))
            ->firstWhere('id', $op->id);

        $this->assertSame(400000, $row['salesBreakdown']['cashHalalas']);
        $this->assertSame(400000, $row['salesBreakdown']['cardHalalas']);
        $this->assertSame(150000, $row['salesBreakdown']['appsHalalas']);
        $this->assertSame(1000000, $row['salesBreakdown']['totalSalesHalalas']);
        $this->assertSame(950000, $row['salesBreakdown']['collectedHalalas']);
        $this->assertSame(-50000, $row['salesBreakdown']['varianceHalalas']);
    }

    public function test_an_unreconciled_sales_row_has_a_null_breakdown(): void
    {
        $op = $this->salesOp();

        $row = collect($this->acc()->getJson('/api/v1/operations?moduleKey=sales')->assertOk()->json('data'))
            ->firstWhere('id', $op->id);

        $this->assertNull($row['salesBreakdown']);
    }

    // ── ACC-1.1 KPIs ─────────────────────────────────────────────────────────

    public function test_sales_kpis_sum_todays_operations_and_list_variance_branches(): void
    {
        $a = $this->salesOp(1000000);
        $b = $this->salesOp(500000, ['branch_id' => $this->branchB->id, 'public_id' => OperationSequence::next('OPS')]);
        $this->salesOp(800000, ['operation_date' => now()->subDay(), 'public_id' => OperationSequence::next('OPS')]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$a->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 965000]],
        ])->assertOk();
        $this->acc()->patchJson("/api/v1/company/me/operations/{$b->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 500000]],
        ])->assertOk();

        $kpis = $this->acc()->getJson('/api/v1/company/me/sales/kpis')->assertOk()->json();

        $this->assertSame(1500000, $kpis['totalSalesHalalas']);
        $this->assertSame(2, $kpis['branchCount']);
        $this->assertSame(1465000, $kpis['totalCollectedHalalas']);
        $this->assertSame(-35000, $kpis['totalVarianceHalalas']);
        $this->assertSame(1, $kpis['varianceCaseCount']);
        $this->assertSame(1, $kpis['zeroVarianceBranchCount']);
        $this->assertSame('فرع الرياض - العليا', $kpis['varianceBranches'][0]['name']);
        $this->assertSame(87.5, $kpis['trendPct']);
    }

    public function test_sales_kpis_return_zeros_on_an_empty_day(): void
    {
        $kpis = $this->acc()->getJson('/api/v1/company/me/sales/kpis')->assertOk()->json();

        $this->assertSame(0, $kpis['totalSalesHalalas']);
        $this->assertSame(0, $kpis['varianceCaseCount']);
        $this->assertNull($kpis['trendPct']);
    }

    // ── ACC-1.2 day completeness ─────────────────────────────────────────────

    public function test_day_completeness_reports_the_missing_branches(): void
    {
        Branch::factory()->create(['name' => 'فرع ثالث', 'asab_company_id' => $this->company->id]);
        $this->salesOp();

        $today = $this->acc()->getJson('/api/v1/company/me/sales/day-completeness?days=3')
            ->assertOk()->json('data.0');

        $this->assertSame('اليوم', $today['pillLabelAr']);
        $this->assertSame(3, $today['requiredCount']);
        $this->assertSame(1, $today['completedCount']);
        $this->assertSame(2, $today['missingCount']);
        $this->assertSame('3 عملية مطلوبة — 1 مكتملة · 2 ناقصة', $today['bannerAr']);
        $this->assertContains('فرع جدة - الحمراء', array_column($today['missingBranches'], 'name'));
    }

    public function test_day_completeness_labels_the_pills(): void
    {
        $labels = array_column(
            $this->acc()->getJson('/api/v1/company/me/sales/day-completeness?days=3')->assertOk()->json('data'),
            'pillLabelAr',
        );

        $this->assertSame(['اليوم', 'أمس', 'قبل يومين'], $labels);
    }

    // ── T04.8 attachments ────────────────────────────────────────────────────

    public function test_operation_attachments_are_listed(): void
    {
        $op = $this->salesOp();
        foreach (['تقرير POS الرئيسي.pdf', 'كشف بنك الرياض.pdf'] as $name) {
            Attachment::create([
                'owner_type' => 'operation', 'owner_id' => $op->id, 'filename' => $name,
                'mime_type' => 'application/pdf', 'size' => 245000, 'storage_key' => 'ops/'.$name,
                'public_url' => 'https://files.test/'.$name, 'uploaded_at' => now(),
            ]);
        }

        $response = $this->acc()->getJson("/api/v1/operations/{$op->id}/attachments")->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('تقرير POS الرئيسي.pdf', $response->json('data.0.filename'));
        $this->assertSame('application/pdf', $response->json('data.0.mimeType'));
    }
}
