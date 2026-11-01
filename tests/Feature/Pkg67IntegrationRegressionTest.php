<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Support\OperationEnums;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\ShiftNotWorkedService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Transformers\ShiftDetailResource;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * PKG-2/3 + PKG-6 + PKG-7 composition regressions. Everything here runs the real composed
 * implementation (no mocks of the guard, the review service or the correction services).
 * SQLite functional evidence only; it is not MariaDB lock-safety evidence.
 */
class Pkg67IntegrationRegressionTest extends TestCase
{
    use Pkg6ReviewFixture, RefreshDatabase;

    private ?object $actor = null;

    private function manager(): BranchManager
    {
        return BranchManager::findOrFail($this->day->branch_manager_id);
    }

    /** Return the report through the real approval chain: accountant -> head -> manager_returned. */
    private function returnReport(): Operation
    {
        $service = app(OperationService::class);
        $approved = $service->approve($this->operation, $this->accountant);
        $returned = $service->returnForReview($approved, $this->head, 'Verify report evidence');

        return $service->reject($returned, $this->accountant,
            array_key_first(OperationEnums::rejectionReasons('shifts')), 'Manager must verify');
    }

    /** The exact explicit audit fact ShiftNotWorkedService writes (BRC-11), planted on a shift that has a report. */
    private function plantExplicitNoWorkFact(): void
    {
        $at = now()->startOfSecond();
        $this->source->history()->create([
            'action' => 'marked_not_worked', 'performed_by' => (string) $this->manager()->id,
            'performed_by_type' => 'branch_manager', 'created_at' => $at,
            'old_value' => ['status' => 'not_started'],
            'new_value' => ['status' => 'canceled', 'outcome' => 'not_worked', 'reason' => 'Closed',
                'recorded_at' => $at->toIso8601String()],
            'notes' => 'Closed',
        ]);
        $this->assertTrue($this->source->fresh()->notWorkedHistory()->exists());
    }

    private function revisionNumber(): int
    {
        return (int) DB::table('shift_report_aggregates')->where('source_id', $this->source->id)->value('current_revision_number');
    }

    private function accountantCorrect(): mixed
    {
        return app(AccountantReportCorrectionService::class)->correct($this->source, $this->accountant,
            ['counted_cash' => '101.00'], null, 1, null, 'Verified original evidence correction', (string) Str::uuid());
    }

    private function managerCorrect(): mixed
    {
        return app(ShiftReportCorrectionService::class)->correctCashierReport($this->source, $this->manager(),
            ['handover_notes' => 'Verified document'], 1, 'Manager verification', (string) Str::uuid(), null, true);
    }

    // ---- I6-01: both BRC-07 early-return paths must sit BELOW the BRC-11 fence ----------------

    public function test_control_accountant_early_return_path_is_valid_without_the_marker(): void
    {
        $this->accountantCorrect();
        $this->assertSame(2, $this->revisionNumber());
    }

