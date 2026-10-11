<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use App\Support\ShiftMoneyValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AsabUser;
use Modules\Shift\Liability\AllocationRules;
use Modules\Shift\Liability\ResponsibleActorResolver;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountantReportCorrectionService
{
    public function __construct(
        private ShiftReportRevisionService $revisions,
        private ShiftReportRevisionSnapshotService $snapshots,
        private ShiftCashCountService $counts,
        private ShiftReportMutationGuard $guard,
        private ShiftReportBalanceCorrectionService $balanceService,
        private ShiftReportManagerReviewService $reviewService,
        private ResponsibleActorResolver $actorResolver,
    ) {}

    public function correct(
        CashierShift $source,
        AsabUser $actor,
        array $changes,
        ?array $shares,
        int $expectedRevision,
        ?int $expectedAllocationVersion,
        string $reason,
        string $operationId
    ): ShiftReportRevision {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Correction reason cannot be empty.',
            ]);
        }

        $operationId = trim($operationId);
        if ($operationId === '' || strlen($operationId) > 100) {
            throw ValidationException::withMessages([
                'operation_id' => 'Operation ID cannot be empty or exceed 100 characters.',
            ]);
        }

        // Strict gross sales guard: reject total_sales, gross_sales, sales, sales_amount
        $forbiddenSalesKeys = ['total_sales', 'gross_sales', 'sales', 'sales_amount'];
        foreach ($forbiddenSalesKeys as $key) {
            if (array_key_exists($key, $changes)) {
                throw ValidationException::withMessages([
                    'changes' => 'GROSS_SALES_MODIFICATION_FORBIDDEN',
                ]);
            }
        }

        $allowedKeys = ['card_payments', 'cash_collected', 'handover_notes', 'aggregators'];
        $unsupported = array_diff(array_keys($changes), $allowedKeys);
        if (! empty($unsupported)) {
            throw ValidationException::withMessages([
                'changes' => 'Unsupported correction field.',
            ]);
        }

        Validator::make($changes, [
            'card_payments' => 'sometimes|required|'.ShiftMoneyValidation::SAR,
            'cash_collected' => 'sometimes|required|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'sometimes|nullable|string',
            'aggregators' => 'sometimes|required|array',
            'aggregators.*' => 'required|array:aggregator_id,amount,notes',
            'aggregators.*.aggregator_id' => 'required|uuid',
            'aggregators.*.amount' => 'required|'.ShiftMoneyValidation::SAR,
            'aggregators.*.notes' => 'nullable|string',
        ])->validate();

        return DB::transaction(function () use (
            $source,
            $actor,
            $changes,
            $shares,
            $expectedRevision,
            $expectedAllocationVersion,
            $reason,
            $operationId
        ) {
            $shift = $this->guard->lockCorrection($source, $actor);
            $branchId = $shift->shift()->value('branch_id') ?? $shift->branch_id;
            $companyId = $shift->shift?->branch?->asab_company_id;

            /** @var ShiftReportAggregate $aggregate */
            $aggregate = ShiftReportAggregate::query()
                ->where('source_type', 'cashier_shift')
                ->where('source_id', $shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($aggregate->current_revision_number !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }

            $previousRevision = ShiftReportRevision::query()
                ->where('report_aggregate_id', $aggregate->id)
                ->where('revision_number', $aggregate->current_revision_number)
                ->lockForUpdate()
                ->first();

            // Snapshot before
            if ($previousRevision) {
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            $sourceCount = $previousRevision
                ? ShiftReportCashCount::where('report_revision_id', $previousRevision->id)->lockForUpdate()->first()
                : null;

            // Prepare monetary figures
            $grossHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->total_sales ?? '0.00');
            $oldCardsHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->card_payments ?? '0.00');
            $oldCashCollectedHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->cash_collected ?? '0.00');

            $newCardsHalalas = array_key_exists('card_payments', $changes)
                ? ShiftFinancialCalculator::sarToHalalas($changes['card_payments'])
                : $oldCardsHalalas;

            $newCashCollectedHalalas = array_key_exists('cash_collected', $changes)
                ? ShiftFinancialCalculator::sarToHalalas($changes['cash_collected'])
                : $oldCashCollectedHalalas;

            $cardsChanged = $newCardsHalalas !== $oldCardsHalalas;
            $cashCollectedChanged = $newCashCollectedHalalas !== $oldCashCollectedHalalas;

            // Aggregators breakdown
            $rawAggs = $changes['aggregators'] ?? null;
            $aggsChanged = $rawAggs !== null;
            $tempAppsHalalas = 0;
            $formattedAggs = [];

            if ($aggsChanged) {
                $seenAggregatorIds = [];
                foreach ($rawAggs as $agg) {
                    $aggId = (string) ($agg['aggregator_id'] ?? '');
                    if ($aggId === '' || isset($seenAggregatorIds[$aggId])) {
                        throw ValidationException::withMessages([
                            'aggregators' => 'Duplicate or invalid aggregator_id.',
                        ]);
                    }
                    $seenAggregatorIds[$aggId] = true;
                    if (! \Modules\Aggregator\Models\Aggregator::query()->active()->whereKey($aggId)
                        ->whereHas('enabledBranches', fn ($query) => $query->where('branches.id', $branchId))->exists()) {
                        throw ValidationException::withMessages([
                            'aggregators' => 'Aggregator must be active and enabled for this branch.',
                        ]);
                    }
                    $itemAmountHalalas = ShiftFinancialCalculator::sarToHalalas($agg['amount'] ?? 0);
                    $tempAppsHalalas += $itemAmountHalalas;
                    $formattedAggs[] = [
                        'aggregator_id' => $aggId,
                        'amount' => number_format($itemAmountHalalas / 100, 2, '.', ''),
                        'notes' => $agg['notes'] ?? null,
                    ];
                }
                $newAppsHalalas = $tempAppsHalalas;
            } else {
                $existingAggregatorsSum = ShiftSalesBreakdown::query()
                    ->where('cashier_shift_id', $shift->id)
                    ->sum('amount');
                $newAppsHalalas = ShiftFinancialCalculator::storedSarToHalalas($existingAggregatorsSum ?: '0.00');
            }

            $openingHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->shift?->opening_float ?? $shift->opening_balance ?? '0.00');

            $calc = ShiftFinancialCalculator::calculate(
                $grossHalalas,
                $newCardsHalalas,
                $newAppsHalalas,
                $openingHalalas,
                $grossHalalas,
                0
            );

            $netHalalas = $calc['net'];
            $vatHalalas = $calc['vat'];
            $newNetSar = number_format($netHalalas / 100, 2, '.', '');
            $newVatSar = number_format($vatHalalas / 100, 2, '.', '');
            $newCardsSar = number_format($newCardsHalalas / 100, 2, '.', '');
            $newCashCollectedSar = number_format($newCashCollectedHalalas / 100, 2, '.', '');

            // Reconcile balance entry if cash_collected changed
            if ($cashCollectedChanged) {
                $this->balanceService->reconcileCashCollected(
                    $shift,
                    $oldCashCollectedHalalas,
                    $newCashCollectedHalalas,
                    $operationId
                );
            }

            // Advance report revision
            $newRevision = $this->revisions->recordCashierRevision($shift, 'accountant', $actor->id, $expectedRevision);

            // Re-calculate count evidence if prior count exists
            $newVarianceHalalas = 0;
            $newVarianceSar = '0.00';
            if ($sourceCount !== null) {
                $newCount = $this->counts->recordCorrectedEvidence(
                    $shift,
                    $newRevision,
                    $sourceCount,
                    [
                        'gross_halalas' => $grossHalalas,
                        'cards_halalas' => $newCardsHalalas,
                        'apps_halalas' => $newAppsHalalas,
                    ]
                );
                $newVarianceHalalas = $newCount->variance_halalas;
                $newVarianceSar = number_format($newVarianceHalalas / 100, 2, '.', '');
            }

            // Update shift model
            $oldHandoverNotes = $shift->handover_notes;
            $newHandoverNotes = array_key_exists('handover_notes', $changes) ? $changes['handover_notes'] : $oldHandoverNotes;
            $notesChanged = $newHandoverNotes !== $oldHandoverNotes;

            $shift->update([
                'card_payments' => $newCardsSar,
                'cash_collected' => $newCashCollectedSar,
                'closing_balance' => $newCashCollectedSar,
                'net_sales' => $newNetSar,
                'vat_amount' => $newVatSar,
                'variance' => $newVarianceSar,
                'handover_notes' => $newHandoverNotes,
            ]);

            if ($aggsChanged) {
                ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->delete();
                foreach ($formattedAggs as $formatted) {
                    ShiftSalesBreakdown::create([
                        'cashier_shift_id' => $shift->id,
                        'aggregator_id' => $formatted['aggregator_id'],
                        'amount' => $formatted['amount'],
                        'notes' => $formatted['notes'],
                    ]);
                }
            }

            // Handle existing allocation
            /** @var ShiftLiabilityAllocation|null $existingAlloc */
            $existingAlloc = ShiftLiabilityAllocation::query()
                ->where('cashier_shift_id', $shift->id)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();

            if ($existingAlloc) {
                $existingAlloc->load('shares');
            }

            $createdAlloc = null;

            $latestAllocationVersion = (int) ShiftLiabilityAllocation::query()
                ->where('cashier_shift_id', $shift->id)
                ->lockForUpdate()
                ->max('version');
            $latestAllocation = ShiftLiabilityAllocation::query()
                ->where('cashier_shift_id', $shift->id)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if ($expectedAllocationVersion !== null && $latestAllocationVersion !== $expectedAllocationVersion) {
                throw new ConflictHttpException('STALE_ALLOCATION_VERSION');
            }

            if ($newVarianceHalalas < 0) {
                if ($shares !== null) {
                    $candidateShares = $shares;
                } elseif ($existingAlloc) {
                    $candidateShares = $existingAlloc->shares->map(fn ($share) => [
                        'type' => $share->responsible_type,
                        'id' => (string) $share->responsible_id,
                        'amount' => (int) $share->amount_halalas,
                    ])->all();
                } else {
                    throw new ConflictHttpException('LIABILITY_ALLOCATION_REQUIRED');
                }

                try {
                    $validatedShares = AllocationRules::validate($newVarianceHalalas, $candidateShares);
                } catch (\InvalidArgumentException $exception) {
                    throw new ConflictHttpException('LIABILITY_ALLOCATION_REQUIRED', $exception);
                }
                foreach ($validatedShares as $share) {
                    $this->actorResolver->resolve($share['type'], $share['id'], $companyId, $branchId);
                }

                $newAlloc = ShiftLiabilityAllocation::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'cashier_shift_id' => $shift->id,
                    'version' => $latestAllocationVersion + 1,
                    'report_revision' => (string) $newRevision->revision_number,
                    'variance_halalas' => $newVarianceHalalas,
                    'created_by_type' => 'accountant',
                    'created_by_id' => $actor->id,
                    'reason' => $reason,
                    'manager_approval_status' => 'pending',
                    'supersedes_allocation_id' => $latestAllocation?->id,
                ]);

                foreach ($validatedShares as $share) {
                    $newAlloc->shares()->create([
                        'responsible_type' => $share['type'],
                        'responsible_id' => $share['id'],
                        'amount_halalas' => $share['amount'],
                        'employee_response_status' => 'pending',
                    ]);
                }
                $createdAlloc = $newAlloc->load('shares');
                $existingAlloc?->update(['superseded_at' => now()]);
            } else {
                $existingAlloc?->update(['superseded_at' => now()]);
            }

            // Require manager review
            $this->reviewService->requireFor($shift, $newRevision, $newVarianceHalalas, $reason);

            // Record fine-grained immutable audit rows
            $recordCorrection = function (
                string $field,
                string $type,
                ?string $oldVal,
                ?string $newVal,
                ?int $oldH,
                ?int $newH
            ) use ($aggregate, $previousRevision, $newRevision, $reason, $actor, $companyId, $branchId, $operationId) {
                ShiftReportCorrection::create([
                    'report_aggregate_id' => $aggregate->id,
                    'previous_revision_id' => $previousRevision?->id,
                    'new_revision_id' => $newRevision->id,
                    'previous_revision_number' => $previousRevision?->revision_number,
                    'new_revision_number' => $newRevision->revision_number,
                    'field_name' => $field,
                    'field_type' => $type,
                    'old_value' => $oldVal,
                    'new_value' => $newVal,
                    'old_halalas' => $oldH,
                    'new_halalas' => $newH,
                    'reason' => $reason,
                    'actor_type' => 'accountant',
                    'actor_id' => $actor->id,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'operation_id' => $operationId,
                ]);
            };

            if ($cardsChanged) {
                $recordCorrection('card_payments', 'monetary_halalas', number_format($oldCardsHalalas / 100, 2, '.', ''), $newCardsSar, $oldCardsHalalas, $newCardsHalalas);
            }
            if ($cashCollectedChanged) {
                $recordCorrection('cash_collected', 'monetary_halalas', number_format($oldCashCollectedHalalas / 100, 2, '.', ''), $newCashCollectedSar, $oldCashCollectedHalalas, $newCashCollectedHalalas);
            }
            if ($notesChanged) {
                $recordCorrection('handover_notes', 'string', $oldHandoverNotes, $newHandoverNotes, null, null);
            }
            if ($shares !== null) {
                $recordCorrection('shortage_allocations', 'json', null, json_encode($shares), null, null);
            }

            // Enqueue outbox notifications
            app(ShiftReportCorrectionNotificationService::class)->enqueue(
                $newRevision,
                $shift,
                $previousRevision,
                $existingAlloc,
                $createdAlloc,
                $reason
            );

            // Snapshot after
            $this->snapshots->createSnapshotIfMissing($newRevision, $shift);

            // Invalidate cache
            app(ShiftReportCacheInvalidator::class)->cashierReport($shift);

            return $newRevision;
        }, 3);
    }
}
