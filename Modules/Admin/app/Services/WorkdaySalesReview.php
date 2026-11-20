<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftReportReviewService;

class WorkdaySalesReview
{
    public function __construct(private readonly ShiftReportReviewService $reviews) {}

    public function details(Operation $operation): array
    {
        $questions = [];
        $events = $operation->steps()->whereNotNull('meta')->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($events as $event) {
            $meta = $event->meta;
            if (isset($meta['workday_question'])) {
                $questions[$meta['workday_question']] = [
                    'id' => $meta['workday_question'], 'shiftId' => $meta['shift_id'],
                    'shiftLabel' => $meta['shift_label'], 'message' => $event->note,
                    'askedAt' => $event->occurred_at?->toIso8601String(),
                    'reply' => null, 'repliedAt' => null,
                ];
            }
        }
        foreach ($events as $event) {
            $id = $event->meta['workday_reply_to'] ?? null;
            if ($id !== null && isset($questions[$id])) {
                $questions[$id]['reply'] = $event->note;
                $questions[$id]['repliedAt'] = $event->occurred_at?->toIso8601String();
            }
        }
        $review = $operation->payload['pkg6Review'] ?? [];
        $openIds = array_keys(array_filter($questions, fn ($question) => $question['reply'] === null));
        sort($openIds);

        return [
            'questions' => array_values($questions),
            'confirmation' => hash('sha256', json_encode([
                $operation->id, $operation->status, $operation->payload, $openIds,
            ], JSON_THROW_ON_ERROR)),
            'returnCycle' => ($review['phase'] ?? null) === 'day_manager_returned' ? $review['day_return_cycle'] : null,
            'returnReason' => $review['day_return_reason'] ?? null,
            'correctionNote' => $review['day_resubmission_note'] ?? null,
        ];
    }

    public function checkConfirmation(Operation $operation, ?string $confirmation): array
    {
        $review = $this->details($operation);
        $open = array_values(array_filter($review['questions'], fn ($question) => $question['reply'] === null));
        if (($open || $confirmation !== null) && $confirmation !== $review['confirmation']) {
            throw new AsabException('WORKDAY_REVIEW_CONFIRMATION_REQUIRED', 'Review the current open questions',
                'راجع التنبيه المحدّث عن طلبات التوضيح قبل الإرسال', 409, ['workdayReview' => $review]);
        }

        return array_column($open, 'id');
    }

    public function ask(Operation $operation, AsabUser $actor, ?string $shiftId, string $message): void
    {
        $day = $this->day($operation);
        $this->reviews->authorizeReview($operation, $actor, 'accountant');
        if ($operation->status !== Operation::STATUS_PENDING || ! $shiftId) {
            throw new AsabException('WORKDAY_SHIFT_REQUIRED', 'Select a shift in the pending workday',
                'اختر شفتًا داخل تقرير اليوم قيد المراجعة', 422);
        }
        $shift = ShiftLiabilityDailyLock::active()->where('branch_manager_shift_id', $day->id)
            ->where('cashier_shift_id', $shiftId)->first();
        if (! $shift) {
            throw new AsabException('WORKDAY_SHIFT_REQUIRED', 'Shift is outside this workday',
                'الشفت غير مرتبط بهذا التقرير', 422);
        }
        $label = DB::table('cashier_shifts as shift')->leftJoin('cashiers as cashier', 'cashier.id', '=', 'shift.cashier_id')
            ->where('shift.id', $shiftId)->value('cashier.name');
        $this->step($operation, $actor, 'review', 'طلب توضيح على شفت', $message, [
            'workday_question' => (string) Str::uuid(), 'shift_id' => $shiftId, 'shift_label' => $label ?: $shiftId,
        ]);
    }

    public function returnDay(Operation $operation, AsabUser $actor, string $reason): Operation
    {
        return DB::transaction(function () use ($operation, $actor, $reason) {
            $operation = $this->reviews->lockOperation($operation);
            $this->day($operation);
            $this->reviews->authorizeReview($operation, $actor, 'accountant');
            abort_unless($operation->status === Operation::STATUS_PENDING, 409, 'OP_NOT_PENDING');
            $payload = $operation->payload;
            $cycle = (string) Str::uuid();
            $payload['pkg6Review'] = array_merge($payload['pkg6Review'] ?? [], [
                'phase' => 'day_manager_returned', 'day_return_cycle' => $cycle,
                'day_return_reason' => $reason, 'day_resubmission_note' => null, 'approved_snapshot' => null,
            ]);
            $operation->update([
                'payload' => $payload, 'status' => Operation::STATUS_REJECTED, 'reject_reason' => $reason,
                'rejected_by_id' => $actor->id, 'rejected_at' => now(),
                'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
            ]);
            $this->step($operation, $actor, 'rejected', 'أُعيد تقرير اليوم لمدير الفرع', $reason, ['day_return_cycle' => $cycle]);

            return $operation;
        });
    }

