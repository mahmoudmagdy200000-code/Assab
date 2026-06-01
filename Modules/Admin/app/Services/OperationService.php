<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

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

        return $fresh;
    }

    public function reject(Operation $op, AsabUser $actor, string $reason, ?string $notes = null): Operation
    {
        if (in_array($op->status, [Operation::STATUS_FINAL, Operation::STATUS_REJECTED], true)) {
            throw new AsabException('OP_ALREADY_FINAL', 'Operation can no longer be rejected', 'لا يمكن رفض العملية', 409, ['currentStatus' => $op->status]);
        }

        $prev = $op->status;
        $fresh = DB::transaction(function () use ($op, $actor, $reason, $notes) {
            $op->update([
                'status' => Operation::STATUS_REJECTED,
                'reject_reason' => $reason,
                'rejected_by_id' => $actor->id,
                'rejected_at' => now(),
            ]);
            $this->step($op, 'rejected', 'مرفوض — السبب: '.$reason, $actor, $notes);

            return $op->fresh();
        });

        $this->rt->operationStatusChanged($fresh, $prev, Operation::STATUS_REJECTED, $actor);
        if ($fresh->submitted_by_id) {
            $this->notifications->push(
                $fresh->submitted_by_id, 'operation.rejected',
                'عمليتك مرفوضة', $fresh->public_id.' — السبب: '.$reason,
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
        }

        return $fresh;
    }

    public function finalApprove(Operation $op, AsabUser $actor, bool $conditional = false, ?string $conditionalNote = null): Operation
    {
        $this->assertStatus($op, Operation::STATUS_APPROVED, 'OP_NOT_APPROVED');

        $fresh = DB::transaction(function () use ($op, $actor, $conditional, $conditionalNote) {
            $op->update([
                'status' => Operation::STATUS_FINAL,
                'final_approved_by_id' => $actor->id,
                'final_approved_at' => now(),
                'is_conditional' => $conditional,
                'conditional_note' => $conditionalNote,
            ]);
            $this->step($op, 'final', 'اعتمده رئيس الحسابات نهائياً — سجل مُغلق', $actor, $conditionalNote, [
                'isConditional' => $conditional,
            ]);

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

        return $fresh;
    }

    /**
     * Create a corrective operation linked to an original (BACKEND_API_SPEC.md §5.3).
     */
    public function correction(Operation $original, AsabUser $actor, string $reason, array $overrides = []): Operation
    {
        return DB::transaction(function () use ($original, $actor, $reason, $overrides) {
            $correction = Operation::create(array_merge([
                'public_id' => $this->nextCorrectionId($original),
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
        });
    }

    private function nextCorrectionId(Operation $original): string
    {
        $prefix = explode('-', $original->public_id)[0] ?? 'OPS';
        $n = Operation::where('public_id', 'like', "{$prefix}-%")->count() + 1;

        return $prefix.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
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
