<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\OperationEnums;
use Psr\Log\LoggerInterface;

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
        private readonly ShiftCloseService $shiftClose,
        private readonly LoggerInterface $log,
    ) {}

    public function approve(Operation $op, AsabUser $actor, ?string $note = null): Operation
    {
        $fresh = DB::transaction(function () use ($op, $actor, $note) {
            // BRC-05: lock the row, THEN validate and write on the locked copy
            // (same pattern as finalApprove) so a stale request cannot act on a
            // state another actor already moved.
            $op = $this->lockOperation($op);
            $this->assertDecisionAuthorized($op);
            $this->assertStatus($op, Operation::STATUS_PENDING);

            app(\Modules\Shift\Services\ShiftReportReviewService::class)->authorizeReview($op, $actor, 'accountant');
            $snapshot = app(\Modules\Shift\Services\ShiftReportReviewService::class)->approvalSnapshot($op);
            if ($snapshot !== null) {
                $payload = $op->payload ?? [];
                $payload['pkg6Review'] = array_merge($payload['pkg6Review'] ?? [], [
                    'phase' => 'head_review', 'approved_snapshot' => $snapshot,
                ]);
                $op->update(['payload' => $payload]);
            }

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

        // A bulk transition is nested in an outer transaction. Defer every
        // externally visible projection until that outermost transaction commits.
        DB::afterCommit(function () use ($fresh, $actor): void {
            $this->rt->operationStatusChanged($fresh, Operation::STATUS_PENDING, Operation::STATUS_APPROVED, $actor);
            $pendingFinal = Operation::where('company_id', $fresh->company_id)->where('status', Operation::STATUS_APPROVED)->count();
            $this->rt->approvalPending($fresh->company_id, $actor->id, $pendingFinal);
            $this->notifications->pushToRole(
                $fresh->company_id, 'head', 'operation.pending_final',
                'عملية بانتظار الاعتماد النهائي', $fresh->public_id.' — '.$fresh->module_key,
                null, ['type' => 'operation', 'id' => $fresh->id],
            );
            if ($fresh->submitted_by_id) {
                $this->notifications->push(
                    $fresh->submitted_by_id, 'operation.approved',
                    'تم قبول تقريرك', $fresh->public_id.' — '.$fresh->module_key,
                    null, ['type' => 'operation', 'id' => $fresh->id],
                );
            }
            event(new \Modules\Admin\Events\OperationApproved($fresh, $actor));
        });

        return $fresh;
    }

    /**
     * The rejection an actor's ROLE means (meeting 2026-08-14). The head of
     * accounts rejecting a record the accountant already approved does not send
     * it back to the branch — it goes back to the ACCOUNTANT to review again.
     * Every other case is the ordinary terminal rejection.
     *
     * BRC-05: the role-dependent route is chosen from the caller's snapshot, but
     * the row is locked and the snapshot re-verified before anything is written;
     * if the operation moved in between, the request is refused (409) instead of
     * being silently re-interpreted as a different transition.
     */
    public function rejectAs(Operation $op, AsabUser $actor, string $reasonKey, ?string $notes = null): Operation
    {
        $snapshot = $op->status;
        if ($snapshot === Operation::STATUS_APPROVED && $actor->hasAnyAsabRole(['head'])) {
            $reason = OperationEnums::resolveRejectionReason($reasonKey, $op->module_key);

            return $this->returnForReview($op, $actor, trim(($reason['labelAr'] ?? $reasonKey).($notes ? ' — '.$notes : '')));
        }

        return $this->reject($op, $actor, $reasonKey, $notes, $actor->hasAnyAsabRole(['head']) ? $snapshot : null);
    }

    /**
     * Reject back to the branch manager (SRS §5.4). `$reasonKey` is a key from
     * OperationEnums::rejectionReasons() for the operation's module; the
     * canonical Arabic label is what lands in `reject_reason` so existing
     * readers (head lists, exports) keep working, while the key is preserved in
     * the audit step's meta for analytics.
     *
     * @param  string|null  $expectedStatus  set only by rejectAs for a head whose snapshot was not
     *                                       "approved": the locked row must still be in that status.
     */
    public function reject(Operation $op, AsabUser $actor, string $reasonKey, ?string $notes = null, ?string $expectedStatus = null): Operation
    {
        [$fresh, $prev, $label] = DB::transaction(function () use ($op, $actor, $reasonKey, $notes, $expectedStatus) {
            $op = $this->lockOperation($op);
            $this->assertDecisionAuthorized($op);
            if ($expectedStatus !== null) {
                // The head's request was routed as a terminal rejection because the
                // snapshot was not "approved". If the accountant approved it meanwhile,
                // the same request would now mean "return to accountant" — refuse.
                $this->assertStatus($op, $expectedStatus, $expectedStatus === Operation::STATUS_APPROVED ? 'OP_NOT_APPROVED' : 'OP_NOT_PENDING');
            }
            $this->assertSalesReturnAllowed($op);
            $this->assertMutable($op, 'لا يمكن رفض العملية');
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->assertMutableOperation($op);

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
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->returnToManager($op, $actor, $notes ?: $label);
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

            return [$op->fresh(), $prev, $label];
        });

        DB::afterCommit(function () use ($fresh, $prev, $actor, $label): void {
            $this->rt->operationStatusChanged($fresh, $prev, Operation::STATUS_REJECTED, $actor);
            if ($fresh->submitted_by_id) {
                $this->notifications->push(
                    $fresh->submitted_by_id, 'operation.rejected',
                    'عمليتك مرفوضة', $fresh->public_id.' — السبب: '.$label,
                    null, ['type' => 'operation', 'id' => $fresh->id],
                );
            }
            event(new \Modules\Admin\Events\OperationRejected($fresh, $actor, $label));
        });

        return $fresh;
    }

    /**
     * @param  array<int, array{id?:string, text:string, dueAt?:string}>  $conditions
     */
    public function finalApprove(Operation $op, AsabUser $actor, bool $conditional = false, ?string $conditionalNote = null, array $conditions = []): Operation
    {
        $fresh = DB::transaction(function () use ($op, $actor, $conditional, $conditionalNote, $conditions) {
            $op = $this->lockOperation($op);
            $this->assertDecisionAuthorized($op);
            $this->assertStatus($op, Operation::STATUS_APPROVED, 'OP_NOT_APPROVED');
            if ($op->module_key === 'sales' && app(OperationWorkflowClassifier::class)->classify($op) === OperationWorkflowClassifier::SAL
                && ($op->payload['pkg6Review']['phase'] ?? null) !== 'head_review') {
                throw new AsabException('REPORT_NOT_IN_HEAD_REVIEW', 'Day report is not in head review', 'التقرير ليس في مرحلة مراجعة رئيس الحسابات', 409);
            }
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->authorizeReview($op, $actor, 'head');
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->approvalSnapshot($op, final: true);
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

            // A shifts operation owns a required employee allocation and native
            // shift close. Keep both in this transaction with final state/audit;
            // a failed ledger write must not leave a final-approved operation.
            if ($op->module_key === 'shifts') {
                $this->shiftClose->onFinalApproved($op, $actor);
            }

            return $op->fresh();
        });

        DB::afterCommit(function () use ($fresh, $actor, $conditional): void {
            try {
                // Required shift close/allocation ran in the state transaction.
                // These remaining bridges are post-commit projections.
                $this->rt->operationStatusChanged($fresh, Operation::STATUS_APPROVED, Operation::STATUS_FINAL, $actor);
                if ($fresh->submitted_by_id) {
                    $this->notifications->push(
                        $fresh->submitted_by_id, 'operation.final_approved',
                        'تم اعتماد عمليتك نهائياً', $fresh->public_id.($conditional ? ' (اعتماد مشروط)' : ''),
                        null, ['type' => 'operation', 'id' => $fresh->id],
                    );
                }
                event(new \Modules\Admin\Events\OperationFinalApproved($fresh, $actor));
            } catch (\Throwable $exception) {
                $this->log->error('Final approval projection failed after commit', [
                    'operation_id' => $fresh->id,
                    'module_key' => $fresh->module_key,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        return $fresh;
    }

    /**
     * HEAD-2.1 «إرجاع للمراجعة» — the head sends an approved operation back to the
     * accountant queue instead of finalizing or rejecting it. approved → pending,
     * clearing the approval stamps and notifying the accountant who approved it.
     */
    public function returnForReview(Operation $op, AsabUser $actor, ?string $note = null): Operation
    {
        [$fresh, $accountantId] = DB::transaction(function () use ($op, $actor, $note) {
            // BRC-05: a request prepared against an "approved" snapshot must not
            // move a row that has since become final-approved (or returned) back to
            // pending — re-check on the locked row.
            $op = $this->lockOperation($op);
            $this->assertDecisionAuthorized($op);
            $this->assertStatus($op, Operation::STATUS_APPROVED, 'OP_NOT_APPROVED');

            $this->assertSalesReturnAllowed($op);
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->headReturned($op, $actor, $note);
            $accountantId = $op->approved_by_id; // capture before clearing
            app(\Modules\Shift\Services\ShiftReportReviewService::class)->assertMutableOperation($op);
            $op->update([
                'status' => Operation::STATUS_PENDING,
                'approved_by_id' => null,
                'approved_at' => null,
            ]);
            $this->step($op, 'review', 'أعادها رئيس الحسابات للمراجعة'.($note ? ' — '.$note : ''), $actor, $note, ['returnedForReview' => true]);

            return [$op->fresh(), $accountantId];
        });

        DB::afterCommit(function () use ($fresh, $accountantId, $actor, $note): void {
            $this->rt->operationStatusChanged($fresh, Operation::STATUS_APPROVED, Operation::STATUS_PENDING, $actor);
            if ($accountantId) {
                $this->notifications->push(
                    $accountantId, 'operation.returned_for_review',
                    'عملية أُعيدت للمراجعة', $fresh->public_id.($note ? ' — '.$note : ''),
                    null, ['type' => 'operation', 'id' => $fresh->id],
                );
            }
            event(new \Modules\Admin\Events\OperationReturnedForReview($fresh, $actor, $note));
        });

        return $fresh;
    }

    /**
     * «طلب توضيح» (SRS ACC-0.5 / ACC-1.4) — ask the submitter for more
     * information without moving the operation off its stage. Non-terminal:
     * the status is deliberately untouched.
     */
    public function requestClarification(Operation $op, AsabUser $actor, string $message): Operation
    {
        $fresh = DB::transaction(function () use ($op, $actor, $message) {
            // BRC-05: closed-record check on the locked row, so a stale request
            // cannot add evidence/notifications to an operation that was
            // finalised or rejected meanwhile.
            $op = $this->lockOperation($op);
            $this->assertMutable($op, 'لا يمكن طلب توضيح على عملية مُغلقة');

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
        $fresh = DB::transaction(function () use ($op, $actor, $note) {
            // BRC-05: lock, check the closed-record rule on the locked row and
            // merge into the LATEST payload (not the caller's possibly stale copy),
            // so a concurrent line edit is never overwritten.
            $op = $this->lockOperation($op);
            $this->assertMutable($op, 'لا يمكن توثيق عملية مُغلقة');

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
            $review = app(\Modules\Shift\Services\ShiftReportReviewService::class);
            if ($review->cashierSource($original) !== null || $review->managerSource($original) !== null) {
                $original = $this->lockOperation($original);
                $this->assertMutable($original, 'لا يمكن تعديل تقرير مُغلق');
                $review->assertMutableOperation($original);
                $sourceId = $review->cashierSource($original);
                if ($sourceId !== null) {
                    $review->assertReportEditable(\Modules\Shift\Models\CashierShift::withoutEagerLoads()->findOrFail($sourceId));
                }
            }
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
        return DB::transaction(function () use ($ids, $actor) {
            $this->assertBulkAllowed($ids, $actor);
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
        });
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
        return DB::transaction(function () use ($ids, $actor, $conditional, $conditionalNote) {
            $this->assertBulkAllowed($ids, $actor);
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
                } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
                    $failed[] = ['id' => $id, 'code' => $e->getMessage()];
                } catch (\Throwable $e) {
                    // A non-domain error on one op must not abort the whole batch.
                    $failed[] = ['id' => $id, 'code' => 'INTERNAL_ERROR'];
                }
            }

            return ['finalApproved' => $finalApproved, 'failed' => $failed];
        });
    }

    /**
     * HEAD-2.1 group «إرجاع للمراجعة» — return every approved op in the set.
     *
     * @param  string[]  $ids
     * @return array{returned: string[], failed: array<int, array{id:string, code:string}>}
     */
    public function bulkReturnForReview(array $ids, AsabUser $actor, ?string $note = null): array
    {
        return DB::transaction(function () use ($ids, $actor, $note) {
            $this->assertBulkAllowed($ids, $actor);
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
        });
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

    /**
     * Re-read the operation with a row lock. Must be called inside a transaction;
     * every status check and write of a transition then runs on this locked copy.
     */
    private function assertSalesReturnAllowed(Operation $op): void
    {
        if ($op->module_key === 'sales' && app(OperationWorkflowClassifier::class)->classify($op) !== OperationWorkflowClassifier::LEGACY) {
            throw new AsabException('RETURN_INDIVIDUAL_SHF_REQUIRED', 'Return the individual shift instead', 'يجب إرجاع الوردية الفردية', 409);
        }
    }

    private function assertBulkAllowed(array $ids, AsabUser $actor): void
    {
        // Resolve/lock in stable order; no mutation happens until the entire selection passes.
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $stub = Operation::find($id) ?? Operation::where('public_id', $id)->first();
            if ($stub === null) {
                throw new AsabException('BULK_SELECTION_INVALID', 'Incomplete bulk selection', 'مجموعة العمليات غير متاحة بالكامل', 409);
            }
            $op = $this->lockOperation($stub);
            $ctx = app(\Modules\Admin\Support\TenantContext::class);
            $companies = $ctx->resolved ? $ctx->companyIds() : [$actor->company_id];
            $branches = $ctx->resolved ? app(TenantBranchResolver::class)->operationBranchIds($ctx) : null;
            if (! in_array($op->company_id, $companies, true)
                || ($branches !== null && ! in_array($op->branch_id, $branches, true))
                || $actor->status !== 'active' || ! $actor->hasAnyAsabRole(['accountant', 'head'])) {
                throw new AsabException('BULK_SCOPE_FORBIDDEN', 'Bulk selection is outside actor scope', 'نطاق العمليات غير مصرح به', 403);
            }
            if ($op->module_key === 'shifts' || ($op->module_key === 'sales'
                && app(OperationWorkflowClassifier::class)->classify($op) !== OperationWorkflowClassifier::LEGACY)) {
                throw new AsabException('BULK_WORKFLOW_NOT_ALLOWED', 'Protected workflows require individual review', 'يلزم مراجعة فردية لمسار العملية', 409);
            }
        }
    }

    private function lockOperation(Operation $op): Operation
    {
        return app(\Modules\Shift\Services\ShiftReportReviewService::class)->lockOperation($op);
    }

    /**
     * BRC-03 / EXP-06 (TD-2): an expense is decided by the brand owner / brand
     * manager in the mobile flow, never by the dashboard accountant or head of
     * accounts. The `expenses` operation is a read-only approval mirror, so every
     * decision transition is refused here — on the locked row and before any state
     * change, audit step, event, notification or financial write. The mirror is
     * still closed by the owner's decision via ExpenseBridgeService (which does
     * not go through these transitions).
     */
    private function assertDecisionAuthorized(Operation $op): void
    {
        if ($op->module_key === 'sales' && app(OperationWorkflowClassifier::class)->classify($op) === OperationWorkflowClassifier::UNKNOWN) {
            throw new AsabException('WORKFLOW_SOURCE_REQUIRED', 'Verified workflow source is required', 'يلزم مصدر موثوق لمسار العملية', 409);
        }
        if ($op->module_key === 'expenses') {
            throw new AsabException(
                'EXPENSE_DECISION_NOT_AUTHORIZED',
                'Expense decisions belong to the brand owner; the dashboard shows expenses read-only',
                'قرار المصروف للمالك أو مدير العلامة التجارية فقط — الداشبورد للعرض والمتابعة',
                409,
                ['moduleKey' => $op->module_key],
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
