<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T03 — the approval pipeline (SRS §5): lifecycle transitions, the fixed
 * rejection-reason list, «طلب توضيح», locked-record immutability, origin
 * stamping, assigned-branch scoping and public_id allocation.
 */
class OperationsPipelineTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brandA;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $branchManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Pipeline Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند أ', 'sub_status' => 'active', 'status' => 'active']);
        $brandB = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند ب', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brandA->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $brandB->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->user('acc@asab.test', 'accountant', 'brand', ['brand_ids' => [$this->brandA->id]]);
        $this->head = $this->user('head@asab.test', 'head', 'all');
        $this->branchManager = $this->user('branch@asab.test', 'branch', 'branch', ['branch_ids' => [$this->branchA->id]]);
    }

    private function user(string $email, string $roleKey, string $scope, array $assignment = []): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id, 'name' => $email, 'email' => $email,
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(array_merge(['user_id' => $user->id, 'role_key' => $roleKey, 'scope' => $scope], $assignment));

        return $user;
    }

    private function op(array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'sales',
            'amount' => 150000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'submitted_by_id' => $this->branchManager->id,
            'submitted_at' => now(),
            'operation_date' => now(),
        ], $attrs));
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function test_lifecycle_pending_to_approved_to_final_approved(): void
    {
        $op = $this->op();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', Operation::STATUS_APPROVED)
            ->assertJsonPath('statusLabelAr', 'تمت الموافقة')
            ->assertJsonPath('stage.key', 'approved');

        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/final-approve")
            ->assertOk()
            ->assertJsonPath('status', Operation::STATUS_FINAL)
            ->assertJsonPath('statusLabelAr', 'معتمد نهائياً')
            ->assertJsonPath('stage.step', 4);

        $this->assertNotNull($op->fresh()->final_approved_at);

        $trail = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}/audit-trail")->assertOk()->json('data');
        $this->assertSame(['approved', 'final'], array_column($trail, 'stageId'));
        $this->assertTrue($trail[1]['isTerminal']);
    }

    public function test_state_machine_rejects_out_of_order_transitions(): void
    {
        $pending = $this->op();
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$pending->id}/final-approve")
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_NOT_APPROVED');

        $approved = $this->op(['status' => Operation::STATUS_APPROVED]);
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$approved->id}/approve")
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_NOT_PENDING');

        $final = $this->op(['status' => Operation::STATUS_FINAL]);
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$final->id}/final-approve")
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_NOT_APPROVED');
    }

    public function test_final_approved_operation_is_locked_against_rejection(): void
    {
        $final = $this->op(['status' => Operation::STATUS_FINAL]);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$final->id}/reject", ['reason' => 'incomplete_data'])
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');
    }

    public function test_conditional_final_approve_requires_a_note(): void
    {
        $op = $this->op(['status' => Operation::STATUS_APPROVED]);

        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/final-approve", ['isConditional' => true])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/final-approve", [
                'isConditional' => true,
                'conditionalNote' => 'يلزم إرفاق كشف البنك',
                'conditions' => [['text' => 'إرفاق الكشف', 'dueAt' => now()->addDay()->toIso8601String()]],
            ])->assertOk()->assertJsonPath('isConditional', true);

        $step = ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'final')->first();
        $this->assertTrue($step->meta['isConditional']);
        $this->assertCount(1, $step->meta['conditions']);
    }

    // ── §5.4 rejection reasons ───────────────────────────────────────────────

    public function test_reject_stores_the_canonical_label_and_notifies_the_submitter(): void
    {
        $op = $this->op();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/reject", [
                'reason' => 'missing_invoice',
                'details' => 'الفاتورة غير واضحة',
            ])
            ->assertOk()
            ->assertJsonPath('status', Operation::STATUS_REJECTED)
            ->assertJsonPath('rejectReason', 'فاتورة مفقودة أو غير واضحة')
            ->assertJsonPath('rejectReasonKey', 'missing_invoice')
            ->assertJsonPath('stage.step', -1);

        $step = ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'rejected')->first();
        $this->assertSame('missing_invoice', $step->meta['reasonKey']);
        $this->assertSame('الفاتورة غير واضحة', $step->meta['details']);

        $this->assertDatabaseHas('asab_notifications', [
            'user_id' => $this->branchManager->id,
            'type' => 'operation.rejected',
        ]);
    }

    public function test_reject_refuses_a_reason_outside_the_module_list(): void
    {
        $expense = $this->op(['module_key' => 'expenses', 'public_id' => OperationSequence::next('EXP')]);

        // Sales-only reason on an expenses operation.
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$expense->id}/reject", ['reason' => 'missing_pos_report'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REJECT_REASON');

        $sales = $this->op();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$sales->id}/reject", ['reason' => 'missing_pos_report'])
            ->assertOk()->assertJsonPath('rejectReason', 'تقرير POS مفقود');
    }

    public function test_reject_still_accepts_the_legacy_arabic_label(): void
    {
        $op = $this->op();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/reject", ['reason' => 'تناقض في المبالغ'])
            ->assertOk()->assertJsonPath('rejectReasonKey', 'amount_mismatch');
    }

    public function test_reject_requires_a_reason(): void
    {
        $op = $this->op();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/reject", [])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_rejection_reason_lookup_adds_the_sales_specific_entries(): void
    {
        $generic = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/lookups/rejection-reasons')->assertOk()->json('data');
        $sales = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/lookups/rejection-reasons?moduleKey=sales')->assertOk()->json('data');

        $this->assertCount(7, $generic);
        $this->assertCount(9, $sales);
        $this->assertContains('كشف البنك غير مرفق', array_column($sales, 'labelAr'));
    }

    public function test_operation_enum_catalog_exposes_every_map(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/lookups/operation-enums')
            ->assertOk()
            ->assertJsonPath('rollup.0.key', 'empty')
            ->assertJsonPath('rollup.0.labelAr', 'لا بيانات')
            ->assertJsonPath('origin.1.labelAr', 'سير المشتريات');
    }

    // ── §5.2c non-terminal clarification ─────────────────────────────────────

    public function test_request_clarification_does_not_move_the_operation(): void
    {
        $op = $this->op();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/request-clarification", ['message' => 'أرفق تقرير POS'])
            ->assertOk()
            ->assertJsonPath('status', Operation::STATUS_PENDING);

        $this->assertSame(Operation::STATUS_PENDING, $op->fresh()->status);
        $this->assertDatabaseHas('asab_approval_steps', [
            'operation_id' => $op->id,
            'stage_id' => 'review',
            'action' => 'طلب توضيح: أرفق تقرير POS',
        ]);
        $this->assertSame(1, AsabNotification::where('user_id', $this->branchManager->id)
            ->where('type', 'operation.clarification_requested')->count());
    }

    public function test_request_clarification_is_refused_on_a_locked_operation(): void
    {
        $final = $this->op(['status' => Operation::STATUS_FINAL]);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$final->id}/request-clarification", ['message' => 'استفسار'])
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');
    }

    // ── Correction ───────────────────────────────────────────────────────────

    public function test_correction_creates_a_linked_pending_operation(): void
    {
        $original = $this->op(['status' => Operation::STATUS_FINAL]);

        $body = $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$original->id}/correction", ['reason' => 'خطأ في المبلغ', 'amount' => 90000])
            ->assertCreated()->json();

        $correction = Operation::find($body['correctionOperationId']);
        $this->assertSame($original->id, $correction->corrective_ref_id);
        $this->assertTrue((bool) $correction->is_correction);
        $this->assertSame('system', $correction->origin);
        $this->assertSame('review', $correction->match);
        $this->assertSame(Operation::STATUS_PENDING, $correction->status);
        $this->assertSame(90000, $correction->amount);
    }

    // ── Bulk approve ─────────────────────────────────────────────────────────

    public function test_bulk_approve_only_touches_pending_ops_inside_the_callers_scope(): void
    {
        $pending = $this->op();
        $alreadyApproved = $this->op(['status' => Operation::STATUS_APPROVED]);
        $otherBranch = $this->op(['branch_id' => $this->branchB->id]);

        $body = $this->actingAs($this->accountant, 'sanctum')
            ->postJson('/api/v1/operations/bulk-approve', [
                'operationIds' => [$pending->id, $alreadyApproved->id, $otherBranch->id, 'missing-id'],
            ])->assertOk()->json();

        $this->assertSame([$pending->public_id], $body['approved']);
        $this->assertSame(['OP_NOT_PENDING'], array_column($body['failed'], 'code'));
        // Out-of-scope and unknown ids never reach the service.
        $this->assertSame(Operation::STATUS_PENDING, $otherBranch->fresh()->status);
    }

    // ── Zero-trust ───────────────────────────────────────────────────────────

    public function test_branch_scoped_accountant_cannot_see_or_touch_another_brands_operation(): void
    {
        $foreign = $this->op(['branch_id' => $this->branchB->id]);

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/operations/{$foreign->id}")->assertStatus(404);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$foreign->id}/approve")->assertStatus(404);

        $ids = array_column($this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/operations')->assertOk()->json('data'), 'id');
        $this->assertNotContains($foreign->id, $ids);

        // The head (scope=all) sees it.
        $this->actingAs($this->head, 'sanctum')->getJson("/api/v1/operations/{$foreign->id}")->assertOk();
    }

    public function test_pipeline_overview_counts_only_the_assigned_branches(): void
    {
        $this->op();
        $this->op(['branch_id' => $this->branchB->id]);

        $scoped = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/pipeline/overview')->assertOk()->json('stages');
        $companyWide = $this->actingAs($this->head, 'sanctum')
            ->getJson('/api/v1/pipeline/overview')->assertOk()->json('stages');

        $this->assertSame(1, $scoped[0]['count']);
        $this->assertSame(2, $companyWide[0]['count']);
    }

    public function test_branch_role_cannot_approve_and_accountant_cannot_final_approve(): void
    {
        $op = $this->op();

        $this->actingAs($this->branchManager, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")->assertStatus(403);

        $approved = $this->op(['status' => Operation::STATUS_APPROVED]);
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$approved->id}/final-approve")->assertStatus(403);
    }

    // ── §5.2b origin + filters ───────────────────────────────────────────────

    public function test_index_filters_by_origin_and_date_range(): void
    {
        $this->op(['origin' => 'mobile']);
        $procurement = $this->op(['origin' => 'procurement', 'module_key' => 'purchases', 'public_id' => OperationSequence::next('PUR')]);
        $old = $this->op(['operation_date' => now()->subDays(10)]);

        $rows = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/operations?origin=procurement')->assertOk()->json('data');
        $this->assertSame([$procurement->id], array_column($rows, 'id'));
        $this->assertSame('سير المشتريات', $rows[0]['originLabelAr']);

        $rows = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/operations?dateFrom='.now()->subDay()->toDateString())->assertOk()->json('data');
        $this->assertNotContains($old->id, array_column($rows, 'id'));
    }

    public function test_procurement_orders_are_stamped_with_the_procurement_origin(): void
    {
        $procurementUser = $this->user('proc@asab.test', 'procurement', 'all');

        $body = $this->actingAs($procurementUser, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/orders', [
                'supplierId' => 'sup-1',
                'branchId' => $this->branchA->id,
                'items' => [['itemId' => 'i1', 'qty' => 2, 'unitPriceHalalas' => 1000]],
            ])->assertCreated()->json();

        $this->assertSame('procurement', Operation::find($body['id'])->origin);
    }

    // ── NFR-10 immutability leak (procurement edit path) ─────────────────────

    public function test_procurement_cannot_reopen_a_final_approved_order(): void
    {
        $procurementUser = $this->user('proc2@asab.test', 'procurement', 'all');
        $order = $this->op([
            'module_key' => 'purchases',
            'public_id' => OperationSequence::next('PUR'),
            'status' => Operation::STATUS_FINAL,
            'payload' => ['supplierId' => 'sup-1', 'items' => []],
        ]);

        $this->actingAs($procurementUser, 'sanctum')
            ->patchJson("/api/v1/company/me/procurement/orders/{$order->id}", ['status' => Operation::STATUS_PENDING])
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');

        $this->assertSame(Operation::STATUS_FINAL, $order->fresh()->status);

        $this->actingAs($procurementUser, 'sanctum')
            ->deleteJson("/api/v1/company/me/procurement/orders/{$order->id}")
            ->assertStatus(409);
    }

    public function test_procurement_status_change_runs_through_the_pipeline(): void
    {
        $procurementUser = $this->user('proc3@asab.test', 'procurement', 'all');
        $order = $this->op([
            'module_key' => 'purchases',
            'public_id' => OperationSequence::next('PUR'),
            'payload' => ['supplierId' => 'sup-1', 'items' => []],
        ]);

        // final-approved is head-only: not reachable from the edit endpoint.
        $this->actingAs($procurementUser, 'sanctum')
            ->patchJson("/api/v1/company/me/procurement/orders/{$order->id}", ['status' => Operation::STATUS_FINAL])
            ->assertStatus(422)->assertJsonPath('error.code', 'OP_STATUS_TRANSITION_FORBIDDEN');

        $this->actingAs($procurementUser, 'sanctum')
            ->patchJson("/api/v1/company/me/procurement/orders/{$order->id}", ['status' => Operation::STATUS_APPROVED])
            ->assertOk();

        $order->refresh();
        $this->assertSame(Operation::STATUS_APPROVED, $order->status);
        $this->assertNotNull($order->approved_at);
        $this->assertDatabaseHas('asab_approval_steps', ['operation_id' => $order->id, 'stage_id' => 'approved']);
    }

    // ── public_id allocation ─────────────────────────────────────────────────

    public function test_public_id_survives_a_soft_deleted_high_water_mark(): void
    {
        $this->op(['public_id' => 'OPS-0001']);
        $this->op(['public_id' => 'OPS-0002'])->delete();

        $this->assertSame('OPS-0003', OperationSequence::next('OPS'));

        // …and a fresh create does not collide with the trashed row.
        $this->op(['public_id' => OperationSequence::next('OPS')]);
        $this->assertDatabaseHas('asab_operations', ['public_id' => 'OPS-0003']);
    }
}
