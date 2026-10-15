<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Support\OperationEnums;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Services\ShiftReportMutationGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Final-acceptance gap closure (mutation M4): ShiftReportMutationGuard::lockCorrection runs the BRC-11 no-work
 * fence (SHIFT_MARKED_NOT_WORKED) BEFORE the BRC-07 manager/accountant early returns. A marked shift cannot
 * legitimately carry a reviewable report, so the ordering is defence in depth against a corrupt or racing
 * combined state -- the only state in which an early return could otherwise skip the fence. Each test first proves
 * the early-return path is live on the identical fixture (control), then plants the no-work fact and requires the
 * fence to win. Moving the fence below the early returns turns both tests red.
 */
class Pkg67FenceOrderingTest extends TestCase
{
    use Pkg6ReviewFixture, RefreshDatabase;

    private function plantNoWorkFact(): void
    {
        $this->source->history()->create([
            'action' => 'marked_not_worked', 'performed_by' => (string) $this->day->branch_manager_id,
            'performed_by_type' => 'branch_manager', 'created_at' => now()->startOfSecond(),
            'old_value' => ['status' => 'not_started'],
            'new_value' => ['status' => 'canceled', 'outcome' => 'not_worked', 'reason' => 'planted', 'recorded_at' => now()->startOfSecond()->toIso8601String()],
            'notes' => 'planted',
        ]);
    }

    private function lock(object $actor): void
    {
        DB::transaction(fn () => app(ShiftReportMutationGuard::class)->lockCorrection($this->source->fresh(), $actor));
    }

    public function test_no_work_fence_precedes_the_accountant_early_return(): void
    {
        $this->lock($this->accountant); // control: submitted day, accountant_review => early return is live and accepts
        $this->plantNoWorkFact();
        try {
            $this->lock($this->accountant);
            $this->fail('The accountant early return skipped the no-work fence.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('SHIFT_MARKED_NOT_WORKED', $error->getMessage());
        }
    }

    public function test_no_work_fence_precedes_the_manager_early_return(): void
    {
        $service = app(OperationService::class);
        $returned = $service->reject($service->returnForReview($service->approve($this->operation, $this->accountant), $this->head, 'verify'),
            $this->accountant, array_key_first(OperationEnums::rejectionReasons('shifts')), 'Manager must verify');
        $this->assertSame('manager_returned', $returned->payload['pkg6Review']['phase']);
        $manager = BranchManager::findOrFail($this->day->branch_manager_id);
        $this->lock($manager); // control: manager_returned => manager early return is live and accepts
        $this->plantNoWorkFact();
        try {
            $this->lock($manager);
            $this->fail('The manager early return skipped the no-work fence.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('SHIFT_MARKED_NOT_WORKED', $error->getMessage());
        }
    }
}
