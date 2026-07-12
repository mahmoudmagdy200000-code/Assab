<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T10 §8 HEAD-1/HEAD-2 — the head final-approval queue: bulk final-approve,
 * return-for-review, the grouped pending view, and dashboard completeness
 * (accountantsActive + real brand performance).
 */
class HeadFinalApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private AsabUser $head;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Head Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'abbr' => 'BR', 'color' => '#111', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id, 'asab_monthly_target' => 1000000]);

        $this->head = $this->user('head@head.test', 'head', 'all');
        $this->accountant = $this->user('acc@head.test', 'accountant', 'all');
    }

    private function user(string $email, string $role, string $scope): AsabUser
    {
        $u = AsabUser::create(['company_id' => $this->company->id, 'name' => $role, 'email' => $email, 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => $scope, 'branch_ids' => []]);

        return $u;
    }

    private function op(array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'sales',
            'amount' => 100000,
            'match' => 'exact',
            'status' => Operation::STATUS_APPROVED,
            'approved_by_id' => $this->accountant->id,
            'approved_at' => now(),
            'operation_date' => now(),
            'reviewed_at' => now(),
            'submitted_at' => now(),
        ], $attrs));
    }

    private function asHead()
    {
        return $this->actingAs($this->head, 'sanctum');
    }

    // ── T10.1 bulk final-approve ────────────────────────────────────────────────

    public function test_bulk_final_approve_partial_and_steps(): void
    {
        $a = $this->op();
        $b = $this->op();
        $pending = $this->op(['status' => Operation::STATUS_PENDING, 'approved_by_id' => null]);

        $body = $this->asHead()->postJson('/api/v1/operations/bulk-final-approve', [
            'operationIds' => [$a->id, $b->id, $pending->id],
        ])->assertOk()->json();

        $this->assertCount(2, $body['finalApproved']);
        $this->assertCount(1, $body['failed']);
        $this->assertSame('OP_NOT_APPROVED', $body['failed'][0]['code']);

        $this->assertSame(Operation::STATUS_FINAL, $a->fresh()->status);
        $this->assertSame(2, ApprovalStep::whereIn('operation_id', [$a->id, $b->id])->where('stage_id', 'final')->count());
    }

    public function test_bulk_final_approve_is_head_only(): void
    {
        $a = $this->op();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/operations/bulk-final-approve', ['operationIds' => [$a->id]])
            ->assertStatus(403);
    }

    // ── T10.2 return-for-review ─────────────────────────────────────────────────

    public function test_return_for_review_sends_it_back_and_notifies_accountant(): void
    {
        $op = $this->op();

        $this->asHead()->postJson("/api/v1/operations/{$op->id}/return-for-review", ['note' => 'راجع المرفقات'])
            ->assertOk()->assertJsonPath('status', Operation::STATUS_PENDING);

        $op->refresh();
        $this->assertNull($op->approved_by_id);
        $this->assertNull($op->approved_at);
        $this->assertSame(1, AsabNotification::where('user_id', $this->accountant->id)
            ->where('type', 'operation.returned_for_review')->count());
    }

    public function test_return_for_review_conflicts_on_non_approved(): void
    {
        $pending = $this->op(['status' => Operation::STATUS_PENDING, 'approved_by_id' => null]);
        $this->asHead()->postJson("/api/v1/operations/{$pending->id}/return-for-review")
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_NOT_APPROVED');
    }

    public function test_return_for_review_is_head_only(): void
    {
        $op = $this->op();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/return-for-review")->assertStatus(403);
    }

    // ── T10.3 grouped pending ───────────────────────────────────────────────────

    public function test_grouped_pending_groups_by_accountant_and_module_with_diffs(): void
    {
        $acc2 = $this->user('acc2@head.test', 'accountant', 'all');
        $this->op(['module_key' => 'sales', 'amount' => 100000]);
        $this->op(['module_key' => 'sales', 'amount' => 50000, 'match' => 'diff']);
        $this->op(['module_key' => 'expenses', 'amount' => 30000, 'approved_by_id' => $acc2->id]);

        $body = $this->asHead()->getJson('/api/v1/head/operations/pending?view=grouped')->assertOk()->json();

        $groups = collect($body['groups']);
        $this->assertSame(2, $body['summary']['groupCount']);

        $salesGroup = $groups->firstWhere('moduleKey', 'sales');
        $this->assertSame(2, $salesGroup['count']);
        $this->assertSame(150000, $salesGroup['totalAmount']);
        $this->assertTrue($salesGroup['hasDiffs']);

        $expGroup = $groups->firstWhere('moduleKey', 'expenses');
        $this->assertFalse($expGroup['hasDiffs']);
        $this->assertSame($acc2->id, $expGroup['accountantId']);
    }

    public function test_pending_filters_by_brand(): void
    {
        $otherBrand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'ب2', 'sub_status' => 'active', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['asab_brand_id' => $otherBrand->id, 'asab_company_id' => $this->company->id]);
        $this->op(); // brand A
        $this->op(['branch_id' => $otherBranch->id]); // brand B

        $body = $this->asHead()->getJson("/api/v1/head/operations/pending?brandId={$this->brand->id}")->assertOk()->json();
        $this->assertSame(1, $body['meta']['summary']['count']);
    }

    // ── T10.4 dashboard completeness ────────────────────────────────────────────

    public function test_dashboard_has_active_accountants_and_real_brand_performance(): void
    {
        // Seed a posted-independent sales op this month for brand A.
        $this->op(['module_key' => 'sales', 'amount' => 400000, 'status' => Operation::STATUS_FINAL]);

        $body = $this->asHead()->getJson('/api/v1/head/dashboard')->assertOk()->json();

        $this->assertArrayHasKey('accountantsActive', $body['kpis']);
        $this->assertSame(1, $body['kpis']['accountantsActive']['active']);
        $this->assertSame(1, $body['kpis']['accountantsActive']['total']);

        $brand = collect($body['brandPerformance'])->firstWhere('brandId', $this->brand->id);
        $this->assertSame(400000, $brand['salesHalalas']);
        $this->assertEquals(40.0, $brand['pctOfTarget']); // 400000 / 1000000 target

        $stageIds = collect($body['pipeline'])->pluck('stageId');
        $this->assertTrue($stageIds->contains('submit'));
        $this->assertTrue($stageIds->contains('reports'));
    }

    /** Review finding: approve must stamp reviewed_at, else all head throughput KPIs read 0. */
    public function test_approve_stamps_reviewed_at_and_feeds_dashboard(): void
    {
        $op = $this->op(['status' => Operation::STATUS_PENDING, 'approved_by_id' => null, 'reviewed_at' => null, 'reviewed_by_id' => null]);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")->assertOk();

        $this->assertNotNull($op->fresh()->reviewed_at);
        $this->assertSame($this->accountant->id, $op->fresh()->reviewed_by_id);

        $kpis = $this->asHead()->getJson('/api/v1/head/dashboard')->assertOk()->json('kpis');
        $this->assertGreaterThanOrEqual(1, $kpis['totalReviewedThisMonth']);
    }

    public function test_final_approved_list_erp_posted_filter(): void
    {
        $this->op(['status' => Operation::STATUS_FINAL, 'erp_posted' => false]);
        $this->op(['status' => Operation::STATUS_FINAL, 'erp_posted' => true, 'erp_batch_id' => 'EXP-X']);

        $body = $this->asHead()->getJson('/api/v1/head/operations/final-approved?erpPosted=false')->assertOk()->json();
        $this->assertSame(1, $body['meta']['summary']['count']);
    }
}
