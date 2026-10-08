<?php

namespace Modules\Shift\Liability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal S1-07 commands. HTTP/source adapters are deliberately deferred to S1-10/S1-11. */
final class ShiftLiabilityService
{
    public function __construct(
        private LiabilityEvidenceSource $evidence,
        private ResponsibleActorResolver $actors,
    ) {}

    public function allocate(string $shiftId, Model $actor, array $shares, int $expectedVersion, bool $cashierConfirmed = false, ?string $reason = null): ShiftLiabilityAllocation
    {
        return DB::transaction(function () use ($shiftId, $actor, $shares, $expectedVersion, $cashierConfirmed, $reason) {
            [$shift, $report] = $this->context($shiftId);
            $identity = $this->actors->identity($actor, $report->companyId, $report->branchId);
            $managerCorrection = $identity['type'] === 'branch_manager';
            if (! $managerCorrection && ($identity['type'] !== 'cashier' || $identity['id'] !== $shift->cashier_id)) {
                throw new AccessDeniedHttpException('ONLY_OWNER_OR_BRANCH_MANAGER');
            }
            if ($managerCorrection && trim($reason ?? '') === '') {
                throw ValidationException::withMessages(['reason' => 'Manager correction requires a reason.']);
            }
            $previous = $this->latest($shiftId);
            if (($previous?->version ?? 0) !== $expectedVersion) {
                throw new ConflictHttpException('STALE_ALLOCATION');
            }
            try {
                $shares = AllocationRules::validate($report->varianceHalalas, $shares);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['allocations' => $e->getMessage()]);
            }
            foreach ($shares as $share) {
                $this->actors->resolve($share['type'], $share['id'], $report->companyId, $report->branchId);
            }
            // Supersede instead of deleting: previous approval/objection evidence remains intact.
            $previous?->update(['superseded_at' => now()]);
            $allocation = ShiftLiabilityAllocation::create([
                'cashier_shift_id' => $shiftId,
                'company_id' => $report->companyId,
                'branch_id' => $report->branchId,
                'version' => $expectedVersion + 1,
                'report_revision' => $report->revision,
                'variance_halalas' => $report->varianceHalalas,
                'created_by_type' => $identity['type'],
                'created_by_id' => $identity['id'],
                'reason' => $reason,
                'cashier_confirmed_at' => ! $managerCorrection && $cashierConfirmed ? now() : null,
            ]);
            foreach ($shares as $share) {
                $allocation->shares()->create([
                    'responsible_type' => $share['type'],
                    'responsible_id' => $share['id'],
                    'amount_halalas' => $share['amount'],
                ]);
            }

            return $allocation->load('shares');
        });
    }

    public function confirm(string $shiftId, Model $cashier, int $version): void
    {
        DB::transaction(function () use ($shiftId, $cashier, $version) {
            [$shift, $report] = $this->context($shiftId);
            $identity = $this->actors->identity($cashier, $report->companyId, $report->branchId);
            if ($identity['type'] !== 'cashier' || $identity['id'] !== $shift->cashier_id) {
                throw new AccessDeniedHttpException('ONLY_REPORT_OWNER');
            }
            $allocation = $this->current($report, $version);
            if ($allocation->cashier_confirmed_at === null) {
                $allocation->update(['cashier_confirmed_at' => now()]);
            }
        });
    }

    public function respond(string $shiftId, Model $actor, int $version, string $response, ?string $reason = null): void
    {
        if (! in_array($response, ['accepted', 'objected'], true) || ($response === 'objected' && trim($reason ?? '') === '')) {
            throw ValidationException::withMessages(['response' => 'Accept or object; an objection requires a reason.']);
        }
        DB::transaction(function () use ($shiftId, $actor, $version, $response, $reason) {
            [, $report] = $this->context($shiftId);
            $identity = $this->actors->identity($actor, $report->companyId, $report->branchId);
            $allocation = $this->current($report, $version);
            $share = $allocation->shares()->where('responsible_type', $identity['type'])->where('responsible_id', $identity['id'])->first();
            if (! $share) {
                throw new AccessDeniedHttpException('ONLY_ASSIGNED_EMPLOYEE');
            }
            if ($share->employee_response_status !== 'pending') {
                throw new ConflictHttpException('EMPLOYEE_RESPONSE_ALREADY_RECORDED');
            }
            $share->update([
                'employee_response_status' => $response,
                'employee_response_reason' => $reason,
                'employee_responded_at' => now(),
            ]);
        });
    }

    public function approve(string $shiftId, Model $manager, int $version, bool $explicitSelfShare = false): void
    {
        DB::transaction(function () use ($shiftId, $manager, $version, $explicitSelfShare) {
            [, $report] = $this->context($shiftId);
            $identity = $this->actors->identity($manager, $report->companyId, $report->branchId);
            if ($identity['type'] !== 'branch_manager') {
                throw new AccessDeniedHttpException('ONLY_BRANCH_MANAGER');
            }
            $allocation = $this->current($report, $version);
            $this->assertComplete($allocation);
            if ($allocation->variance_halalas >= 0) {
                throw new ConflictHttpException('NO_SHORTAGE_LIABILITY');
            }
            if ($allocation->created_by_type === 'cashier' && $allocation->cashier_confirmed_at === null) {
                throw new ConflictHttpException('CASHIER_ALLOCATION_CONFIRMATION_REQUIRED');
            }
            if (! $explicitSelfShare && $allocation->shares()->where('responsible_type', 'branch_manager')->where('responsible_id', $identity['id'])->exists()) {
                throw new ConflictHttpException('EXPLICIT_MANAGER_SELF_APPROVAL_REQUIRED');
            }
            if ($allocation->manager_approval_status !== 'pending') {
                throw new ConflictHttpException('MANAGER_DECISION_ALREADY_RECORDED');
            }
            $allocation->update([
                'manager_approval_status' => 'approved',
                'manager_approved_by' => $identity['id'],
                'manager_approved_at' => now(),
            ]);
            // No response overwrite, ledger event, cash movement, or payroll deduction.
        });
    }

    /** Call inside the daily-submit transaction after the evidence source has locked membership. */
    public function assertDailyReportReady(ReportEvidence $report): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Liability readiness requires the submit transaction.');
        }
        [, $currentReport] = $this->context($report->shiftId);
        if ($currentReport != $report) {
            throw new ConflictHttpException('STALE_DAILY_REPORT');
        }
        if (! $report->completed) {
            throw new ConflictHttpException('REPORT_NOT_COMPLETED');
        }
        // Zero/surplus is branch-only, but a previous shortage snapshot must not remain current.
        $allocation = $this->latest($report->shiftId);
        if ($report->varianceHalalas >= 0 && ! $allocation) {
            return;
        }
        $allocation = $this->current($report, $allocation?->version ?? 0);
        $this->assertComplete($allocation);
        if ($report->varianceHalalas < 0 && (
            $allocation->manager_approval_status !== 'approved'
            || $allocation->manager_approved_by === null || $allocation->manager_approved_at === null
            || ($allocation->created_by_type === 'cashier' && $allocation->cashier_confirmed_at === null)
        )) {
            throw new ConflictHttpException('LIABILITY_APPROVAL_REQUIRED');
        }
    }

    private function context(string $shiftId): array
    {
        $shift = CashierShift::without(['cashier', 'shift', 'nextCashier'])->whereKey($shiftId)->lockForUpdate()->firstOrFail();
        $branch = DB::table('shifts')->join('branches', 'branches.id', '=', 'shifts.branch_id')
            ->where('shifts.id', $shift->shift_id)->select('branches.id', 'branches.asab_company_id')->first();
        if (! $branch || ! $branch->asab_company_id) {
            throw new ConflictHttpException('LIABILITY_COMPANY_MAPPING_REQUIRED');
        }
        $report = $this->evidence->report($shiftId);
        if ($report->shiftId !== $shiftId || $report->branchId !== $branch->id || $report->companyId !== $branch->asab_company_id || trim($report->revision) === '') {
            throw new ConflictHttpException('LIABILITY_EVIDENCE_SCOPE_MISMATCH');
        }

        return [$shift, $report];
    }

    private function latest(string $shiftId): ?ShiftLiabilityAllocation
    {
        return ShiftLiabilityAllocation::where('cashier_shift_id', $shiftId)->orderByDesc('version')->lockForUpdate()->first();
    }

    private function current(ReportEvidence $report, int $version): ShiftLiabilityAllocation
    {
        $allocation = $this->latest($report->shiftId);
        if (! $allocation || $allocation->version !== $version || $allocation->superseded_at !== null
            || $allocation->report_revision !== $report->revision || $allocation->variance_halalas !== $report->varianceHalalas
            || $allocation->branch_id !== $report->branchId || $allocation->company_id !== $report->companyId) {
            throw new ConflictHttpException('STALE_OR_MISSING_LIABILITY_ALLOCATION');
        }

        return $allocation;
    }

    private function assertComplete(ShiftLiabilityAllocation $allocation): void
    {
        try {
            AllocationRules::validate($allocation->variance_halalas, $allocation->shares->map(fn ($share) => [
                'type' => $share->responsible_type, 'id' => $share->responsible_id, 'amount' => $share->amount_halalas,
            ])->all());
        } catch (InvalidArgumentException $e) {
            throw new ConflictHttpException('ALLOCATION_INCOMPLETE', $e);
        }
        foreach ($allocation->shares as $share) {
            $this->actors->resolve($share->responsible_type, $share->responsible_id, $allocation->company_id, $allocation->branch_id);
        }
    }
}