    public function managerReports(BranchManager $manager): array
    {
        app(BranchManagerService::class)->assertAssignedActiveManager($manager->branch_id, $manager->id);
        $days = BranchManagerShift::query()->where('branch_id', $manager->branch_id)
            ->where('branch_manager_id', $manager->id)->where('daily_report_submitted', true)->select('id');
        return Operation::query()->where('branch_id', $manager->branch_id)->where('module_key', 'sales')
            ->where('origin', 'mobile')->whereIn('payload->managerShiftId', $days)
            ->where(fn ($query) => $query->where('payload->pkg6Review->phase', 'day_manager_returned')
                ->orWhereHas('steps', fn ($steps) => $steps->whereNotNull('meta->workday_question')))
            ->orderByDesc('operation_date')->get()->filter(fn ($operation) => $this->reviews->managerSource($operation) !== null)
            ->map(fn ($operation) => [
                'workdayId' => $operation->payload['managerShiftId'], 'publicId' => $operation->public_id,
                'date' => $operation->operation_date?->toDateString(), 'status' => $operation->status,
                ...$this->details($operation),
            ])->values()->all();
    }

    public function managerAction(BranchManager $manager, string $dayId, array $command): array
    {
        return DB::transaction(function () use ($manager, $dayId, $command) {
            app(BranchManagerService::class)->assertAssignedActiveManager($manager->branch_id, $manager->id);
            BranchManagerShift::query()->whereKey($dayId)->where('branch_manager_id', $manager->id)
                ->where('branch_id', $manager->branch_id)->where('daily_report_submitted', true)->firstOrFail();
            $operation = $this->reviews->lockOperation(Operation::query()->where('module_key', 'sales')
                ->where('origin', 'mobile')->where('branch_id', $manager->branch_id)
                ->where('payload->managerShiftId', $dayId)->sole());
            $this->day($operation);
            $review = $this->details($operation);
            if ($command['action'] === 'reply') {
                $question = collect($review['questions'])->firstWhere('id', $command['questionId']);
                abort_unless($question && $question['reply'] === null, 409, 'WORKDAY_QUESTION_ALREADY_ANSWERED');
                $this->step($operation, $manager, 'review', 'رد مدير الفرع على طلب التوضيح', $command['message'],
                    ['workday_reply_to' => $question['id']]);
            } else {
                abort_unless($operation->status === Operation::STATUS_REJECTED && $review['returnCycle'] === $command['returnCycle'],
                    409, 'STALE_RETURN_CYCLE');
                $payload = $operation->payload;
                $payload['pkg6Review']['phase'] = 'accountant_review';
                $payload['pkg6Review']['day_resubmission_note'] = $command['message'];
                $operation->update([
                    'payload' => $payload, 'status' => Operation::STATUS_PENDING, 'reject_reason' => null,
                    'rejected_by_id' => null, 'rejected_at' => null,
                    'reviewed_by_id' => null, 'reviewed_at' => null,
                ]);
                $this->step($operation, $manager, 'submit', 'أعاد مدير الفرع إرسال تقرير اليوم', $command['message'],
                    ['day_return_cycle' => $review['returnCycle']]);
            }

            return $this->details($operation);
        });
    }

    private function day(Operation $operation): BranchManagerShift
    {
        $id = $this->reviews->managerSource($operation);
        abort_unless($id, 404);

        return BranchManagerShift::query()->whereKey($id)->where('daily_report_submitted', true)->firstOrFail();
    }

    private function step(Operation $operation, AsabUser|BranchManager $actor, string $stage, string $action, string $note, array $meta): void
    {
        $operation->steps()->create([
            'stage_id' => $stage, 'action' => $action, 'note' => $note, 'actor_label' => $actor->name,
            'actor_user_id' => $actor instanceof AsabUser ? $actor->id : null,
            'meta' => $meta + ['actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id],
            'occurred_at' => now(),
        ]);
    }
}
