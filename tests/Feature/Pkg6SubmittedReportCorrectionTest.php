<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\ShiftReportReviewService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class Pkg6SubmittedReportCorrectionTest extends TestCase
{
    use RefreshDatabase, Pkg6ReviewFixture;

    private function correct(array $changes, int $revision = 1): mixed
    {
        return app(AccountantReportCorrectionService::class)->correct($this->source, $this->accountant,
            $changes, null, $revision, null, 'Verified original evidence correction', (string) Str::uuid());
    }

    public function test_submitted_count_correction_retains_original_count_and_membership(): void
    {
        $originalCount = ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->sole();
        $before = $originalCount->getRawOriginal();
        $revision = $this->correct(['counted_cash' => '101.00']);
        $count = ShiftReportCashCount::where('report_revision_id', $revision->id)->sole();
        $this->assertSame($before, $originalCount->fresh()->getRawOriginal());
        $this->assertSame(10100, $count->counted_halalas);
        $this->assertSame($originalCount->counted_revision_id, $count->counted_revision_id);
        $this->assertSame($revision->id, $count->evidence_revision_id);
        $this->assertSame(1, ShiftReportCorrection::where('new_revision_id', $revision->id)->where('field_name', 'counted_cash')->count());
        $active = ShiftLiabilityDailyLock::active()->where('cashier_shift_id', $this->source->id)->sole();
        $this->assertSame($this->day->id, $active->branch_manager_shift_id);
        $this->assertSame($count->evidence_revision_id, $active->report_revision);
        $this->assertNotNull($this->membership->fresh()->superseded_at);
        $this->assertNull($this->membership->fresh()->released_at);
        $this->assertSame($this->operation->id, $this->operation->fresh()->id);
        $this->assertSame(10100, $this->operation->fresh()->payload['cashActualHalalas']);
        $this->assertTrue($this->day->fresh()->daily_report_submitted);
        $this->assertSame(0, DB::table('shift_liability_postings')->count());
    }

    public function test_counted_cash_cannot_satisfy_a_required_physical_recount(): void
    {
        DB::table('shift_report_aggregates')->where('source_id', $this->source->id)->update(['fresh_count_required' => true]);
        try {
            $this->correct(['counted_cash' => '101.00']);
            $this->fail('A correction bypassed the physical recount.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('PHYSICAL_RECOUNT_REQUIRED', $error->getMessage());
        }
        $this->assertSame(1, ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->count());
        $this->assertNull($this->membership->fresh()->superseded_at);
    }

    public function test_stale_retry_has_no_second_revision_or_financial_effect(): void
    {
        $this->correct(['counted_cash' => '101.00']);
        try {
            $this->correct(['counted_cash' => '102.00']);
            $this->fail('Stale retry succeeded.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('STALE_REPORT_REVISION', $error->getMessage());
        }
        $this->assertSame(2, ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->count());
        $this->assertSame(10100, $this->operation->fresh()->payload['cashActualHalalas']);
        $this->assertSame(0, DB::table('shift_liability_postings')->count());
    }

    public function test_failure_after_evidence_creation_rolls_back_every_projection(): void
    {
        $mock = \Mockery::mock(ShiftReportReviewService::class)->makePartial();
        $mock->shouldReceive('syncCorrection')->once()->andThrow(new \RuntimeException('injected final synchronization failure'));
        $this->app->instance(ShiftReportReviewService::class, $mock);
        try {
            $this->correct(['counted_cash' => '101.00']);
            $this->fail('Injected failure was ignored.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected final synchronization failure', $error->getMessage());
        }
        $this->assertSame(1, ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->count());
        $this->assertSame(1, DB::table('shift_report_aggregates')->where('source_id', $this->source->id)->value('current_revision_number'));
        $this->assertSame(0, ShiftReportCorrection::count());
        $this->assertSame(0, DB::table('shift_report_correction_notifications')->count());
        $this->assertSame(10000, $this->operation->fresh()->payload['cashActualHalalas']);
        $this->assertNull($this->membership->fresh()->superseded_at);
    }
    public function test_count_corrections_post_only_liability_deltas_and_preserve_retry_lineage(): void
    {
        $cashier = $this->source->cashier_id;
        $service = app(AccountantReportCorrectionService::class);
        $first = $service->correct($this->source, $this->accountant, ['counted_cash' => '90.00'],
            [['type' => 'cashier', 'id' => $cashier, 'amount' => 1000]], 1, null, 'Original count transcription', (string) Str::uuid());
        $second = $service->correct($this->source, $this->accountant, ['counted_cash' => '95.00'],
            [['type' => 'cashier', 'id' => $cashier, 'amount' => 500]], 2, 1, 'Correct transcription evidence', (string) Str::uuid());
        $this->assertSame(3, $second->revision_number);
        $postings = DB::table('shift_liability_postings')->where('cashier_shift_id', $this->source->id)->orderBy('created_at')->get();
        $this->assertCount(2, $postings);
        $this->assertSame([1000, -500], $postings->pluck('delta_halalas')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(500, (int) $postings->sum('delta_halalas'));
        $retry = app(\Modules\Shift\Services\ShortagePostingService::class)->reconcile($this->source,
            $this->accountant, 3, 2, (string) Str::uuid());
        $this->assertSame(0, $retry['total_deltas']);
        $this->assertSame(2, DB::table('shift_liability_postings')->count());
        $this->assertSame(3, ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->count());
        $this->assertSame(2, DB::table('shift_liability_allocations')->where('cashier_shift_id', $this->source->id)->count());
        $this->assertSame(1, DB::table('shift_liability_allocations')->where('cashier_shift_id', $this->source->id)->whereNotNull('superseded_at')->count());
    }

}
