<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T10 §14.3 ERP-1..3 — batch conformance (per day × module), the
 * ready → exported | failed lifecycle, retry, the admin export screen, and the
 * tenant/role hardening of the shared ERP endpoints.
 */
class ErpBatchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $head;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Erp Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->head = $this->user($this->company->id, 'head@erp.test', 'head');
        $this->admin = $this->user($this->company->id, 'admin@erp.test', 'admin');
    }

    private function user(string $companyId, string $email, string $role): AsabUser
    {
        $u = AsabUser::create(['company_id' => $companyId, 'name' => $role, 'email' => $email, 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all', 'branch_ids' => []]);

        return $u;
    }

    private function finalOp(string $module, $date, int $amount = 100000, ?string $companyId = null, ?string $branchId = null): Operation
    {
        return Operation::create([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $companyId ?? $this->company->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'module_key' => $module,
            'amount' => $amount,
            'match' => 'exact',
            'status' => Operation::STATUS_FINAL,
            'erp_posted' => false,
            'operation_date' => $date,
        ]);
    }

    private function asHead()
    {
        return $this->actingAs($this->head, 'sanctum');
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    // ── T10.5 per day × module batches ──────────────────────────────────────────

    public function test_export_splits_into_one_batch_per_day_and_module(): void
    {
        $day1 = now();
        $day2 = now()->subDay();
        $this->finalOp('sales', $day1, 100000);
        $this->finalOp('expenses', $day1, 20000);
        $this->finalOp('sales', $day2, 50000);
        $this->finalOp('expenses', $day2, 30000);

        $body = $this->asHead()->postJson('/api/v1/erp/batches', [])->assertStatus(201)->json();

        $this->assertSame(4, $body['count']);
        $this->assertSame(4, ErpBatch::count());
        foreach ($body['batches'] as $b) {
            $this->assertStringStartsWith('EXP-', $b['batchId']);
            $this->assertSame('exported', $b['status']);
            $this->assertSame(1, $b['operationCount']);
        }
        $this->assertSame(4, Operation::where('erp_posted', true)->count());
    }

    public function test_export_with_no_eligible_ops_is_422(): void
    {
        $this->asHead()->postJson('/api/v1/erp/batches', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'NO_ELIGIBLE_OPS');
    }

    /**
     * Review finding: a single-op export must not flip the whole pre-seeded
     * (day × module) ready batch to exported while stranding its other members.
     * Posting one member posts the full batch (day × module is the ERP unit).
     */
    public function test_single_op_export_posts_the_full_day_module_batch(): void
    {
        $accountant = $this->user($this->company->id, 'acc-strand@erp.test', 'accountant');
        $mk = fn () => Operation::create([
            'public_id' => strtoupper(Str::random(12)), 'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'module_key' => 'sales', 'amount' => 100000, 'match' => 'exact',
            'status' => Operation::STATUS_APPROVED, 'approved_by_id' => $accountant->id, 'operation_date' => now(),
        ]);
        $op1 = $mk();
        $op2 = $mk();
        // Final-approve both → one ready batch (day × sales) holding [op1, op2].
        $this->asHead()->postJson("/api/v1/operations/{$op1->id}/final-approve")->assertOk();
        $this->asHead()->postJson("/api/v1/operations/{$op2->id}/final-approve")->assertOk();
        $this->assertSame(2, ErpBatch::where('status', 'ready')->first()->operation_count);

        // Export just op1 (post-to-erp single op) → the whole batch exports.
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/operations/{$op1->id}/post-to-erp")->assertOk();

        $this->assertTrue((bool) $op1->fresh()->erp_posted);
        $this->assertTrue((bool) $op2->fresh()->erp_posted); // not stranded
        $this->assertSame('exported', ErpBatch::first()->status);
    }

    // ── T10.5/10.6 lifecycle: ready → exported → failed → retry ──────────────────

    public function test_final_approve_seeds_a_ready_batch(): void
    {
        // An approved op final-approved through the pipeline seeds a ready batch.
        $accountant = $this->user($this->company->id, 'acc@erp.test', 'accountant');
        $op = Operation::create([
            'public_id' => strtoupper(Str::random(12)), 'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'module_key' => 'sales', 'amount' => 100000, 'match' => 'exact',
            'status' => Operation::STATUS_APPROVED, 'approved_by_id' => $accountant->id, 'approved_at' => now(),
            'operation_date' => now(),
        ]);

        $this->asHead()->postJson("/api/v1/operations/{$op->id}/final-approve")->assertOk();

        $batch = ErpBatch::where('module_key', 'sales')->where('status', 'ready')->first();
        $this->assertNotNull($batch);
        $this->assertSame(1, $batch->operation_count);
        $this->assertFalse((bool) $op->fresh()->erp_posted); // ready, not yet exported
    }

    public function test_failed_export_then_retry_succeeds(): void
    {
        $this->finalOp('sales', now(), 100000);

        config()->set('asab.erp.force_fail', true);
        $create = $this->asHead()->postJson('/api/v1/erp/batches', [])->assertStatus(201)->json();
        $batchId = $create['batchId'];
        $this->assertSame('failed', $create['status']);
        $this->assertSame(0, Operation::where('erp_posted', true)->count());

        config()->set('asab.erp.force_fail', false);
        $this->asHead()->postJson("/api/v1/erp/batches/{$batchId}/retry")
            ->assertOk()->assertJsonPath('status', 'exported');
        $this->assertSame(1, Operation::where('erp_posted', true)->count());

        // Retry on a non-failed batch → 409.
        $this->asHead()->postJson("/api/v1/erp/batches/{$batchId}/retry")
            ->assertStatus(409)->assertJsonPath('error.code', 'BATCH_NOT_FAILED');
    }

    // ── T10.8 downloads + role hardening ────────────────────────────────────────

    public function test_xlsx_download_is_a_spreadsheet_and_role_guarded(): void
    {
        $this->finalOp('sales', now());
        $batchId = $this->asHead()->postJson('/api/v1/erp/batches', [])->json('batchId');

        $this->asHead()->get("/api/v1/erp/batches/{$batchId}/download.xlsx")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // A non-head/admin role cannot read batch status/downloads.
        $accountant = $this->user($this->company->id, 'acc2@erp.test', 'accountant');
        $this->actingAs($accountant, 'sanctum')->getJson("/api/v1/erp/batches/{$batchId}/status")->assertStatus(403);
    }

    // ── T10.7 admin ERP screen ──────────────────────────────────────────────────

    public function test_admin_erp_summary_log_and_bulk_export(): void
    {
        // One ready batch (seed via final-approve), one approved op (awaitingHead).
        $accountant = $this->user($this->company->id, 'acc3@erp.test', 'accountant');
        $approved = Operation::create([
            'public_id' => strtoupper(Str::random(12)), 'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'module_key' => 'sales', 'amount' => 100000, 'match' => 'exact',
            'status' => Operation::STATUS_APPROVED, 'approved_by_id' => $accountant->id, 'operation_date' => now(),
        ]);
        $this->asHead()->postJson("/api/v1/operations/{$approved->id}/final-approve")->assertOk();

        $summary = $this->asAdmin()->getJson('/api/v1/admin/erp/summary')->assertOk()->json();
        $this->assertSame(1, $summary['kpis']['ready']);
        $this->assertSame(0, $summary['kpis']['awaitingHead']);
        $this->assertTrue($summary['connection']['ok']);

        $this->asAdmin()->getJson('/api/v1/admin/erp/batches?status=ready')->assertOk()
            ->assertJsonPath('meta.total', 1);

        $export = $this->asAdmin()->postJson('/api/v1/admin/erp/export', ['allReady' => true])->assertOk()->json();
        $this->assertSame(1, $export['count']);
        $this->assertSame('exported', ErpBatch::first()->status);

        // Accountant cannot reach the admin screen.
        $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/admin/erp/summary')->assertStatus(403);
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────────

    public function test_tenant_isolation_on_batches(): void
    {
        // Company B batch.
        $companyB = AsabCompany::create(['name' => 'B', 'plan' => 'Professional', 'status' => 'active']);
        $brandB = AsabBrand::create(['company_id' => $companyB->id, 'name' => 'بب', 'sub_status' => 'active', 'status' => 'active']);
        $branchB = Branch::factory()->create(['asab_brand_id' => $brandB->id, 'asab_company_id' => $companyB->id]);
        $headB = $this->user($companyB->id, 'headb@erp.test', 'head');
        $this->finalOp('sales', now(), 100000, $companyB->id, $branchB->id);
        $bBatchId = $this->actingAs($headB, 'sanctum')->postJson('/api/v1/erp/batches', [])->json('batchId');

        // Company A head cannot see company B's batch.
        $this->asHead()->getJson("/api/v1/erp/batches/{$bBatchId}/status")->assertStatus(404);
        $this->asHead()->getJson('/api/v1/head/erp/batches')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