    public function test_accountant_early_return_path_rejects_a_marked_not_worked_report(): void
    {
        $before = ['revision' => $this->revisionNumber(), 'counts' => ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->count()];
        $this->plantExplicitNoWorkFact();
        try {
            $this->accountantCorrect();
            $this->fail('Accountant correction bypassed the not-worked fence.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('SHIFT_MARKED_NOT_WORKED', $error->getMessage());
        }
        $this->assertSame($before['revision'], $this->revisionNumber());
        $this->assertSame($before['counts'], ShiftReportCashCount::where('cashier_shift_id', $this->source->id)->count());
        $this->assertNull($this->membership->fresh()->superseded_at);
        $this->assertSame(0, DB::table('shift_liability_postings')->count());
    }

    public function test_control_manager_early_return_path_is_valid_without_the_marker(): void
    {
        $this->returnReport();
        $revision = $this->managerCorrect();
        $this->assertSame(2, $revision->revision_number);
    }

    public function test_manager_early_return_path_rejects_a_marked_not_worked_report(): void
    {
        $this->returnReport();
        $this->plantExplicitNoWorkFact();
        $notes = $this->source->fresh()->handover_notes;
        try {
            $this->managerCorrect();
            $this->fail('Manager correction bypassed the not-worked fence.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('SHIFT_MARKED_NOT_WORKED', $error->getMessage());
        }
        $this->assertSame(1, $this->revisionNumber());
        $this->assertSame($notes, $this->source->fresh()->handover_notes);
        $this->assertSame(0, DB::table('shift_report_corrections')->count());
        $this->assertNull($this->membership->fresh()->superseded_at);
    }

    public function test_marked_report_cannot_be_reopened_or_edited_through_lock_editable(): void
    {
        $this->plantExplicitNoWorkFact();
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('SHIFT_MARKED_NOT_WORKED');
        DB::transaction(fn () => app(\Modules\Shift\Services\ShiftReportMutationGuard::class)
            ->lockEditable($this->source, $this->manager()));
    }

    public function test_fence_is_placed_before_both_early_returns_in_source_order(): void
    {
        $source = file_get_contents(base_path('Modules/Shift/app/Services/ShiftReportMutationGuard.php'));
        $start = strpos($source, 'public function lockCorrection');
        $body = substr($source, $start, strpos($source, 'private function membershipDates') - $start);
        $fence = strpos($body, 'ShiftNotWorkedGuard::class)->assertMutable($shift)');
        $this->assertNotFalse($fence);
        foreach (['managerCorrectionAllowed($shift', 'accountantCorrectionAllowed($shift'] as $earlyReturn) {
            $position = strpos($body, $earlyReturn);
            $this->assertNotFalse($position, $earlyReturn);
            $this->assertLessThan($position, $fence, "Fence must precede {$earlyReturn}");
        }
        // The fence must run against the locked re-read row, after re-authorization.
        $this->assertLessThan($fence, strrpos(substr($body, 0, $fence), 'assertAllowed($shift, $actor)'));
    }

    // ---- I6-02 / I6-06: submitted or returned reports are never "not worked" -----------------

    public function test_submitted_and_returned_reports_cannot_become_not_worked(): void
    {
        foreach ([false, true] as $returned) {
            if ($returned) {
                $this->returnReport();
            }
            try {
                app(ShiftNotWorkedService::class)->mark($this->source, $this->manager(), 'Closed');
                $this->fail('A report with evidence became not worked.');
            } catch (ConflictHttpException $error) {
                $this->assertContains($error->getMessage(), [
                    'SHIFT_NOT_WORKED_NOT_ELIGIBLE', 'SHIFT_NOT_WORKED_HAS_EVIDENCE', 'REPORT_REOPEN_REQUIRED']);
            }
            $this->assertSame(0, $this->source->notWorkedHistory()->count());
            $this->assertSame('completed', $this->source->fresh()->getRawOriginal('status'));
            $this->assertNull($this->membership->fresh()->released_at);
            $this->assertSame($this->day->id, $this->membership->fresh()->branch_manager_shift_id);
        }
    }

    public function test_ordinary_cancellation_is_never_inferred_as_no_work(): void
    {
        DB::table('cashier_shifts')->where('id', $this->source->id)->update(['status' => ShiftStatus::CANCELED->value]);
        $fresh = CashierShift::findOrFail($this->source->id);
        $this->assertNull($fresh->notWorkedOutcome());
        $this->assertSame(0, $fresh->notWorkedHistory()->count());
    }

    // ---- ShiftDetailResource: all three packages' semantics together ------------------------

    /** A separate branch with no submitted day: the PKG-6 fixture branch correctly refuses no-work on a submitted day. */
    private function freshScope(): array
    {
        $this->travelTo(today()->setTime(15, 0));
        $branch = \Modules\Branch\Models\Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = \Modules\Shift\Models\Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => '08:00:00', 'end_time' => '14:00:00']);
        $shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today()]);

        return [$branch, $manager, $cashier, $shift, $template];
    }

    private function actions(CashierShift $shift, ?object $actor = null): array
    {
        $this->actingAs($actor ?? $this->actor ?? $this->manager(), 'sanctum');
        $payload = (new ShiftDetailResource(CashierShift::findOrFail($shift->id)))->toArray(request());

        return $payload;
    }

    public function test_detail_resource_keeps_pkg3_pkg6_and_pkg7_projections(): void
    {
        $payload = $this->actions($this->source);
        $this->assertArrayHasKey('report_review', $payload);
        $this->assertArrayHasKey('not_worked', $payload);
        $this->assertNull($payload['not_worked']);
        $this->assertArrayHasKey('can_accept_assignment', $payload['available_actions']);
        $this->assertArrayHasKey('can_mark_not_worked', $payload['available_actions']);
        $this->assertFalse($payload['available_actions']['can_mark_not_worked'], 'A completed report is never markable.');
    }

    public function test_can_start_keeps_pkg3_operational_gates_and_not_the_pkg7_shortcut(): void
    {
        [, $freshManager, , $fresh] = $this->freshScope();
        $this->actor = $freshManager;
        $this->assertTrue($this->actions($fresh)['available_actions']['can_start']);
        $this->assertFalse($this->actions($fresh)['available_actions']['can_accept_assignment']);

        // Operational evidence while the raw status still says not_started: PKG-3 refuses the hint, the old shortcut would not.
        DB::table('cashier_shifts')->where('id', $fresh->id)->update(['actual_start_time' => now()]);
        $this->assertFalse($this->actions($fresh)['available_actions']['can_start']);
        DB::table('cashier_shifts')->where('id', $fresh->id)->update(['actual_start_time' => null, 'operational_ended_at' => now()]);
        $this->assertFalse($this->actions($fresh)['available_actions']['can_start']);
        DB::table('cashier_shifts')->where('id', $fresh->id)->update(['operational_ended_at' => null, 'status' => ShiftStatus::REASSIGNED->value]);
        $reassigned = $this->actions($fresh)['available_actions'];
        $this->assertFalse($reassigned['can_start']);
        $this->assertTrue($reassigned['can_accept_assignment']);
        $this->assertFalse($reassigned['can_mark_not_worked'], 'Unaccepted reassignment is not an untouched assignment.');
    }

    public function test_marked_shift_projects_label_outcome_and_no_further_actions(): void
    {
        [, $freshManager, , $fresh] = $this->freshScope();
        $this->actor = $freshManager;
        $this->assertTrue($this->actions($fresh)['available_actions']['can_mark_not_worked']);
        app(ShiftNotWorkedService::class)->mark($fresh, $freshManager, 'Closed');
        $payload = $this->actions($fresh);
        $this->assertSame('لم تعمل', $payload['status_label']);
        $this->assertSame('not_worked', $payload['not_worked']['outcome']);
        $this->assertFalse($payload['available_actions']['can_mark_not_worked']);
        $this->assertFalse($payload['available_actions']['can_start']);
        $this->assertArrayHasKey('report_review', $payload);
    }

    // ---- routes / middleware: PKG-6 and PKG-7 registrations coexist -------------------------

    public function test_both_packages_routes_are_registered_without_collision(): void
    {
        $routes = Route::getRoutes();
        $mark = $routes->getByName('branch-manager.shifts.mark-not-worked');
        $correct = $routes->getByName('workday.shifts.report.correct');
        $resubmit = $routes->getByName('workday.shifts.report.resubmit');
        $this->assertNotNull($mark);
        $this->assertNotNull($correct);
        $this->assertNotNull($resubmit);
        $this->assertContains('POST', $mark->methods());
        $this->assertStringEndsWith('branch-manager/shifts/{shift}/mark-not-worked', $mark->uri());

        // Order matters: authenticate, assigned-manager role, throttle log, authority revalidation, THEN idempotency.
        $middleware = array_values($mark->gatherMiddleware());
        $position = fn (string $needle) => collect($middleware)->search(fn ($m) => str_contains((string) $m, $needle));
        $this->assertNotFalse($position('auth:sanctum'));
        $this->assertLessThan($position('branch.manager'), $position('auth:sanctum'));
        $this->assertLessThan($position('log.throttle'), $position('branch.manager'));
        $this->assertLessThan($position('ShiftNotWorkedGuard'), $position('log.throttle'));
        $this->assertLessThan($position('asab.idempotency'), $position('ShiftNotWorkedGuard'));
        $this->assertFalse($position('branch.manager.or.cashier'), 'No cashier access on the manager-only route.');

        foreach ([$correct, $resubmit] as $route) {
            $this->assertContains('asab.idempotency:required,transaction,legacy', $route->gatherMiddleware());
        }

        // The project registers every API route under /api and /api/v1; each URI must have exactly one owner.
        $owners = collect($routes->getRoutes())->filter(fn ($r) => in_array('POST', $r->methods(), true)
            && str_ends_with($r->uri(), 'mark-not-worked'))->map->uri()->sort()->values()->all();
        $this->assertSame(['api/branch-manager/shifts/{shift}/mark-not-worked',
            'api/v1/branch-manager/shifts/{shift}/mark-not-worked'], $owners);
    }

    public function test_mark_route_http_access_matrix(): void
    {
        [, $freshManager, $cashier, $fresh] = $this->freshScope();
        $url = "/api/v1/branch-manager/shifts/{$fresh->id}/mark-not-worked";
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson($url, ['reason' => 'Closed'], $key)->assertUnauthorized();
        $this->actingAs($cashier, 'sanctum')->postJson($url, ['reason' => 'Closed'], $key)->assertForbidden();
        $this->assertSame(0, $fresh->notWorkedHistory()->count());

        $otherBranchManager = BranchManager::factory()->create(['branch_id' => \Modules\Branch\Models\Branch::factory()->create()->id]);
        $this->actingAs($otherBranchManager, 'sanctum')->postJson($url, ['reason' => 'Closed'], $key)->assertStatus(404);
        $this->assertSame(0, $fresh->notWorkedHistory()->count());

        $this->actingAs($freshManager, 'sanctum')->postJson($url, ['reason' => 'Closed'])->assertUnprocessable();
        $this->actingAs($this->manager(), 'sanctum')->postJson($url, ['reason' => 'Closed'], $key)->assertStatus(404); // sibling-branch manager
        $this->actingAs($freshManager, 'sanctum')->postJson($url, ['reason' => 'Closed'], $key)->assertOk();
        $this->assertSame(1, $fresh->notWorkedHistory()->count());
    }

    // ---- PKG-3 acceptance vs PKG-7 no-work (I6-04) ------------------------------------------

    public function test_unaccepted_reassignment_is_not_markable_and_accepted_one_is(): void
    {
        [$branch, $freshManager, $cashier, $shift] = $this->freshScope();
        $replacement = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $freshManager->id]);

        $this->actingAs($freshManager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/reassign",
            ['new_cashier_id' => $replacement->id, 'reason' => 'Administrative replacement'])->assertOk();
        $this->assertSame(ShiftStatus::REASSIGNED, $shift->fresh()->status);
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $freshManager, 'Closed');
            $this->fail('Unaccepted reassignment was marked not worked.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('SHIFT_NOT_WORKED_NOT_ELIGIBLE', $error->getMessage());
        }
        // PKG-3: start before acceptance is refused with the contract error.
        $this->actingAs($replacement, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/start")
            ->assertStatus(409)->assertJsonPath('code', 'ASSIGNMENT_ACCEPTANCE_REQUIRED');
        $this->assertSame(0, $shift->notWorkedHistory()->count());

        $this->actingAs($replacement, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/reassign/accept")->assertOk();
        $history = $shift->history()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->actingAs($freshManager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/mark-not-worked",
            ['reason' => 'Closed'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame($history, $shift->history()->where('action', '!=', 'marked_not_worked')->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($cashier->id, $shift->fresh()->original_cashier_id, 'Original cashier identity is preserved.');
        $this->assertSame(0, DB::table('shift_report_aggregates')->where('source_id', $shift->id)->count());

        // A marked shift can neither be started nor re-accepted afterwards.
        $this->actingAs($replacement, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/start")->assertStatus(400);
        $this->assertSame(ShiftStatus::CANCELED, $shift->fresh()->status);
    }
}
