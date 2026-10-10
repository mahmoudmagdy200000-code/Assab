<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use App\Support\ShiftMoneyValidation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * S1-11 Task 3.2: Single authorized internal correction service for cashier shift reports.
 */
class ShiftReportCorrectionService
{
    public function __construct(
        private ShiftReportRevisionService $revisions,
        private ShiftReportRevisionSnapshotService $snapshots,
        private ShiftCashCountService $counts,
    ) {}

    /**
     * Corrects a cashier shift report, advancing the revision and recording fine-grained immutable audit rows.
     */
    public function correctCashierReport(
        CashierShift $source,
        Model $actor,
        array $changes,
        int $expectedRevision,
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
                'operation_id' => 'Operation ID cannot be empty.',
            ]);
        }
        $allowed = ['total_sales', 'card_payments', 'cash_collected', 'handover_notes', 'aggregators'];
        if (array_diff(array_keys($changes), $allowed)) {
            throw ValidationException::withMessages(['changes' => 'Unsupported correction field.']);
        }
        Validator::make($changes, [
            'total_sales' => 'sometimes|required|'.ShiftMoneyValidation::SAR,
            'card_payments' => 'sometimes|required|'.ShiftMoneyValidation::SAR,
            'cash_collected' => 'sometimes|required|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'sometimes|nullable|string',
            'aggregators' => 'sometimes|required|array',
            'aggregators.*' => 'required|array:aggregator_id,amount,notes',
            'aggregators.*.aggregator_id' => 'required|uuid',
            'aggregators.*.amount' => 'required|'.ShiftMoneyValidation::SAR,
            'aggregators.*.notes' => 'nullable|string',
        ])->validate();

        return DB::transaction(function () use ($source, $actor, $changes, $expectedRevision, $reason, $operationId) {
            $shift = app(ShiftReportMutationGuard::class)->lockEditable($source, $actor);
            $branchId = $shift->shift()->value('branch_id');

            // Lock and verify report aggregate & expected revision
            /** @var ShiftReportAggregate $aggregate */
            $aggregate = ShiftReportAggregate::query()
                ->where('source_type', 'cashier_shift')
                ->where('source_id', $shift->id)
                ->lockForUpdate()
                ->first();

            if (! $aggregate || $aggregate->current_revision_number !== $expectedRevision) {
                throw new ConflictHttpException('STALE_REPORT_REVISION');
            }
            if ($aggregate->fresh_count_required) {
                throw new ConflictHttpException('PHYSICAL_RECOUNT_REQUIRED');
            }

            // Read current revision
            $previousRevision = $aggregate->revisions()
                ->where('revision_number', $aggregate->current_revision_number)
                ->lockForUpdate()
                ->first();

            // Preserve reviews and snapshot before any mutations
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            // Extract old values
            $oldGrossHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->total_sales ?? '0.00');
            $oldCardsHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->card_payments ?? '0.00');
            $oldCashCollectedHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->cash_collected ?? '0.00');
            $oldHandoverNotes = $shift->handover_notes;

            $oldBreakdowns = ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)
                ->orderBy('aggregator_id')
                ->lockForUpdate()
                ->get();
            $oldAppsHalalas = (int) $oldBreakdowns->sum(fn ($b) => ShiftFinancialCalculator::storedSarToHalalas($b->amount));
            $oldAggregatorsList = $oldBreakdowns->map(fn ($b) => [
                'aggregator_id' => $b->aggregator_id,
                'amount' => (string) $b->amount,
                'notes' => $b->notes,
            ])->values()->toArray();

            // Extract new values
            $newGrossHalalas = array_key_exists('total_sales', $changes)
                ? ShiftFinancialCalculator::sarToHalalas($changes['total_sales'])
                : $oldGrossHalalas;

            $newCardsHalalas = array_key_exists('card_payments', $changes)
                ? ShiftFinancialCalculator::sarToHalalas($changes['card_payments'])
                : $oldCardsHalalas;

            $newCashCollectedHalalas = array_key_exists('cash_collected', $changes)
                ? ShiftFinancialCalculator::sarToHalalas($changes['cash_collected'])
                : $oldCashCollectedHalalas;

            $newHandoverNotes = array_key_exists('handover_notes', $changes)
                ? $changes['handover_notes']
                : $oldHandoverNotes;

            $aggregatorsChanged = false;
            $newAppsHalalas = $oldAppsHalalas;
            $newAggregatorsList = $oldAggregatorsList;

            if (array_key_exists('aggregators', $changes)) {
                $rawAggs = (array) $changes['aggregators'];
                $seenAggregatorIds = [];
                $tempAppsHalalas = 0;
                $formattedAggs = [];

                foreach ($rawAggs as $agg) {
                    $aggId = (string) ($agg['aggregator_id'] ?? '');
                    if ($aggId === '' || isset($seenAggregatorIds[$aggId])) {
                        throw ValidationException::withMessages([
                            'aggregators' => 'Duplicate or invalid aggregator_id in aggregators breakdown.',
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

                usort($formattedAggs, fn ($a, $b) => strcmp($a['aggregator_id'], $b['aggregator_id']));
                $newAggregatorsList = $formattedAggs;
                $newAppsHalalas = $tempAppsHalalas;
                $aggregatorsChanged = json_encode($newAggregatorsList) !== json_encode($oldAggregatorsList);
            }

            // Sales channel check
            $channels = ShiftFinancialCalculator::salesChannelCheck($newGrossHalalas, $newCardsHalalas, $newAppsHalalas);
            if (! $channels['channelsValid']) {
                throw ValidationException::withMessages([
                    'card_payments' => 'Card payments plus delivery-app sales cannot exceed gross sales.',
                ]);
            }

            $grossChanged = $newGrossHalalas !== $oldGrossHalalas;
            $cardsChanged = $newCardsHalalas !== $oldCardsHalalas;
            $cashCollectedChanged = $newCashCollectedHalalas !== $oldCashCollectedHalalas;
            $notesChanged = $newHandoverNotes !== $oldHandoverNotes;
            $appsChanged = $newAppsHalalas !== $oldAppsHalalas;

            // Reject financial and narrative no-ops
            if (! $grossChanged && ! $cardsChanged && ! $cashCollectedChanged && ! $notesChanged && ! $aggregatorsChanged && ! $appsChanged) {
                throw ValidationException::withMessages([
                    'changes' => 'NO_REPORT_CHANGES',
                ]);
            }

            // Canonical calculation for VAT and Net
            $openingHalalas = $this->counts->confirmedOpeningHalalas($shift->id);
            $calc = ShiftFinancialCalculator::calculate(
                $newGrossHalalas,
                $newCardsHalalas,
                $newAppsHalalas,
                $openingHalalas,
                $newGrossHalalas, // dummy count for pure net/vat breakdown
                0
            );

            $netHalalas = $calc['net'];
            $vatHalalas = $calc['vat'];
            $newNetSar = number_format($netHalalas / 100, 2, '.', '');
            $newVatSar = number_format($vatHalalas / 100, 2, '.', '');
            $newGrossSar = number_format($newGrossHalalas / 100, 2, '.', '');
            $newCardsSar = number_format($newCardsHalalas / 100, 2, '.', '');
            $newCashCollectedSar = number_format($newCashCollectedHalalas / 100, 2, '.', '');

            // Advance report revision
            $actorType = $actor->getMorphClass();
            $newRevision = $this->revisions->recordCashierRevision($shift, $actorType, $actor->id, $expectedRevision);

            // Re-calculate count evidence if prior count exists
            $sourceCount = $previousRevision
                ? ShiftReportCashCount::where('report_revision_id', $previousRevision->id)->lockForUpdate()->first()
                : null;

            $varianceChanged = false;
            $oldVarianceHalalas = null;
            $newVarianceHalalas = null;
            $newVarianceSar = null;

            if ($sourceCount !== null) {
                $newCount = $this->counts->recordCorrectedEvidence(
                    $shift,
                    $newRevision,
                    $sourceCount,
                    [
                        'gross_halalas' => $newGrossHalalas,
                        'cards_halalas' => $newCardsHalalas,
                        'apps_halalas' => $newAppsHalalas,
                    ]
                );

                $newVarianceHalalas = $newCount->variance_halalas;
                $oldVarianceHalalas = $sourceCount->variance_halalas;
                $varianceChanged = $newVarianceHalalas !== $oldVarianceHalalas;
                $newVarianceSar = number_format($newVarianceHalalas / 100, 2, '.', '');
            }

            // Record correction rows
            $companyId = $shift->shift?->branch?->asab_company_id;
            $recordCorrection = function (
                string $field,
                string $type,
                ?string $oldVal,
                ?string $newVal,
                ?int $oldH,
                ?int $newH
            ) use ($aggregate, $previousRevision, $newRevision, $reason, $actorType, $actor, $companyId, $branchId, $operationId) {
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
                    'actor_type' => $actorType,
                    'actor_id' => $actor->id,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'operation_id' => $operationId,
                ]);
            };

            if ($grossChanged) {
                $recordCorrection('total_sales', 'monetary', number_format($oldGrossHalalas / 100, 2, '.', ''), $newGrossSar, $oldGrossHalalas, $newGrossHalalas);
            }
            if ($cardsChanged) {
                $recordCorrection('card_payments', 'monetary', number_format($oldCardsHalalas / 100, 2, '.', ''), $newCardsSar, $oldCardsHalalas, $newCardsHalalas);
            }
            if ($cashCollectedChanged) {
                $recordCorrection('cash_collected', 'monetary', number_format($oldCashCollectedHalalas / 100, 2, '.', ''), $newCashCollectedSar, $oldCashCollectedHalalas, $newCashCollectedHalalas);
            }

            $oldNetHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->net_sales ?? '0.00');
            if ($netHalalas !== $oldNetHalalas) {
                $recordCorrection('net_sales', 'monetary', number_format($oldNetHalalas / 100, 2, '.', ''), $newNetSar, $oldNetHalalas, $netHalalas);
            }

            $oldVatHalalas = ShiftFinancialCalculator::storedSarToHalalas($shift->vat_amount ?? '0.00');
            if ($vatHalalas !== $oldVatHalalas) {
                $recordCorrection('vat_amount', 'monetary', number_format($oldVatHalalas / 100, 2, '.', ''), $newVatSar, $oldVatHalalas, $vatHalalas);
            }

            if ($notesChanged) {
                $recordCorrection('handover_notes', 'string', $oldHandoverNotes, $newHandoverNotes, null, null);
            }

            if ($aggregatorsChanged) {
                $recordCorrection('aggregators', 'json', json_encode($oldAggregatorsList), json_encode($newAggregatorsList), null, null);
            }

            if ($appsChanged) {
                $recordCorrection('derived_delivery_apps', 'monetary', number_format($oldAppsHalalas / 100, 2, '.', ''), number_format($newAppsHalalas / 100, 2, '.', ''), $oldAppsHalalas, $newAppsHalalas);
            }

            if ($varianceChanged) {
                $recordCorrection('variance', 'monetary', number_format($oldVarianceHalalas / 100, 2, '.', ''), $newVarianceSar, $oldVarianceHalalas, $newVarianceHalalas);
            }

            // Supersede existing liability allocations
            ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            // Update projection on cashier_shifts
            $updateData = [
                'total_sales' => $newGrossSar,
                'card_payments' => $newCardsSar,
                'cash_collected' => $newCashCollectedSar,
                'net_sales' => $newNetSar,
                'vat_amount' => $newVatSar,
                'handover_notes' => $newHandoverNotes,
            ];
            if ($sourceCount !== null) {
                $updateData['variance'] = $newVarianceSar;
            }
            $shift->update($updateData);

            // Update sales breakdown rows if aggregators changed
            if ($aggregatorsChanged) {
                ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->delete();
                foreach ($newAggregatorsList as $item) {
                    ShiftSalesBreakdown::create([
                        'cashier_shift_id' => $shift->id,
                        'aggregator_id' => $item['aggregator_id'],
                        'amount' => $item['amount'],
                        'notes' => $item['notes'],
                    ]);
                }
            }

            // Record history
            $shift->history()->create([
                'action' => 'report_corrected',
                'performed_by' => (string) $actor->getKey(),
                'performed_by_type' => $actorType,
                'old_value' => [
                    'total_sales' => number_format($oldGrossHalalas / 100, 2, '.', ''),
                    'card_payments' => number_format($oldCardsHalalas / 100, 2, '.', ''),
                    'cash_collected' => number_format($oldCashCollectedHalalas / 100, 2, '.', ''),
                ],
                'new_value' => [
                    'total_sales' => $newGrossSar,
                    'card_payments' => $newCardsSar,
                    'cash_collected' => $newCashCollectedSar,
                    'revision_number' => $newRevision->revision_number,
                    'operation_id' => $operationId,
                    'reason' => $reason,
                ],
                'notes' => $reason,
            ]);

            // Create snapshot for the new revision
            $this->snapshots->createSnapshotIfMissing($newRevision, $shift);
            app(ShiftReportCacheInvalidator::class)->cashierReport($shift);

            return $newRevision;
        });
    }
}
