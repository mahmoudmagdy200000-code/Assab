<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T04.5 — ACC-0 «ملخص اليوم»: the KPI block, the nine-module grid with its
 * urgent dot, today's progress bars and the accountant's scope subtitle.
 */
class AccountantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brandA;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Dash Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند أ', 'sub_status' => 'active', 'status' => 'active']);
        $brandB = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند ب', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $brandB->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@dash.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [$this->brandA->id], 'module_keys' => ['sales', 'expenses'],
        ]);

        $this->head = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'رئيس الحسابات', 'email' => 'head@dash.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
    }

    private function op(array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'sales',
            'amount' => 100000,
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

    // ── KPIs ─────────────────────────────────────────────────────────────────

    public function test_approval_rate_reflects_the_actors_own_decisions(): void
    {
        // 3 approved by me, 1 rejected by me → 75%.
        foreach (range(1, 3) as $i) {
            $this->op(['status' => Operation::STATUS_APPROVED, 'approved_by_id' => $this->accountant->id]);
        }
        $this->op(['status' => Operation::STATUS_REJECTED, 'rejected_by_id' => $this->accountant->id]);
        // Somebody else's approval must not count.
        $this->op(['status' => Operation::STATUS_APPROVED, 'approved_by_id' => $this->head->id]);

        $counts = $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('counts');

        $this->assertSame(75, $counts['approvalRatePct']);
        $this->assertSame(3, $counts['iApproved']);
        $this->assertSame(1, $counts['rejected']);
    }

    public function test_approval_rate_is_zero_when_nothing_was_reviewed(): void
    {
        $this->op();

        $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')
            ->assertOk()->assertJsonPath('counts.approvalRatePct', 0);
    }

    public function test_new_today_counts_only_todays_submissions(): void
    {
        $this->op();
        $this->op(['submitted_at' => now()->subDays(3), 'operation_date' => now()->subDays(3)]);

        $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')
            ->assertOk()->assertJsonPath('counts.newTodayCount', 1);
    }

    // ── Nine-module grid ─────────────────────────────────────────────────────

    public function test_the_grid_always_returns_the_nine_modules(): void
    {
        $modules = $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('modules');

        $this->assertCount(9, $modules);
        $this->assertSame(ModuleCatalog::keys(), array_column($modules, 'key'));
        $this->assertSame('المبيعات', $modules[0]['labelAr']);
        $this->assertSame(0, $modules[0]['totalCount']);
    }

    public function test_a_pending_diff_operation_raises_the_urgent_dot(): void
    {
        $this->op(['match' => 'diff']);
        $this->op(['module_key' => 'expenses', 'public_id' => OperationSequence::next('EXP')]);

        $modules = collect($this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('modules'))
            ->keyBy('key');

        $this->assertTrue($modules['sales']['hasUrgent']);
        $this->assertSame(1, $modules['sales']['pendingCount']);
        $this->assertFalse($modules['expenses']['hasUrgent']);
    }

    public function test_a_stale_pending_operation_raises_the_urgent_dot(): void
    {
        $this->op(['submitted_at' => now()->subDays(3)]);

        $modules = collect($this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('modules'))
            ->keyBy('key');

        $this->assertTrue($modules['sales']['hasUrgent']);
    }

    // ── Progress + scope ─────────────────────────────────────────────────────

    public function test_progress_today_measures_review_approval_and_documentation(): void
    {
        $this->op();                                                          // pending, no files
        $this->op(['status' => Operation::STATUS_APPROVED, 'attachment_count' => 2]);
        $this->op(['status' => Operation::STATUS_FINAL, 'attachment_count' => 1]);
        $this->op(['status' => Operation::STATUS_REJECTED]);

        $progress = $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('progressToday');

        $this->assertSame(4, $progress['operationsToday']);
        $this->assertSame(75, $progress['reviewPct']);        // 3 of 4 left `pending`
        $this->assertSame(50, $progress['approvalPct']);      // approved + final
        $this->assertSame(50, $progress['documentationPct']); // 2 of 4 carry files
        $this->assertSame(100, $progress['completedBranchesPct']);
    }

    public function test_progress_today_is_zero_without_operations(): void
    {
        $progress = $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('progressToday');

        $this->assertSame(0, $progress['reviewPct']);
        $this->assertSame(0, $progress['completedBranchesPct']);
    }

    public function test_the_scope_block_describes_the_assignment(): void
    {
        $scope = $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('scope');

        $this->assertSame(1, $scope['branchCount']);
        $this->assertFalse($scope['isCompanyWide']);
        $this->assertSame(['sales', 'expenses'], $scope['moduleKeys']);
        $this->assertSame(['المبيعات', 'المصروفات'], $scope['moduleLabelsAr']);

        $headScope = $this->actingAs($this->head, 'sanctum')
            ->getJson('/api/v1/accountant/dashboard')->assertOk()->json('scope');
        $this->assertTrue($headScope['isCompanyWide']);
        $this->assertSame(2, $headScope['branchCount']);
    }

    // ── Zero-trust ───────────────────────────────────────────────────────────

    public function test_the_dashboard_never_counts_another_brands_branch(): void
    {
        $this->op();
        $this->op(['branch_id' => $this->branchB->id]);

        $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')
            ->assertOk()->assertJsonPath('counts.awaitingReview', 1);

        $this->actingAs($this->head, 'sanctum')->getJson('/api/v1/accountant/dashboard')
            ->assertOk()->assertJsonPath('kpis.awaitingReview', 2);
    }

    public function test_both_surfaces_expose_the_same_grid(): void
    {
        $this->op(['match' => 'diff']);

        $company = $this->acc()->getJson('/api/v1/company/me/accountant/dashboard')->assertOk()->json('modules');
        $platform = $this->acc()->getJson('/api/v1/accountant/dashboard')->assertOk()->json('modules');

        $this->assertSame(array_column($company, 'key'), array_column($platform, 'key'));
        $this->assertSame($company[0]['hasUrgent'], $platform[0]['hasUrgent']);
    }

    public function test_the_legacy_operations_list_filters_by_date(): void
    {
        $this->op();
        $old = $this->op(['operation_date' => now()->subDays(5)]);

        $rows = $this->acc()->getJson('/api/v1/accountant/operations?dateFrom='.now()->subDay()->toDateString())
            ->assertOk()->json('data');

        $this->assertNotContains($old->id, array_column($rows, 'id'));
    }

    /**
     * meta.summary must survive pagination: paginate() mutates the builder
     * with limit/offset, so a clone taken afterwards counted an empty window
     * past page 1 and every bucket silently read 0.
     */
    public function test_operations_summary_is_identical_on_every_page(): void
    {
        $this->op();
        $this->op();
        $this->op(['status' => Operation::STATUS_APPROVED]);
        $this->op(['status' => Operation::STATUS_REJECTED]);

        $expected = ['totalUploaded' => 4, 'underReview' => 2, 'approved' => 1, 'rejected' => 1];

        foreach ([1, 3] as $page) {
            $summary = $this->acc()->getJson("/api/v1/accountant/operations?pageSize=1&page={$page}")
                ->assertOk()->json('meta.summary');
            $this->assertSame($expected, $summary, "summary drifted on page {$page}");
        }
    }

    /** A ?status= tab must not zero the sibling summary buckets. */
    public function test_operations_summary_ignores_the_status_filter(): void
    {
        $this->op();
        $this->op(['status' => Operation::STATUS_APPROVED]);
        $this->op(['status' => Operation::STATUS_REJECTED]);

        $body = $this->acc()->getJson('/api/v1/accountant/operations?status=approved')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame(
            ['totalUploaded' => 3, 'underReview' => 1, 'approved' => 1, 'rejected' => 1],
            $body['meta']['summary'],
        );
    }
}
