<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShiftNotWorkedService
{
    public function __construct(private ShiftNotWorkedGuard $guard) {}

    public function mark(CashierShift $source, BranchManager $actor, string $reason): CashierShift
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A nonempty reason of at most 500 characters is required.']);
        }

        return DB::transaction(function () use ($source, $actor, $reason) {
            $source = CashierShift::withoutEagerLoads()->findOrFail($source->id);
            $this->guard->assertActor($source, $actor);
            // Existing boundary orders cash-owner -> workday -> source locks and rejects submitted days.
            $shift = app(ShiftReportMutationGuard::class)->lockEditable($source, $actor);
            if ($shift->cashier_id !== $source->cashier_id) {
                throw new ConflictHttpException('SHIFT_ASSIGNMENT_CHANGED');
            }
            $this->guard->assertActor($shift, $actor);
            $this->guard->assertEligible($shift);
            $at = now()->startOfSecond();
            // No start/end events, manager-day creation or aggregation. Audit is written explicitly.
            $shift->forceFill(['status' => ShiftStatus::CANCELED])->saveQuietly();
            $shift->history()->create([
                'action' => 'marked_not_worked', 'performed_by' => (string) $actor->id,
                'performed_by_type' => 'branch_manager', 'created_at' => $at,
                'old_value' => ['status' => ShiftStatus::NOT_STARTED->value],
                'new_value' => ['status' => ShiftStatus::CANCELED->value, 'outcome' => 'not_worked',
                    'reason' => $reason, 'recorded_at' => $at->toIso8601String()],
                'notes' => $reason,
            ]);
            app(ShiftReportCacheInvalidator::class)->cashierReport($shift);

            return $shift->fresh(['notWorkedHistory']);
        });
    }
}
