<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\OperationEnums;

/**
 * The shared approval-pipeline state machine (BACKEND_API_SPEC.md §5).
 * pending → approved → final-approved (+ erp), or → rejected. Every transition
 * is atomic and writes an approval_steps audit row.
 */
class OperationService
{
    public function __construct(
        private readonly RealtimeBroadcaster $rt,
        private readonly NotificationService $notifications,
    ) {}

    public function approve(Operation $op, AsabUser $actor, ?string $note = null): Operation
    {
        $this->assertStatus($op, Operation::STATUS_PENDING);

        $fresh = DB::transaction(function () use ($op, $actor, $note) {
            $op->update([
                'status' => Operation::STATUS_APPROVED,
                'approved_by_id' => $actor->id,
                'approved_at' => now(),
                // The accountant's review timestamp — powers HEAD-1 throughput/
                // performance metrics (previously never written, so they read 0).
                'reviewed_by_id' => $op->reviewed_by_id ?: $actor->id,
                'reviewed_at' => $op->reviewed_at ?: now(),
            ]);
            $this->step($op, 'approved', 'راجعه المحاسب ووافق عليه — أُرسل لرئيس الحسابات', $actor, $note);

            return $op->fresh();
        });

        $this->rt->operationStatusChanged($fresh, Operation::STATUS_PENDING, Operation::STATUS_APPROVED, $actor);
        $pendingFinal = Operation::where('company_id', $fresh->company_id)->where('status', Operation::STATUS_APPROVED)->count();
        $this->rt->approvalPending($fresh->company_id, $actor->id, $pendingFinal);
        $this->notifications->pushToRole(
            $fresh->company_id, 'head', 'operation.pending_final',
            'عملية بانتظار الاعتماد النهائي', $fresh->public_id.' — '.$fresh->module_key,
            null, ['type' => 'operation', 'id' => $fresh->id],
        );
        // Reverse leg of branch → accountant → head: tell the branch manager who
        // uploaded it that their report cleared review (their upload/status chip
        // flips to «success»). Mirrors the reject path, which already notifies.
        if ($fresh->submitted_by_id) {
            $this->notifications->push(
                $fresh->submitted_by_id, 'operation.approved',
                'تم قبول تقريرك', $fresh->public_id.' — '.$fresh->module_key,
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
        }

        return $fresh;
    }

    /**
     * Reject back to the branch manager (SRS §5.4). `$reasonKey` is a key from
     * OperationEnums::rejectionReasons() for the operation's module; the
     * canonical Arabic label is what lands in `reject_reason` so existing
     * readers (head lists, exports) keep working, while the key is preserved in
     * the audit step's meta for analytics.
     */
    public function reject(Operation $op, AsabUser $actor, string $reasonKey, ?string $notes = null): Operation
    {
        $this->assertMutable($op, 'لا يمكن رفض العملية');

        $reason = OperationEnums::resolveRejectionReason($reasonKey, $op->module_key);
        if ($reason === null) {
            throw new AsabException(
                'INVALID_REJECT_REASON',
                'Unknown rejection reason for this module',
                'سبب الرفض غير معروف لهذا الموديول',
                422,
                ['allowed' => array_keys(OperationEnums::rejectionReasons($op->module_key))],
            );
        }

        $prev = $op->status;
        $label = $reason['labelAr'];
        $fresh = DB::transaction(function () use ($op, $actor, $reason, $label, $notes) {
            $op->update([
                'status' => Operation::STATUS_REJECTED,
                'reject_reason' => $label,
                'rejected_by_id' => $actor->id,
                'rejected_at' => now(),
                // A rejection is also a review — stamp it for the throughput metrics.
                'reviewed_by_id' => $op->reviewed_by_id ?: $actor->id,
                'reviewed_at' => $op->reviewed_at ?: now(),
            ]);
            $this->step($op, 'rejected', 'مرفوض — السبب: '.$label, $actor, $notes, [
                'reasonKey' => $reason['key'],
                'details' => $notes,
            ]);

            return $op->fresh();
        });

        $this->rt->operationStatusChanged($fresh, $prev, Operation::STATUS_REJECTED, $actor);
        if ($fresh->submitted_by_id) {
            $this->notifications->push(
                $fresh->submitted_by_id, 'operation.rejected',
                'عمليتك مرفوضة', $fresh->public_id.' — السبب: '.$label,
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
        }
        event(new \Modules\Admin\Events\OperationRejected($fresh, $actor, $label));

        return $fresh;
    }

    /**
     * @param  array<int, array{id?:string, text:string, dueAt?:string}>  $conditions
     */
    public function finalApprove(Operation $op, AsabUser $actor, bool $conditional = false, ?string $conditionalNote = null, array $conditions = []): Operation
    {
        $this->assertStatus($op, Operation::STATUS_APPROVED, 'OP_NOT_APPROVED');

        $fresh = DB::transaction(function () use ($op, $actor, $conditional, $conditionalNote, $conditions) {
            $op->update([
                'status' => Operation::STATUS_FINAL,
                'final_approved_by_id' => $actor->id,
                'final_approved_at' => now(),
                'is_conditional' => $conditional,
                'conditional_note' => $conditionalNote,
            ]);
            $this->step($op, 'final', 'اعتمده رئيس الحسابات نهائياً — سجل مُغلق', $actor, $conditionalNote, array_filter([
                'isConditional' => $conditional,
                'conditions' => $conditional ? array_values($conditions) : null,
            ], fn ($v) => $v !== null));

            return $op->fresh();
        });

        $this->rt->operationStatusChanged($fresh, Operation::STATUS_APPROVED, Operation::STATUS_FINAL, $actor);
        if ($fresh->submitted_by_id) {
            $this->notifications->push(
                $fresh->submitted_by_id, 'operation.final_approved',
                'تم اعتماد عمليتك نهائياً', $fresh->public_id.($conditional ? ' (اعتماد مشروط)' : ''),
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
        }
        event(new \Modules\Admin\Events\OperationFinalApproved($fresh, $actor));

        return $fresh;
    }

    /**
     * HEAD-2.1 «إرجاع للمراجعة» — the head sends an approved operation back to the
     * accountant queue instead of finalizing or rejecting it. approved → pending,
     * clearing the approval stamps and notifying the accountant who approved it.
     */
    public function returnForReview(Operation $op, AsabUser $actor, ?string $note = null): Operation
    {
        $this->assertStatus($op, Operation::STATUS_APPROVED, 'OP_NOT_APPROVED');

        $accountantId = $op->approved_by_id; // capture before clearing
        $fresh = DB::transaction(function () use ($op, $actor, $note) {
            $op->update([
                'status' => Operation::STATUS_PENDING,
                'approved_by_id' => null,
                'approved_at' => null,
            ]);
            $this->step($op, 'review', 'أعادها رئيس الحسابات للمراجعة'.($note ? ' — '.$note : ''), $actor, $note, ['returnedForReview' => true]);

            return $op->fresh();
        });

        $this->rt->operationStatusChanged($fresh, Operation::STATUS_APPROVED, Operation::STATUS_PENDING, $actor);
        if ($accountantId) {
            $this->notifications->push(
                $accountantId, 'operation.returned_for_review',
                'عملية أُعيدت للمراجعة', $fresh->public_id.($note ? ' — '.$note : ''),
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
        }

        return $fresh;
    }

    /**
     * «طلب توضيح» (SRS ACC-0.5 / ACC-1.4) — ask the submitter for more
     * information without moving the operation off its stage. Non-terminal:
     * the status is deliberately untouched.
     */
    public function requestClarification(Operation $op, AsabUser $actor, string $message): Operation
    {
        $this->assertMutable($op, 'لا يمكن طلب توضيح على عملية مُغلقة');

        $fresh = DB::transaction(function () use ($op, $actor, $message) {
            $this->step($op, 'review', 'طلب توضيح: '.$message, $actor, $message, ['clarification' => true]);

            return $op->fresh();
        });

        if ($fresh->submitted_by_id) {
            $this->notifications->push(
                $fresh->submitted_by_id, 'operation.clarification_requested',
                'طلب توضيح على عمليتك', $fresh->public_id.' — '.$message,
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
        }

        return $fresh;
    }

    /**
     * ACC-3.4 توثيق — the accountant stamps a purchase order as documented before
     * it moves to the head accountant. Non-terminal and idempotent: re-documenting
     * refreshes the timestamp and note. Status is unchanged; the payload carries
     * the flag and an audit step records it.
     */
    public function document(Operation $op, AsabUser $actor, ?string $note = null): Operation
    {
        $this->assertMutable($op, 'لا يمكن توثيق عملية مُغلقة');

        $fresh = DB::transaction(function () use ($op, $actor, $note) {
            $payload = $op->payload ?? [];
            $payload['documentation'] = [
                'documentedAt' => now()->toIso8601String(),
                'documentedById' => $actor->id,
                'documentedBy' => $actor->name,
                'note' => $note,
            ];
            $op->update(['payload' => $payload]);
            $this->step($op, 'review', 'وثّق المحاسب مستندات الطلب', $actor, $note, ['documented' => true]);

            return $op->fresh();
        });

        return $fresh;
    }

    /**
     * Create a corrective operation linked to an original (BACKEND_API_SPEC.md §5.3).
     */
    public function correction(Operation $original, AsabUser $actor, string $reason, array $overrides = []): Operation
    {
        $prefix = explode('-', $original->public_id)[0] ?: 'OPS';

        return OperationSequence::createWithPublicId($prefix, fn (string $publicId) => DB::transaction(function () use ($publicId, $original, $actor, $reason, $overrides) {
            $correction = Operation::create(array_merge([
                'public_id' => $publicId,
                'company_id' => $original->company_id,
                'branch_id' => $original->branch_id,
                'module_key' => $original->module_key,
                'source_module' => $original->source_module,
                'source_id' => $original->source_id,
                'payload' => $original->payload,
                'amount' => $original->amount,
                'match' => 'review',
                'origin' => 'system',
                'status' => Operation::STATUS_PENDING,
                'is_correction' => true,
                'corrective_ref_id' => $original->id,
                'submitted_by_id' => $actor->id,
                'submitted_at' => now(),
                'operation_date' => now(),
            ], $overrides));

            $this->step($correction, 'submit', 'عملية تعديل مرتبطة بـ '.$original->public_id.' — السبب: '.$reason, $actor, $reason);
            $this->step($original, 'submit', 'أُنشئت عملية تعديل: '.$correction->public_id, $actor);

            return $correction->fresh();
        }));
    }

    /**
     * @param  string[]  $ids
     * @return array{approved: string[], failed: array<int, array{id:string, code:string}>}
     */
    public function bulkApprove(array $ids, AsabUser $actor): array
    {
        $approved = [];
        $failed = [];
        foreach ($ids as $id) {
            $op = Operation::find($id) ?? Operation::where('public_id', $id)->first();
            if (! $op) {
                $failed[] = ['id' => $id, 'code' => 'NOT_FOUND'];

                continue;
            }
            try {
                $this->approve($op, $actor);
                $approved[] = $op->public_id;
            } catch (AsabException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            }
        }

        return ['approved' => $approved, 'failed' => $failed];
    }

    /**
     * HEAD-2.1/2.4 «اعتماد الكل» — the head's group final-approval. Each approved
     * op walks the same finalApprove transition (per-op transaction + م4 step +
     * notification); pending/rejected/final ids fail with OP_NOT_APPROVED.
     *
     * @param  string[]  $ids
     * @return array{finalApproved: string[], failed: array<int, array{id:string, code:string}>}
     */
    public function bulkFinalApprove(array $ids, AsabUser $actor, bool $conditional = false, ?string $conditionalNote = null): array
    {
        $finalApproved = [];
        $failed = [];
        foreach ($ids as $id) {
            $op = Operation::find($id) ?? Operation::where('public_id', $id)->first();
            if (! $op) {
                $failed[] = ['id' => $id, 'code' => 'NOT_FOUND'];

                continue;
            }
            try {
                $this->finalApprove($op, $actor, $conditional, $conditionalNote);
                $finalApproved[] = $op->public_id;
            } catch (AsabException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            } catch (\Throwable $e) {
                // A non-domain error on one op must not abort the whole batch.
                $failed[] = ['id' => $id, 'code' => 'INTERNAL_ERROR'];
            }
        }

        return ['finalApproved' => $finalApproved, 'failed' => $failed];
    }

    /**
     * HEAD-2.1 group «إرجاع للمراجعة» — return every approved op in the set.
     *
     * @param  string[]  $ids
     * @return array{returned: string[], failed: array<int, array{id:string, code:string}>}
     */
    public function bulkReturnForReview(array $ids, AsabUser $actor, ?string $note = null): array
    {
        $returned = [];
        $failed = [];
        foreach ($ids as $id) {
            $op = Operation::find($id) ?? Operation::where('public_id', $id)->first();
            if (! $op) {
                $failed[] = ['id' => $id, 'code' => 'NOT_FOUND'];

                continue;
            }
            try {
                $this->returnForReview($op, $actor, $note);
                $returned[] = $op->public_id;
            } catch (AsabException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'code' => 'INTERNAL_ERROR'];
            }
        }

        return ['returned' => $returned, 'failed' => $failed];
    }

    /**
     * NFR-10 — a `final-approved` record is closed («مُغلق»); a `rejected` one
     * is off-pipeline. Neither may be mutated. Every writer that touches an
     * Operation outside the transitions above must call this first.
     */
    public function assertMutable(Operation $op, string $messageAr = 'لا يمكن تعديل عملية مُغلقة'): void
    {
        if (in_array($op->status, [Operation::STATUS_FINAL, Operation::STATUS_REJECTED], true)) {
            throw new AsabException(
                'OP_ALREADY_FINAL',
                'Operation is locked and can no longer be modified',
                $messageAr,
                409,
                ['currentStatus' => $op->status],
            );
        }
    }

    private function assertStatus(Operation $op, string $expected, string $code = 'OP_NOT_PENDING'): void
    {
        if ($op->status !== $expected) {
            throw new AsabException(
                $code,
                "Operation is not in {$expected} status",
                'حالة العملية غير صحيحة لهذا الإجراء',
                409,
                ['currentStatus' => $op->status, 'requiredStatus' => $expected],
            );
        }
    }

    /** Public audit-step writer for callers that own their own mutation (e.g. the line editor). */
    public function recordStep(Operation $op, string $stage, string $action, AsabUser $actor, ?string $note = null, array $meta = []): void
    {
        $this->step($op, $stage, $action, $actor, $note, $meta);
    }

    private function step(Operation $op, string $stage, string $action, AsabUser $actor, ?string $note = null, array $meta = []): void
    {
        ApprovalStep::create([
            'operation_id' => $op->id,
            'stage_id' => $stage,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'actor_label' => $actor->name,
            'note' => $note,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }
}
