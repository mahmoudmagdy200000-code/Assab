<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * BRC-05 — every OperationService status transition re-validates the operation
 * under a row lock. SQLite cannot interleave two connections, so a concurrent
 * writer is simulated deterministically:
 *   - a STALE instance (loaded before another actor moved the row), or
 *   - a one-shot DB::listen hook that moves the row right after the first
 *     SELECT on asab_operations (i.e. between a bulk loop's find() and the
 *     transition's own lock).
 * The real multi-connection proof is the MariaDB test (RG-01), NOT_RUN on SQLite.
 */
class OperationTransitionLockTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $manager;

    private OperationService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Lock Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->user('acc@lock.test', 'accountant', 'brand', ['brand_ids' => [$brand->id]]);
        $this->head = $this->user('head@lock.test', 'head', 'all');
        $this->manager = $this->user('mgr@lock.test', 'branch', 'branch', ['branch_ids' => [$this->branch->id]]);
        $this->svc = app(OperationService::class);
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

    private function op(string $status = Operation::STATUS_PENDING, array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'sales',
            'amount' => 150000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => $status,
            'submitted_by_id' => $this->manager->id,
            'submitted_at' => now(),
            'operation_date' => now(),
        ], $attrs));
    }

    /** An approved operation exactly as the accountant leaves it. */
    private function approvedOp(): Operation
    {
        return $this->op(Operation::STATUS_APPROVED, [
            'approved_by_id' => $this->accountant->id,
            'approved_at' => now(),
        ]);
    }

    private function steps(Operation $op): int
    {
        return ApprovalStep::query()->where('operation_id', $op->id)->count();
    }

    private function notifications(?string $type = null): int
    {
        return AsabNotification::query()->when($type, fn ($q) => $q->where('type', $type))->count();
    }

    private function assertRefused(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail("Expected {$code} but the transition succeeded");
        } catch (AsabException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }

    /** Move the row behind the caller's back, once, right after the first SELECT on operations. */
    private function interleave(callable $mutate): void
    {
        $armed = true;
        DB::listen(function ($query) use (&$armed, $mutate) {
            if ($armed && str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'asab_operations')) {
                $armed = false;
                $mutate();
            }
        });
    }

    private function forceStatus(Operation $op, string $status, array $extra = []): void
    {
        DB::table('asab_operations')->where('id', $op->id)->update(array_merge(['status' => $status], $extra));
    }

    // ── PRB-10: a stale request must never reopen a closed operation ─────────

    public function test_stale_return_for_review_cannot_reopen_a_final_approved_operation(): void
    {
        $stale = $this->approvedOp();
        $this->svc->finalApprove(Operation::find($stale->id), $this->head);
        $stepsAfterFinal = $this->steps($stale);
        $notesAfterFinal = $this->notifications();

        $this->assertRefused(fn () => $this->svc->returnForReview($stale, $this->head, 'late'), 'OP_NOT_APPROVED');

        $row = Operation::find($stale->id);
        $this->assertSame(Operation::STATUS_FINAL, $row->status);
        $this->assertSame($this->accountant->id, $row->approved_by_id, 'approval stamps are not cleared');
        $this->assertNotNull($row->approved_at);
        $this->assertNotNull($row->final_approved_at);
        $this->assertSame($stepsAfterFinal, $this->steps($stale), 'no audit step written');
        $this->assertSame($notesAfterFinal, $this->notifications(), 'no notification sent');
        $this->assertSame(0, $this->notifications('operation.returned_for_review'));
    }

    public function test_stale_reject_as_by_head_cannot_reopen_a_final_approved_operation(): void
    {
        $stale = $this->approvedOp();
        $this->svc->finalApprove(Operation::find($stale->id), $this->head);
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->rejectAs($stale, $this->head, 'incomplete_data'), 'OP_NOT_APPROVED');

        $this->assertSame(Operation::STATUS_FINAL, Operation::find($stale->id)->status);
        $this->assertSame($steps, $this->steps($stale));
        $this->assertSame(0, $this->notifications('operation.returned_for_review'));
    }

    public function test_stale_reject_cannot_reject_a_final_approved_operation(): void
    {
        $stale = $this->approvedOp();
        $this->svc->finalApprove(Operation::find($stale->id), $this->head);
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->reject($stale, $this->accountant, 'incomplete_data'), 'OP_ALREADY_FINAL');

        $row = Operation::find($stale->id);
        $this->assertSame(Operation::STATUS_FINAL, $row->status);
        $this->assertNull($row->rejected_by_id);
        $this->assertNull($row->reject_reason);
        $this->assertSame($steps, $this->steps($stale));
        $this->assertSame(0, $this->notifications('operation.rejected'));
    }

    public function test_stale_approve_cannot_approve_an_operation_that_was_rejected_meanwhile(): void
    {
        $stale = $this->op();
        $this->svc->reject(Operation::find($stale->id), $this->accountant, 'incomplete_data');
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->approve($stale, $this->accountant), 'OP_NOT_PENDING');

        $row = Operation::find($stale->id);
        $this->assertSame(Operation::STATUS_REJECTED, $row->status);
        $this->assertNull($row->approved_by_id);
        $this->assertSame($steps, $this->steps($stale));
        $this->assertSame(0, $this->notifications('operation.pending_final'));
        $this->assertSame(0, $this->notifications('operation.approved'));
    }

    public function test_stale_approve_cannot_approve_an_operation_that_is_already_final(): void
    {
        $stale = $this->op();
        $this->svc->approve(Operation::find($stale->id), $this->accountant);
        $this->svc->finalApprove(Operation::find($stale->id), $this->head);
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->approve($stale, $this->accountant), 'OP_NOT_PENDING');

        $this->assertSame(Operation::STATUS_FINAL, Operation::find($stale->id)->status);
        $this->assertSame($steps, $this->steps($stale));
    }

    public function test_stale_reject_as_routes_by_the_locked_state_not_the_stale_snapshot(): void
    {
        // The head's snapshot says "approved"; the accountant was sent it back
        // (now pending). A terminal rejection must NOT be substituted.
        $stale = $this->approvedOp();
        $this->svc->returnForReview(Operation::find($stale->id), $this->head, 'again');
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->rejectAs($stale, $this->head, 'incomplete_data'), 'OP_NOT_APPROVED');

        $row = Operation::find($stale->id);
        $this->assertSame(Operation::STATUS_PENDING, $row->status);
        $this->assertNull($row->rejected_by_id);
        $this->assertSame($steps, $this->steps($stale), 'no duplicate review step');
    }

    public function test_stale_reject_as_by_head_on_pending_snapshot_cannot_become_a_return_or_terminal_reject_of_an_approved_row(): void
    {
        // Snapshot pending, row meanwhile approved by the accountant: a fresh
        // head request would mean "return to accountant"; the stale one must
        // not turn into a terminal rejection of an approved operation.
        $stale = $this->op();
        $this->svc->approve(Operation::find($stale->id), $this->accountant);
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->rejectAs($stale, $this->head, 'incomplete_data'), 'OP_NOT_PENDING');

        $this->assertSame(Operation::STATUS_APPROVED, Operation::find($stale->id)->status);
        $this->assertSame($steps, $this->steps($stale));
    }

    // ── Repeated requests: one effect, one audit step, one notification ──────

    public function test_repeated_approve_has_a_single_effect(): void
    {
        $op = $this->op();
        $this->svc->approve($op, $this->accountant);
        $this->assertRefused(fn () => $this->svc->approve($op, $this->accountant), 'OP_NOT_PENDING');

        $this->assertSame(1, ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'approved')->count());
        $this->assertSame(1, $this->notifications('operation.pending_final'));
    }

    public function test_repeated_return_for_review_has_a_single_effect(): void
    {
        $op = $this->approvedOp();
        $this->svc->returnForReview($op, $this->head, 'x');
        $this->assertRefused(fn () => $this->svc->returnForReview($op, $this->head, 'x'), 'OP_NOT_APPROVED');

        $this->assertSame(1, ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'review')->count());
        $this->assertSame(1, $this->notifications('operation.returned_for_review'));
        $this->assertSame(Operation::STATUS_PENDING, Operation::find($op->id)->status);
    }

    public function test_repeated_reject_has_a_single_effect(): void
    {
        $op = $this->op();
        $this->svc->reject($op, $this->accountant, 'incomplete_data');
        $this->assertRefused(fn () => $this->svc->reject($op, $this->accountant, 'incomplete_data'), 'OP_ALREADY_FINAL');

        $this->assertSame(1, ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'rejected')->count());
        $this->assertSame(1, $this->notifications('operation.rejected'));
    }

    public function test_repeated_final_approve_has_a_single_effect(): void
    {
        $op = $this->approvedOp();
        $this->svc->finalApprove($op, $this->head);
        $this->assertRefused(fn () => $this->svc->finalApprove($op, $this->head), 'OP_NOT_APPROVED');

        $this->assertSame(1, ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'final')->count());
        $this->assertSame(1, $this->notifications('operation.final_approved'));
    }

    // ── Bulk: a row that moves between load and transition is refused ────────

    public function test_bulk_return_for_review_skips_an_operation_finalised_between_load_and_lock(): void
    {
        $op = $this->approvedOp();
        $this->interleave(fn () => $this->forceStatus($op, Operation::STATUS_FINAL, ['final_approved_by_id' => $this->head->id, 'final_approved_at' => now()]));

        $result = $this->svc->bulkReturnForReview([$op->id], $this->head, 'late');

        $this->assertSame([], $result['returned']);
        $this->assertSame([['id' => $op->id, 'code' => 'OP_NOT_APPROVED']], $result['failed']);
        $this->assertSame(Operation::STATUS_FINAL, Operation::find($op->id)->status);
        $this->assertSame(0, ApprovalStep::where('operation_id', $op->id)->count());
        $this->assertSame(0, $this->notifications('operation.returned_for_review'));
    }

    public function test_bulk_approve_skips_an_operation_rejected_between_load_and_lock(): void
    {
        $op = $this->op();
        $this->interleave(fn () => $this->forceStatus($op, Operation::STATUS_REJECTED, ['rejected_by_id' => $this->manager->id]));

        $result = $this->svc->bulkApprove([$op->id], $this->accountant);

        $this->assertSame([], $result['approved']);
        $this->assertSame([['id' => $op->id, 'code' => 'OP_NOT_PENDING']], $result['failed']);
        $this->assertSame(Operation::STATUS_REJECTED, Operation::find($op->id)->status);
        $this->assertNull(Operation::find($op->id)->approved_by_id);
        $this->assertSame(0, $this->notifications('operation.pending_final'));
    }

    public function test_bulk_final_approve_skips_an_operation_returned_between_load_and_lock(): void
    {
        $op = $this->approvedOp();
        $this->interleave(fn () => $this->forceStatus($op, Operation::STATUS_PENDING, ['approved_by_id' => null, 'approved_at' => null]));

        $result = $this->svc->bulkFinalApprove([$op->id], $this->head);

        $this->assertSame([], $result['finalApproved']);
        $this->assertSame([['id' => $op->id, 'code' => 'OP_NOT_APPROVED']], $result['failed']);
        $this->assertSame(Operation::STATUS_PENDING, Operation::find($op->id)->status);
        $this->assertNull(Operation::find($op->id)->final_approved_at);
    }

    // ── A final-approved operation never leaves final through any transition ─

    public function test_no_transition_moves_a_final_approved_operation_back(): void
    {
        $op = $this->approvedOp();
        $this->svc->finalApprove($op, $this->head);
        $before = $this->steps($op);

        foreach ([
            fn () => $this->svc->approve($op, $this->accountant),
            fn () => $this->svc->reject($op, $this->accountant, 'incomplete_data'),
            fn () => $this->svc->rejectAs($op, $this->head, 'incomplete_data'),
            fn () => $this->svc->returnForReview($op, $this->head),
        ] as $call) {
            try {
                $call();
                $this->fail('A transition touched a final-approved operation');
            } catch (AsabException $e) {
                $this->assertSame(409, $e->status);
            }
        }

        $this->assertSame([], $this->svc->bulkApprove([$op->id], $this->accountant)['approved']);
        $this->assertSame([], $this->svc->bulkReturnForReview([$op->id], $this->head)['returned']);
        $this->assertSame(Operation::STATUS_FINAL, Operation::find($op->id)->status);
        $this->assertSame($before, $this->steps($op));
    }

    // ── Regression: the legitimate lifecycle and its history are unchanged ───

    public function test_full_lifecycle_with_return_preserves_history_and_stamps(): void
    {
        $op = $this->op();
        $this->svc->approve(Operation::find($op->id), $this->accountant);
        $this->svc->returnForReview(Operation::find($op->id), $this->head, 'check totals');
        $this->svc->approve(Operation::find($op->id), $this->accountant);
        $final = $this->svc->finalApprove(Operation::find($op->id), $this->head);

        $this->assertSame(Operation::STATUS_FINAL, $final->status);
        $this->assertSame(
            ['approved', 'review', 'approved', 'final'],
            ApprovalStep::where('operation_id', $op->id)->orderBy('occurred_at')->orderBy('id')->pluck('stage_id')->all(),
        );
        $this->assertSame($this->head->id, $final->final_approved_by_id);
        $this->assertSame($this->accountant->id, $final->approved_by_id);
    }

    public function test_head_reject_of_an_approved_operation_still_returns_it_to_the_accountant(): void
    {
        $op = $this->approvedOp();

        $res = $this->svc->rejectAs(Operation::find($op->id), $this->head, 'incomplete_data', 'fix');

        $this->assertSame(Operation::STATUS_PENDING, $res->status);
        $this->assertNull($res->rejected_by_id);
        $this->assertSame(1, $this->notifications('operation.returned_for_review'));
    }

    public function test_accountant_reject_of_a_pending_operation_is_still_terminal_and_notifies_once(): void
    {
        $op = $this->op();

        $res = $this->svc->rejectAs(Operation::find($op->id), $this->accountant, 'incomplete_data', 'n');

        $this->assertSame(Operation::STATUS_REJECTED, $res->status);
        $this->assertSame($this->accountant->id, $res->rejected_by_id);
        $this->assertSame(1, $this->notifications('operation.rejected'));
    }

    public function test_unknown_rejection_reason_is_still_422_and_writes_nothing(): void
    {
        $op = $this->op();

        try {
            $this->svc->reject(Operation::find($op->id), $this->accountant, 'no_such_reason');
            $this->fail('expected INVALID_REJECT_REASON');
        } catch (AsabException $e) {
            $this->assertSame('INVALID_REJECT_REASON', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
        $this->assertSame(Operation::STATUS_PENDING, Operation::find($op->id)->status);
        $this->assertSame(0, $this->steps($op));
    }

    // ── BRC-05 extension: requestClarification() / document() ────────────────

    public function test_stale_clarification_cannot_write_evidence_on_a_final_approved_operation(): void
    {
        $stale = $this->approvedOp();
        $this->svc->finalApprove(Operation::find($stale->id), $this->head);
        $steps = $this->steps($stale);
        $notes = $this->notifications();

        $this->assertRefused(fn () => $this->svc->requestClarification($stale, $this->head, 'late question'), 'OP_ALREADY_FINAL');

        $this->assertSame(Operation::STATUS_FINAL, Operation::find($stale->id)->status);
        $this->assertSame($steps, $this->steps($stale), 'no audit step on a closed operation');
        $this->assertSame($notes, $this->notifications(), 'no notification');
        $this->assertSame(0, $this->notifications('operation.clarification_requested'));
    }

    public function test_stale_clarification_cannot_write_evidence_on_a_rejected_operation(): void
    {
        $stale = $this->op();
        $this->svc->reject(Operation::find($stale->id), $this->accountant, 'incomplete_data');
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->requestClarification($stale, $this->accountant, 'late question'), 'OP_ALREADY_FINAL');

        $this->assertSame(Operation::STATUS_REJECTED, Operation::find($stale->id)->status);
        $this->assertSame($steps, $this->steps($stale));
        $this->assertSame(0, $this->notifications('operation.clarification_requested'));
    }

    public function test_stale_document_cannot_change_payload_or_audit_of_a_final_approved_operation(): void
    {
        $stale = $this->approvedOp();
        $this->svc->finalApprove(Operation::find($stale->id), $this->head);
        $payloadBefore = Operation::find($stale->id)->payload;
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->document($stale, $this->accountant, 'late doc'), 'OP_ALREADY_FINAL');

        $this->assertSame($payloadBefore, Operation::find($stale->id)->payload, 'payload untouched');
        $this->assertSame($steps, $this->steps($stale), 'no audit step');
    }

    public function test_stale_document_cannot_change_payload_or_audit_of_a_rejected_operation(): void
    {
        $stale = $this->op(Operation::STATUS_PENDING, ['payload' => ['k' => 'v']]);
        $this->svc->reject(Operation::find($stale->id), $this->accountant, 'incomplete_data');
        $steps = $this->steps($stale);

        $this->assertRefused(fn () => $this->svc->document($stale, $this->accountant, 'late doc'), 'OP_ALREADY_FINAL');

        $this->assertSame(['k' => 'v'], Operation::find($stale->id)->payload);
        $this->assertSame($steps, $this->steps($stale));
    }

    public function test_stale_document_does_not_overwrite_a_newer_payload_edit(): void
    {
        $stale = $this->op(Operation::STATUS_PENDING, ['payload' => ['lines' => [['sku' => 'A']]]]);
        // Another writer (e.g. the line editor) saves a newer payload after $stale was loaded.
        DB::table('asab_operations')->where('id', $stale->id)->update([
            'payload' => json_encode(['lines' => [['sku' => 'A'], ['sku' => 'B']], 'editedBy' => 'accountant']),
        ]);

        $this->svc->document($stale, $this->accountant, 'ok');

        $payload = Operation::find($stale->id)->payload;
        $this->assertSame([['sku' => 'A'], ['sku' => 'B']], $payload['lines'], 'newer edit preserved (no lost update)');
        $this->assertSame('accountant', $payload['editedBy']);
        $this->assertSame($this->accountant->id, $payload['documentation']['documentedById']);
        $this->assertSame('ok', $payload['documentation']['note']);
    }

    public function test_clarification_on_an_open_operation_still_works_and_does_not_move_status(): void
    {
        foreach ([Operation::STATUS_PENDING, Operation::STATUS_APPROVED] as $status) {
            $op = $this->op($status, ['approved_by_id' => $status === Operation::STATUS_APPROVED ? $this->accountant->id : null]);

            $res = $this->svc->requestClarification(Operation::find($op->id), $this->accountant, 'please clarify');

            $this->assertSame($status, $res->status);
            $this->assertSame(1, ApprovalStep::where('operation_id', $op->id)->count());
        }
        $this->assertSame(2, $this->notifications('operation.clarification_requested'));
    }

    public function test_document_on_an_open_operation_still_works_preserves_payload_and_may_repeat(): void
    {
        $op = $this->op(Operation::STATUS_APPROVED, ['payload' => ['lines' => [['sku' => 'A']]], 'approved_by_id' => $this->accountant->id]);

        $this->svc->document(Operation::find($op->id), $this->accountant, 'first');
        $second = $this->svc->document(Operation::find($op->id), $this->accountant, 'second');

        $this->assertSame(Operation::STATUS_APPROVED, $second->status);
        $this->assertSame([['sku' => 'A']], $second->payload['lines']);
        $this->assertSame('second', $second->payload['documentation']['note'], 'repeat documentation refreshes the note');
        $this->assertSame(2, ApprovalStep::where('operation_id', $op->id)->count(), 'each legitimate documentation is audited');
    }
}
