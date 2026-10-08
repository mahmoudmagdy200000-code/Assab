<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Liability\DailyCloseEvidence;
use Modules\Shift\Liability\DailyLiabilityGuard;
use Modules\Shift\Liability\LiabilityEvidenceSource;
use Modules\Shift\Liability\ReportEvidence;
use Modules\Shift\Liability\ResponsibleActorResolver;
use Modules\Shift\Liability\ShiftLiabilityService;
use Modules\Shift\Liability\UnavailableLiabilityEvidence;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** Isolated schema/service tests, NOT MySQL locking or legacy HTTP integration certification. */
class ShiftLiabilityServiceTest extends TestCase
{
    private ShiftLiabilityService $service;

    private TestLiabilityEvidence $source;

    private Cashier $cashier;

    private BranchManager $manager;

    private string $shiftId;

    private string $branchId;

    private string $companyId;

    private string $workdayId;

    protected function setUp(): void
    {
        parent::setUp();
        // Never migrate or clear an external database. All test writes are disposable.
        config(['database.connections.s107' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('s107');
        DB::purge('s107');
        Schema::create('branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('asab_company_id');
        });
        Schema::create('shifts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id');
        });
        foreach (['cashiers', 'branch_managers'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('branch_id');
                $t->softDeletes();
            });
        }
        Schema::create('asab_employees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('branch_id');
            $t->softDeletes();
        });
        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('shift_id');
            $t->uuid('cashier_id');
        });
        Schema::create('branch_manager_shifts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id');
            $t->uuid('branch_manager_id');
        });
        (require base_path('Modules/Shift/database/migrations/2026_10_08_000001_create_shift_liability_allocations.php'))->up();
        $this->companyId = (string) Str::uuid();
        $this->branchId = (string) Str::uuid();
        $this->shiftId = (string) Str::uuid();
        $this->workdayId = (string) Str::uuid();
        $template = (string) Str::uuid();
        $cashierId = (string) Str::uuid();
        $managerId = (string) Str::uuid();
        DB::table('branches')->insert(['id' => $this->branchId, 'asab_company_id' => $this->companyId]);
        DB::table('shifts')->insert(['id' => $template, 'branch_id' => $this->branchId]);
        DB::table('cashiers')->insert(['id' => $cashierId, 'branch_id' => $this->branchId]);
        DB::table('branch_managers')->insert(['id' => $managerId, 'branch_id' => $this->branchId]);
        DB::table('cashier_shifts')->insert(['id' => $this->shiftId, 'shift_id' => $template, 'cashier_id' => $cashierId]);
        DB::table('branch_manager_shifts')->insert(['id' => $this->workdayId, 'branch_id' => $this->branchId, 'branch_manager_id' => $managerId]);
        $this->cashier = Cashier::findOrFail($cashierId);
        $this->manager = BranchManager::findOrFail($managerId);
        $this->source = new TestLiabilityEvidence;
        $this->setEvidence(-2000, 'r1');
        $this->service = new ShiftLiabilityService($this->source, new ResponsibleActorResolver);
    }

    private function setEvidence(int $variance, string $revision): void
    {
        $this->source->report = new ReportEvidence($this->shiftId, $this->companyId, $this->branchId, $revision, $variance, true);
        $this->source->scope = new DailyCloseEvidence($this->workdayId, $this->companyId, $this->branchId, [$this->source->report], []);
    }

    private function shares(): array
    {
        return [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1200],
            ['type' => 'branch_manager', 'id' => $this->manager->id, 'amount' => 800],
        ];
    }

    private function ready(): void
    {
        $guard = new DailyLiabilityGuard($this->source, $this->service, new ResponsibleActorResolver);
        DB::transaction(fn () => $guard->assertReady($this->workdayId, $this->manager));
    }

    public function test_manager_approval_preserves_employee_objection_and_requires_explicit_self_share(): void
    {
        $allocation = $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->respond($this->shiftId, $this->cashier, 1, 'objected', 'Disputed count');
        try {
            $this->service->approve($this->shiftId, $this->manager, 1);
            $this->fail('Self share must be explicitly approved.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('EXPLICIT_MANAGER_SELF_APPROVAL_REQUIRED', $e->getMessage());
        }
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $share = $allocation->shares()->where('responsible_type', 'cashier')->first();
        $this->assertSame('objected', $share->employee_response_status);
        $this->assertSame('Disputed count', $share->employee_response_reason);
        $this->assertSame('approved', $allocation->fresh()->manager_approval_status);
        $this->ready(); // Pending manager employee response and cashier objection do not block.
    }

    public function test_original_cashier_allocation_needs_confirmation(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0);
        try {
            $this->service->approve($this->shiftId, $this->manager, 1, true);
            $this->fail('Original submission needs confirmation.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('CASHIER_ALLOCATION_CONFIRMATION_REQUIRED', $e->getMessage());
        }
        $this->service->confirm($this->shiftId, $this->cashier, 1);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->ready();
        $this->assertSame(1, ShiftLiabilityAllocation::count());
    }

    public function test_manager_correction_needs_no_cashier_reconfirmation_and_preserves_old_allocation(): void
    {
        $old = $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->respond($this->shiftId, $this->cashier, 1, 'objected', 'Original objection');
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->setEvidence(-10000, 'r2');
        $new = $this->service->allocate($this->shiftId, $this->manager, [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 10000],
        ], 1, false, 'Corrected card sales 500 to 400');
        $this->assertNull($new->cashier_confirmed_at);
        $this->assertSame('pending', $new->manager_approval_status);
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame('approved', $old->fresh()->manager_approval_status);
        $this->assertSame('Original objection', $old->shares()->where('responsible_type', 'cashier')->first()->employee_response_reason);
        $this->service->approve($this->shiftId, $this->manager, 2);
        $this->ready();
    }

    public function test_changed_report_cannot_reuse_old_approval_even_if_shortage_unchanged(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->setEvidence(-2000, 'r2');
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('STALE_OR_MISSING_LIABILITY_ALLOCATION');
        $this->ready();
    }

    public function test_changed_distribution_resets_approval_and_stale_command_cannot_overwrite(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $new = $this->service->allocate($this->shiftId, $this->cashier, [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 2000],
        ], 1, true);
        $this->assertSame('pending', $new->manager_approval_status);
        $this->expectException(ConflictHttpException::class);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
    }

    public function test_cross_branch_assignee_rejected_without_writes(): void
    {
        $outsider = (string) Str::uuid();
        DB::table('cashiers')->insert(['id' => $outsider, 'branch_id' => (string) Str::uuid()]);
        try {
            $this->service->allocate($this->shiftId, $this->cashier, [['type' => 'cashier', 'id' => $outsider, 'amount' => 2000]], 0, true);
            $this->fail('Cross-branch actor accepted.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame(0, ShiftLiabilityAllocation::count());
        }
    }

    public function test_cross_company_evidence_rejected_without_writes(): void
    {
        $this->source->report = new ReportEvidence($this->shiftId, (string) Str::uuid(), $this->branchId, 'r1', -2000, true);
        $this->expectException(ConflictHttpException::class);
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
    }

    public function test_unknown_actor_type_rejected_without_writes(): void
    {
        try {
            $this->service->allocate($this->shiftId, $this->cashier, [['type' => BranchManager::class, 'id' => $this->manager->id, 'amount' => 2000]], 0, true);
            $this->fail('Client class name accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, ShiftLiabilityAllocation::count());
        }
    }

    public function test_only_assigned_employee_can_respond_and_cannot_overwrite_response(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 2000]], 0, true);
        try {
            $this->service->respond($this->shiftId, $this->manager, 1, 'accepted');
            $this->fail('Unassigned actor responded.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame('pending', ShiftLiabilityAllocation::first()->shares()->first()->employee_response_status);
        }
        $this->service->respond($this->shiftId, $this->cashier, 1, 'objected', 'Reason');
        $this->expectException(ConflictHttpException::class);
        $this->service->respond($this->shiftId, $this->cashier, 1, 'accepted');
    }

    public function test_required_transfer_blocks_daily_even_after_liability_approval(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->source->scope = new DailyCloseEvidence($this->workdayId, $this->companyId, $this->branchId, [$this->source->report], ['required-transfer' => null]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('REQUIRED_TRANSFER_RECEIPT_PENDING');
        $this->ready();
    }

    public function test_confirmed_required_receipt_allows_daily_without_unrelated_branch_requests(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->source->scope = new DailyCloseEvidence($this->workdayId, $this->companyId, $this->branchId, [$this->source->report], ['required-transfer' => 'receipt-evidence']);
        $this->ready();
        $this->assertSame(1, ShiftLiabilityAllocation::count());
    }

    public function test_daily_shortage_cannot_pass_without_manager_approval(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('LIABILITY_APPROVAL_REQUIRED');
        $this->ready();
    }

    public function test_surplus_has_no_shares_or_employee_liability(): void
    {
        $this->setEvidence(500, 'r1');
        $this->ready();
        $allocation = $this->service->allocate($this->shiftId, $this->cashier, [], 0);
        $this->assertCount(0, $allocation->shares);
        $this->ready();
        $this->assertSame(0, DB::table('shift_liability_shares')->count());
    }

    public function test_missing_source_fails_closed_without_legacy_variance_fallback(): void
    {
        $service = new ShiftLiabilityService(new UnavailableLiabilityEvidence, new ResponsibleActorResolver);
        try {
            $service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
            $this->fail('Missing evidence was accepted.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('LIABILITY_EVIDENCE_UNAVAILABLE', $e->getMessage());
            $this->assertSame(0, ShiftLiabilityAllocation::count());
        }
    }

    public function test_daily_guard_requires_submit_transaction(): void
    {
        $this->expectException(\LogicException::class);
        (new DailyLiabilityGuard($this->source, $this->service, new ResponsibleActorResolver))->assertReady($this->workdayId, $this->manager);
    }

    public function test_employee_identity_requires_its_own_company_and_branch_mapping(): void
    {
        $employeeId = (string) Str::uuid();
        DB::table('asab_employees')->insert(['id' => $employeeId, 'company_id' => (string) Str::uuid(), 'branch_id' => $this->branchId]);
        $shares = [['type' => 'employee', 'id' => $employeeId, 'amount' => 2000]];
        try {
            $this->service->allocate($this->shiftId, $this->cashier, $shares, 0, true);
            $this->fail('Cross-company employee accepted.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame(0, ShiftLiabilityAllocation::count());
        }
        DB::table('asab_employees')->where('id', $employeeId)->update(['company_id' => $this->companyId]);
        $allocation = $this->service->allocate($this->shiftId, $this->cashier, $shares, 0, true);
        $this->assertSame('employee', $allocation->shares->first()->responsible_type);
        $this->assertSame($employeeId, $allocation->shares->first()->responsible_id);
    }

    public function test_non_owner_cashier_cannot_allocate_even_in_same_branch(): void
    {
        $id = (string) Str::uuid();
        DB::table('cashiers')->insert(['id' => $id, 'branch_id' => $this->branchId]);
        $this->expectException(AccessDeniedHttpException::class);
        $this->service->allocate($this->shiftId, Cashier::findOrFail($id), $this->shares(), 0, true);
    }

    public function test_failed_reallocation_leaves_prior_approval_and_history_unchanged(): void
    {
        $old = $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        try {
            $this->service->allocate($this->shiftId, $this->cashier, [], 1, true);
            $this->fail('Incomplete allocation accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, ShiftLiabilityAllocation::count());
            $this->assertNull($old->fresh()->superseded_at);
            $this->assertSame('approved', $old->fresh()->manager_approval_status);
        }
    }

    public function test_manager_correction_requires_reason(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->allocate($this->shiftId, $this->manager, $this->shares(), 0);
    }

    public function test_objection_requires_reason(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->expectException(ValidationException::class);
        $this->service->respond($this->shiftId, $this->cashier, 1, 'objected', '  ');
    }

    public function test_cashier_cannot_give_final_manager_approval(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->expectException(AccessDeniedHttpException::class);
        $this->service->approve($this->shiftId, $this->cashier, 1);
    }

    public function test_changed_shortage_cannot_reuse_approval_even_with_same_revision_token(): void
    {
        $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->setEvidence(-3000, 'r1');
        $this->expectException(ConflictHttpException::class);
        $this->ready();
    }

    public function test_corrected_balanced_or_surplus_report_ignores_but_preserves_historical_shortage_allocation(): void
    {
        $allocation = $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->respond($this->shiftId, $this->cashier, 1, 'objected', 'Historical objection');
        $this->service->approve($this->shiftId, $this->manager, 1, true);

        foreach ([0, 500] as $index => $variance) {
            $this->setEvidence($variance, 'r'.($index + 2));
            $this->ready();

            $this->assertSame(1, ShiftLiabilityAllocation::count());
            $this->assertNull($allocation->fresh()->superseded_at);
            $this->assertSame('approved', $allocation->fresh()->manager_approval_status);
            $this->assertSame('objected', $allocation->shares()->where('responsible_type', 'cashier')->first()->employee_response_status);
            $this->assertSame('Historical objection', $allocation->shares()->where('responsible_type', 'cashier')->first()->employee_response_reason);
        }
    }

    public function test_surplus_then_new_shortage_requires_fresh_allocation_and_approval(): void
    {
        $historical = $this->service->allocate($this->shiftId, $this->cashier, $this->shares(), 0, true);
        $this->service->approve($this->shiftId, $this->manager, 1, true);
        $this->setEvidence(500, 'r2');
        $this->ready();

        $this->setEvidence(-1000, 'r3');
        try {
            $this->ready();
            $this->fail('Historical shortage allocation must not satisfy a later shortage.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('STALE_OR_MISSING_LIABILITY_ALLOCATION', $e->getMessage());
        }

        $current = $this->service->allocate($this->shiftId, $this->manager, [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000],
        ], 1, false, 'Corrected count establishes new shortage');
        $this->assertSame('pending', $current->manager_approval_status);
        $this->assertNotNull($historical->fresh()->superseded_at);
        $this->assertSame('approved', $historical->fresh()->manager_approval_status);
        $this->assertNull($current->cashier_confirmed_at);

        try {
            $this->ready();
            $this->fail('Fresh shortage must require a fresh manager approval.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('LIABILITY_APPROVAL_REQUIRED', $e->getMessage());
        }

        $this->service->approve($this->shiftId, $this->manager, 2);
        $this->ready();
    }

    public function test_missing_daily_evidence_is_not_treated_as_empty_completed_scope(): void
    {
        $guard = new DailyLiabilityGuard(new UnavailableLiabilityEvidence, $this->service, new ResponsibleActorResolver);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('DAILY_SCOPE_EVIDENCE_UNAVAILABLE');
        DB::transaction(fn () => $guard->assertReady($this->workdayId, $this->manager));
    }

    public function test_incomplete_report_and_wrong_daily_scope_are_rejected(): void
    {
        $this->source->scope = new DailyCloseEvidence($this->workdayId, $this->companyId, (string) Str::uuid(), [], []);
        try {
            $this->ready();
            $this->fail('Wrong scope accepted.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame(0, ShiftLiabilityAllocation::count());
        }
        $this->source->report = new ReportEvidence($this->shiftId, $this->companyId, $this->branchId, 'r1', 0, false);
        $this->source->scope = new DailyCloseEvidence($this->workdayId, $this->companyId, $this->branchId, [$this->source->report], []);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('REPORT_NOT_COMPLETED');
        $this->ready();
    }

    public function test_additive_migration_rolls_back_without_dropping_legacy_tables(): void
    {
        (require base_path('Modules/Shift/database/migrations/2026_10_08_000001_create_shift_liability_allocations.php'))->down();
        $this->assertFalse(Schema::hasTable('shift_liability_allocations'));
        $this->assertFalse(Schema::hasTable('shift_liability_shares'));
        $this->assertTrue(Schema::hasTable('cashier_shifts'));
    }
}

final class TestLiabilityEvidence implements LiabilityEvidenceSource
{
    public ReportEvidence $report;

    public DailyCloseEvidence $scope;

    public function report(string $cashierShiftId): ReportEvidence
    {
        return $this->report;
    }

    public function dailyClose(string $managerWorkdayId): DailyCloseEvidence
    {
        return $this->scope;
    }
}
