<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Support\OperationEnums;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Services\ShiftReportReviewService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class Pkg6ReturnResubmissionTest extends TestCase
{
    use RefreshDatabase, Pkg6ReviewFixture;

    private function returnReport(): Operation
    {
        $service = app(OperationService::class);
        $approved = $service->approve($this->operation, $this->accountant);
        $returned = $service->returnForReview($approved, $this->head, 'Verify report evidence');
        return $service->reject($returned, $this->accountant,
            array_key_first(OperationEnums::rejectionReasons('shifts')), 'Manager must verify');
    }

    private function manager(): BranchManager
    {
        return BranchManager::findOrFail($this->day->branch_manager_id);
    }

    public function test_entire_return_cycle_retains_shf_identity_and_operational_finality(): void
    {
        $returned = $this->returnReport();
        $this->assertSame('manager_returned', $returned->payload['pkg6Review']['phase']);
        $this->assertSame('rejected', $returned->status);
        $this->assertSame('pending_review', AdminShift::findOrFail($this->operation->payload['shiftId'])->status);
        $this->assertSame('completed', $this->source->fresh()->getRawOriginal('status'));
        $resubmitted = app(ShiftReportReviewService::class)->resubmit($this->source, $this->manager(), 1, null,
            $returned->payload['pkg6Review']['return_cycle'], 'Checked original report', (string) Str::uuid());
        $this->assertSame($this->operation->id, $resubmitted->id);
        $this->assertSame($this->operation->public_id, $resubmitted->public_id);
        $this->assertSame('pending', $resubmitted->status);
        $this->assertSame('accountant_review', $resubmitted->payload['pkg6Review']['phase']);
        $approved = app(OperationService::class)->approve($resubmitted, $this->accountant);
        $final = app(OperationService::class)->finalApprove($approved, $this->head);
        $this->assertSame('final-approved', $final->status);
        $this->assertSame($this->day->id, $this->membership->fresh()->branch_manager_shift_id);
        $this->assertNull($this->membership->fresh()->released_at);
        $this->assertNull($this->membership->fresh()->superseded_at);
        $this->assertTrue($this->day->fresh()->daily_report_submitted);
        $this->assertSame(1, Operation::where('module_key', 'shifts')->count());
    }

    public function test_head_return_cannot_be_approved_directly_by_accountant(): void
    {
        $approved = app(OperationService::class)->approve($this->operation, $this->accountant);
        $returned = app(OperationService::class)->returnForReview($approved, $this->head, 'Return');
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('REPORT_RETURN_CYCLE_REQUIRED');
        app(OperationService::class)->approve($returned, $this->accountant);
    }

    public function test_stale_cycle_and_revision_do_not_change_returned_report(): void
    {
        $returned = $this->returnReport();
        foreach ([(string) Str::uuid(), $returned->payload['pkg6Review']['return_cycle']] as $index => $cycle) {
            try {
                app(ShiftReportReviewService::class)->resubmit($this->source, $this->manager(), $index === 0 ? 1 : 99,
                    null, $cycle, 'Stale request', (string) Str::uuid());
                $this->fail('Stale request succeeded.');
            } catch (ConflictHttpException $error) {
                $this->assertSame($index === 0 ? 'STALE_RETURN_CYCLE' : 'STALE_REPORT_REVISION', $error->getMessage());
            }
        }
        $this->assertSame('rejected', $returned->fresh()->status);
        $this->assertSame(0, $returned->steps()->where('stage_id', 'submit')->count());
        $this->assertSame('pending', DB::table('shift_report_manager_reviews')->value('status'));
    }

    public function test_manager_correction_resubmits_new_evidence_in_original_day(): void
    {
        $returned = $this->returnReport();
        $revision = app(ShiftReportCorrectionService::class)->correctCashierReport($this->source, $this->manager(),
            ['handover_notes' => 'Verified document'], 1, 'Manager verification', (string) Str::uuid());
        $resubmitted = app(ShiftReportReviewService::class)->resubmit($this->source, $this->manager(), $revision->revision_number,
            null, $returned->payload['pkg6Review']['return_cycle'], 'Current evidence', (string) Str::uuid());
        $this->assertSame($this->day->id, $resubmitted->payload['pkg6Review']['resubmitted_snapshot']['day_id']);
        $this->assertSame($revision->id, $resubmitted->payload['pkg6Review']['resubmitted_snapshot']['revision_id']);
        $this->assertNotNull($this->membership->fresh()->superseded_at);
        $this->assertSame($this->operation->id, $resubmitted->id);
        $this->assertSame(0, DB::table('shift_liability_postings')->count());
    }

    public function test_resubmission_http_replay_is_exactly_once(): void
    {
        $returned = $this->returnReport();
        $body = ['expected_revision' => 1, 'return_cycle' => $returned->payload['pkg6Review']['return_cycle'], 'reason' => 'Verified'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $url = '/api/v1/branch-manager/workday/shifts/'.$this->source->id.'/report/resubmit';
        $first = $this->actingAs($this->manager(), 'sanctum')->postJson($url, $body, $headers);
        $first->assertOk();
        $second = $this->postJson($url, $body, $headers);
        $second->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $returned->steps()->where('stage_id', 'submit')->count());
    }
    public function test_returned_accountant_allocation_is_preserved_when_manager_confirms_unchanged_report(): void
    {
        $revision = app(\Modules\Shift\Services\AccountantReportCorrectionService::class)->correct($this->source, $this->accountant,
            ['counted_cash' => '90.00'], [['type' => 'cashier', 'id' => $this->source->cashier_id, 'amount' => 1000]],
            1, null, 'Count transcription', (string) Str::uuid());
        $allocation = \Modules\Shift\Models\ShiftLiabilityAllocation::where('cashier_shift_id', $this->source->id)->sole();
        $before = $allocation->getRawOriginal();
        $returned = $this->returnReport();
        $result = app(ShiftReportReviewService::class)->resubmit($this->source, $this->manager(), 2, 1,
            $returned->payload['pkg6Review']['return_cycle'], 'Unchanged financial facts verified', (string) Str::uuid());
        $this->assertSame('pending', $result->status);
        $this->assertSame($before, $allocation->fresh()->getRawOriginal());
        $this->assertSame(1, DB::table('shift_liability_postings')->count());
        $this->assertSame(1000, (int) DB::table('shift_liability_postings')->sum('delta_halalas'));
    }

    public function test_resubmission_failure_rolls_back_manager_review_and_operation_transition(): void
    {
        $returned = $this->returnReport();
        $mock = \Mockery::mock(\Modules\Shift\Services\ShiftReportManagerReviewService::class)->makePartial();
        $mock->shouldReceive('approveCurrent')->once()->andReturnUsing(function () {
            DB::table('shift_report_manager_reviews')->update(['status' => 'approved']);
            throw new \RuntimeException('injected resubmission failure');
        });
        $this->app->instance(\Modules\Shift\Services\ShiftReportManagerReviewService::class, $mock);
        try {
            app(ShiftReportReviewService::class)->resubmit($this->source, $this->manager(), 1, null,
                $returned->payload['pkg6Review']['return_cycle'], 'Verified', (string) Str::uuid());
            $this->fail('Resubmission ignored rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected resubmission failure', $error->getMessage());
        }
        $this->assertSame('pending', DB::table('shift_report_manager_reviews')->value('status'));
        $this->assertSame('rejected', $returned->fresh()->status);
        $this->assertSame(0, $returned->steps()->where('stage_id', 'submit')->count());
        $this->assertNull($this->membership->fresh()->released_at);
    }

    public function test_returned_manager_can_correct_via_http_before_resubmitting_same_shf(): void
    {
        $returned = $this->returnReport();
        $prefix = '/api/v1/branch-manager/workday/shifts/'.$this->source->id.'/report/';
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['expected_revision' => 1, 'changes' => ['handover_notes' => 'Verified by manager'], 'reason' => 'Document correction'];
        $first = $this->actingAs($this->manager(), 'sanctum')->postJson($prefix.'correct', $body, $headers);
        $first->assertOk()->assertJsonPath('data.revision_number', 2);
        $this->postJson($prefix.'correct', $body, $headers)->assertOk()->assertJsonPath('data.revision_number', 2);
        $this->postJson($prefix.'resubmit', ['expected_revision' => 2, 'return_cycle' => $returned->payload['pkg6Review']['return_cycle'],
            'reason' => 'Current evidence submitted'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.operation_id', $this->operation->id);
        $this->assertSame($this->day->id, $this->operation->fresh()->payload['pkg6Review']['resubmitted_snapshot']['day_id']);
        $this->assertSame('completed', $this->source->fresh()->getRawOriginal('status'));
    }

    public function test_required_recount_of_officially_returned_report_preserves_original_day(): void
    {
        $returned = $this->returnReport();
        DB::table('shift_report_aggregates')->where('source_id', $this->source->id)->update(['fresh_count_required' => true]);
        $count = app(\Modules\Shift\Services\ShiftTransferAttemptService::class)->recount($this->source,
            \Modules\Cashier\Models\Cashier::findOrFail($this->source->cashier_id), 10000);
        $this->assertSame(10000, $count->counted_halalas);
        $this->assertSame('rejected', $returned->fresh()->status);
        $this->assertSame(2, DB::table('shift_report_cash_counts')->where('cashier_shift_id', $this->source->id)->count());
        $this->assertFalse((bool) DB::table('shift_report_aggregates')->where('source_id', $this->source->id)->value('fresh_count_required'));
        $this->assertTrue($this->day->fresh()->daily_report_submitted);
        $this->assertNull($this->membership->fresh()->released_at);
        $this->assertNotNull($this->membership->fresh()->superseded_at);
        $current = \Modules\Shift\Models\ShiftLiabilityDailyLock::active()->where('cashier_shift_id', $this->source->id)->sole();
        $this->assertSame($this->day->id, $current->branch_manager_shift_id);
        $this->assertSame($count->evidence_revision_id, $current->report_revision);
    }

}
