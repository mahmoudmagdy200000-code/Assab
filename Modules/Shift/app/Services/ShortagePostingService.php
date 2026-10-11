<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftLiabilityPostingState;
use Modules\Shift\Models\ShiftLiabilityShare;
use Modules\Shift\Models\ShiftReportManagerReview;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShortagePostingService
{
    public function __construct(
        private ShiftReportRevisionService $revisions,
        private ShiftLiabilityLegacyPostingLinkService $legacyLinks,
    ) {}

    /**
     * Reconcile liability posting for a cashier shift to its current approved target.
     * Computes signed delta = approved_target - effective_already_posted.
     * Additive and reversing entries only; originals are never deleted.
     *
     * @return array{cashier_shift_id: string, postings: array, total_deltas: int}
     */
    public function reconcile(
        CashierShift $source,
        Model $actor,
        int $expectedRevision,
        ?int $expectedAllocationVersion,
        string $operationId
    ): array {
        return DB::transaction(function () use ($source, $actor, $expectedRevision, $expectedAllocationVersion, $operationId) {
            // 1. Lock the source shift
            $shift = CashierShift::withoutEagerLoads()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Validate current revision
            $currentRevision = $this->revisions->currentCashierRevision($shift);
            if (($currentRevision?->revision_number ?? 0) !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            // 3. Resolve active (non-superseded) liability allocation
            $activeAllocation = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();

            if ($expectedAllocationVersion !== null) {
                if (($activeAllocation?->version) !== $expectedAllocationVersion) {
                    throw new ConflictHttpException('STALE_ALLOCATION_VERSION');
                }
            }

            // If active allocation exists, it must be approved by the branch manager
            if ($activeAllocation && $activeAllocation->manager_approval_status !== 'approved') {
                throw new ConflictHttpException('BRANCH_LIABILITY_APPROVAL_PENDING');
            }

            // Resolve company_id and branch_id
            $branchId = $shift->shift?->branch_id ?? DB::table('shifts')->where('id', $shift->shift_id)->value('branch_id');
            $companyId = DB::table('branches')->where('id', $branchId)->value('asab_company_id')
                ?? DB::table('branches')->where('id', $branchId)->value('company_id')
                ?? $shift->company_id;

            // Establish exact provenance for legacy variance rows before computing
            // the first modern effective balance. Unprovable rows fail closed.
            $this->legacyLinks->reconcileShiftLegacyEvidence($shift);

            // 4. Build union of all current and previously-posted stakeholders
            $targets = []; // [lineage_key => ['type' => string, 'id' => string, 'target_halalas' => int]]

            // From current approved allocation
            if ($activeAllocation) {
                $shares = $activeAllocation->shares()->orderBy('id')->lockForUpdate()->get();
                foreach ($shares as $share) {
                    $key = ShiftLiabilityShare::buildLineageKey($shift->id, $share->responsible_type, $share->responsible_id);
                    $targets[$key] = [
                        'type' => $share->responsible_type,
                        'id' => (string) $share->responsible_id,
                        'target_halalas' => (int) $share->amount_halalas,
                    ];
                }
            }

            // From existing posting states
            $existingStates = ShiftLiabilityPostingState::where('cashier_shift_id', $shift->id)
                ->orderBy('lineage_key')->lockForUpdate()->get();
            foreach ($existingStates as $state) {
                if (! isset($targets[$state->lineage_key])) {
                    $targets[$state->lineage_key] = [
                        'type' => $state->responsible_type,
                        'id' => (string) $state->responsible_id,
                        'target_halalas' => 0, // removed from allocation or zeroed out
                    ];
                }
            }

            // From existing legacy links
            $legacyLinks = DB::table('shift_liability_legacy_posting_links')
                ->where('cashier_shift_id', $shift->id)
                ->orderBy('lineage_key')->lockForUpdate()
                ->get();
            foreach ($legacyLinks as $legacy) {
                if (! isset($targets[$legacy->lineage_key])) {
                    $targets[$legacy->lineage_key] = [
                        'type' => $legacy->responsible_type,
                        'id' => (string) $legacy->responsible_id,
                        'target_halalas' => 0,
                    ];
                }
            }

            // 5. Canonical lock ordering: sort lineage keys alphabetically to avoid deadlocks
            ksort($targets);

            $postings = [];
            $review = ShiftReportManagerReview::where('cashier_shift_id', $shift->id)
                ->where('report_revision_id', $currentRevision?->id)
                ->first();

            foreach ($targets as $lineageKey => $stakeholder) {
                // Ensure posting state exists and is locked
                $postingState = ShiftLiabilityPostingState::where('lineage_key', $lineageKey)
                    ->lockForUpdate()->first();
                if ($postingState === null) {
                    $postingState = ShiftLiabilityPostingState::create([
                        'lineage_key' => $lineageKey,
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'cashier_shift_id' => $shift->id,
                        'responsible_type' => $stakeholder['type'],
                        'responsible_id' => $stakeholder['id'],
                        'last_approved_target_halalas' => 0,
                    ]);
                }

                $postingState = ShiftLiabilityPostingState::whereKey($postingState->id)
                    ->lockForUpdate()
                    ->first();

                $targetHalalas = $stakeholder['target_halalas'];
                $effectiveBefore = $this->legacyLinks->resolveEffectivePostedHalalas($lineageKey);
                $deltaHalalas = $targetHalalas - $effectiveBefore;

                if ($deltaHalalas === 0) {
                    $postingState->update([
                        'last_approved_target_halalas' => $targetHalalas,
                        'last_evidence_id' => $currentRevision?->id,
                    ]);

                    continue;
                }

                $postingId = (string) Str::uuid();
                [$ledgerTable, $ledgerId] = $this->postToLedgerAdapter(
                    $stakeholder['type'],
                    $stakeholder['id'],
                    $deltaHalalas,
                    $shift,
                    $lineageKey,
                    $postingId,
                    $operationId
                );

                $posting = ShiftLiabilityPosting::create([
                    'id' => $postingId,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'cashier_shift_id' => $shift->id,
                    'lineage_key' => $lineageKey,
                    'responsible_type' => $stakeholder['type'],
                    'responsible_id' => $stakeholder['id'],
                    'delta_halalas' => $deltaHalalas,
                    'target_halalas' => $targetHalalas,
                    'effective_before_halalas' => $effectiveBefore,
                    'allocation_id' => $activeAllocation?->id,
                    'review_id' => $review?->id,
                    'evidence_id' => $currentRevision?->id,
                    'operation_id' => $operationId,
                    'actor_type' => $actor->getMorphClass() ?? class_basename($actor),
                    'actor_id' => $actor->id,
                    'ledger_table' => $ledgerTable,
                    'ledger_id' => $ledgerId,
                    'status' => 'posted',
                ]);

                $postingState->update([
                    'last_approved_target_halalas' => $targetHalalas,
                    'last_evidence_id' => $currentRevision?->id,
                ]);

                $postings[] = $posting;
            }

            return [
                'cashier_shift_id' => $shift->id,
                'postings' => $postings,
                'total_deltas' => count($postings),
            ];
        });
    }

    /**
     * Post additive or reversing entries to the single authoritative ledger for this actor type.
     *
     * @return array{0: string, 1: string} [ledger_table, ledger_id]
     */
    private function postToLedgerAdapter(
        string $responsibleType,
        string $responsibleId,
        int $deltaHalalas,
        CashierShift $shift,
        string $lineageKey,
        string $postingId,
        string $operationId
    ): array {
        if ($responsibleType === 'employee') {
            $movementType = $deltaHalalas > 0 ? 'debit' : 'credit';
            $amount = abs($deltaHalalas);
            $description = $deltaHalalas > 0 ? 'خصم فرق كاش' : 'رد فرق كاش';
            $ref = 'CSH-'.strtoupper(Str::random(6));

            $movement = EmployeeMovement::create([
                'employee_id' => $responsibleId,
                'movement_type' => $movementType,
                'amount' => $amount,
                'category' => 'cash_variance',
                'description' => $description,
                'ref' => $ref,
                'ref_operation_id' => $operationId,
                'movement_date' => now(),
                'lineage_key' => $lineageKey,
                'posting_id' => $postingId,
            ]);

            return ['asab_employee_movements', $movement->id];
        }

        if ($responsibleType === 'cashier') {
            $isCashIn = $deltaHalalas < 0; // Negative delta restores balance (cash in); positive charges (cash out)
            $amount = round(abs($deltaHalalas) / 100, 2);

            $txn = CashierCustodyTransaction::create([
                'cashier_id' => $responsibleId,
                'transaction_type' => 'Variance',
                'amount' => $amount,
                'is_cash_in' => $isCashIn,
                'category' => 'cash_variance',
                'related_shift_id' => $shift->id,
                'transaction_date' => now(),
                'lineage_key' => $lineageKey,
                'posting_id' => $postingId,
            ]);

            return ['cashier_custody_transactions', $txn->id];
        }

        if ($responsibleType === 'branch_manager') {
            $isCashIn = $deltaHalalas < 0;
            $amount = round(abs($deltaHalalas) / 100, 2);

            $txn = PersonalLedgerTransaction::create([
                'branch_manager_id' => $responsibleId,
                'transaction_type' => 'Manager Variance',
                'amount' => $amount,
                'is_cash_in' => $isCashIn,
                'category' => 'cash_variance',
                'related_shift_id' => $shift->id,
                'transaction_date' => now(),
                'lineage_key' => $lineageKey,
                'posting_id' => $postingId,
            ]);

            return ['personal_ledger_transactions', $txn->id];
        }

        throw new \InvalidArgumentException("Unsupported responsible type: {$responsibleType}");
    }
}
