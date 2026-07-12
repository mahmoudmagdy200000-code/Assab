<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\DailyRollupService;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T03.2 — the §5.2c branch/day rollup state machine:
 * لا بيانات → غير مكتمل → جاهز للتجميع → مُجمَّع → جاهز لـ ERP → مُصدَّر.
 */
class DailyRollupTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Rollup Co', 'plan' => 'Professional', 'status' => 'active']);
        $brandA = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند أ', 'sub_status' => 'active', 'status' => 'active']);
        $brandB = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند ب', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $brandA->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $brandB->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@rollup.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'brand', 'brand_ids' => [$brandA->id],
        ]);

        $this->head = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'رئيس الحسابات', 'email' => 'head@rollup.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
    }

    private function op(string $status, array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'sales',
            'amount' => 1000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => $status,
            'operation_date' => now(),
        ], $attrs));
    }

    private function stateOfBranchA(): array
    {
        $rows = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup')->assertOk()->json('data');

        return collect($rows)->firstWhere('branchId', $this->branchA->id)['state'];
    }

    // ── The six derivations ──────────────────────────────────────────────────

    public function test_a_day_without_operations_is_empty(): void
    {
        $state = $this->stateOfBranchA();

        $this->assertSame('empty', $state['key']);
        $this->assertSame('لا بيانات', $state['labelAr']);
        $this->assertSame('لم يُرفع أي بيان', $state['subLabelAr']);
        $this->assertSame(0, $state['step']);
    }

    public function test_any_pending_operation_makes_the_day_incomplete(): void
    {
        $this->op(Operation::STATUS_PENDING);
        $this->op(Operation::STATUS_FINAL);

        $state = $this->stateOfBranchA();
        $this->assertSame('incomplete', $state['key']);
        $this->assertSame('غير مكتمل', $state['labelAr']);
    }

    public function test_all_approved_means_ready_for_consolidation(): void
    {
        $this->op(Operation::STATUS_APPROVED);
        $this->op(Operation::STATUS_APPROVED);

        $this->assertSame('ready_consolidation', $this->stateOfBranchA()['key']);
    }

    public function test_a_mix_of_approved_and_final_is_consolidated(): void
    {
        $this->op(Operation::STATUS_APPROVED);
        $this->op(Operation::STATUS_FINAL);

        $state = $this->stateOfBranchA();
        $this->assertSame('consolidated', $state['key']);
        $this->assertSame('قيد محاسبي مُغلق — جاهز للدفعة', $state['subLabelAr']);
    }

    public function test_all_final_and_unposted_is_ready_for_erp(): void
    {
        $this->op(Operation::STATUS_FINAL);
        $this->op(Operation::STATUS_FINAL);

        $this->assertSame('ready_erp', $this->stateOfBranchA()['key']);
    }

    public function test_all_final_and_posted_is_exported(): void
    {
        $this->op(Operation::STATUS_FINAL, ['erp_posted' => true]);
        $this->op(Operation::STATUS_FINAL, ['erp_posted' => true]);

        $state = $this->stateOfBranchA();
        $this->assertSame('exported', $state['key']);
        $this->assertSame(5, $state['step']);
    }

    public function test_a_partially_posted_day_is_still_only_ready_for_erp(): void
    {
        $this->op(Operation::STATUS_FINAL, ['erp_posted' => true]);
        $this->op(Operation::STATUS_FINAL);

        $this->assertSame('ready_erp', $this->stateOfBranchA()['key']);
    }

    public function test_a_day_holding_only_rejections_still_owes_data(): void
    {
        $this->op(Operation::STATUS_REJECTED);

        $this->assertSame('incomplete', $this->stateOfBranchA()['key']);
    }

    public function test_erp_imported_is_reserved_and_never_derived(): void
    {
        $service = app(DailyRollupService::class);

        $states = array_map(fn ($counts) => $service->deriveState($counts), [
            ['total' => 0, 'pending' => 0, 'approved' => 0, 'finalApproved' => 0, 'rejected' => 0, 'erpPosted' => 0],
            ['total' => 2, 'pending' => 0, 'approved' => 0, 'finalApproved' => 2, 'rejected' => 0, 'erpPosted' => 2],
        ]);

        $this->assertSame(['empty', 'exported'], $states);
        $this->assertNotContains('erp_imported', $states);
    }

    // ── Counts, filters, scoping ─────────────────────────────────────────────

    public function test_rows_carry_counts_and_amounts(): void
    {
        $this->op(Operation::STATUS_PENDING, ['amount' => 2500]);
        $this->op(Operation::STATUS_FINAL, ['amount' => 1500, 'erp_posted' => true]);

        $row = collect($this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup')->assertOk()->json('data'))
            ->firstWhere('branchId', $this->branchA->id);

        $this->assertSame(2, $row['counts']['total']);
        $this->assertSame(1, $row['counts']['pending']);
        $this->assertSame(1, $row['counts']['finalApproved']);
        $this->assertSame(1, $row['counts']['erpPosted']);
        $this->assertSame(4000, $row['totalAmountHalalas']);
        $this->assertSame('فرع أ', $row['branchName']);
    }

    public function test_a_brand_scoped_accountant_never_sees_another_brands_branch(): void
    {
        $this->op(Operation::STATUS_PENDING, ['branch_id' => $this->branchB->id]);

        $rows = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup')->assertOk()->json('data');
        $this->assertSame([$this->branchA->id], array_column($rows, 'branchId'));

        $rows = $this->actingAs($this->head, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup')->assertOk()->json('data');
        $this->assertCount(2, $rows);
    }

    public function test_yesterday_is_reported_separately_from_today(): void
    {
        $this->op(Operation::STATUS_FINAL, ['operation_date' => now()->subDay()]);

        $today = $this->stateOfBranchA();
        $this->assertSame('empty', $today['key']);

        $rows = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup?date='.now()->subDay()->toDateString())
            ->assertOk()->json('data');
        $this->assertSame('ready_erp', $rows[0]['state']['key']);
    }

    public function test_a_date_range_returns_only_branch_days_with_operations(): void
    {
        $this->op(Operation::STATUS_PENDING, ['operation_date' => now()->subDays(2)]);
        $this->op(Operation::STATUS_FINAL);

        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup?dateFrom='.now()->subDays(3)->toDateString().'&dateTo='.now()->toDateString())
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(['incomplete', 'ready_erp'], array_column(array_column($rows, 'state'), 'key'));
        $this->assertSame(2, $response->json('meta.branchDays'));
        $this->assertSame(1, $response->json('meta.byState.ready_erp'));
    }

    public function test_branch_filter_narrows_the_rows(): void
    {
        $rows = $this->actingAs($this->head, 'sanctum')
            ->getJson('/api/v1/pipeline/daily-rollup?branchId='.$this->branchB->id)
            ->assertOk()->json('data');

        $this->assertSame([$this->branchB->id], array_column($rows, 'branchId'));
    }
}
