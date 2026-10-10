<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftSalesBreakdown;

class BranchManagerShiftService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private ?ShiftFinancialService $financialService = null;

    public function __construct(private ShiftReportRevisionService $revisions) {}

    /**
     * Lazily resolve ShiftFinancialService to break the circular dependency
     * (ShiftFinancialService depends on this class).
     */
    private function financialService(): ShiftFinancialService
    {
        if ($this->financialService === null) {
            $this->financialService = app(ShiftFinancialService::class);
        }

        return $this->financialService;
    }

    /**
     * Attach handoffs_summary and financial_summary to each shift to avoid N+1 in BranchManagerShiftResource.
     * Call this when returning a collection of BranchManagerShift (e.g. list/history).
     */
    public function attachHandoffsAndFinancialSummariesForCollection(Collection $shifts): void
    {
        if ($shifts->isEmpty()) {
            return;
        }

        $branchIds = $shifts->pluck('branch_id')->unique()->filter()->values()->all();
        if (empty($branchIds)) {
            return;
        }

        $handovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
            ->whereIn('handover_to_id', $shifts->pluck('branch_manager_id')->unique())
            ->whereHas('cashierShift.shift', function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds);
            })
            ->with(['cashierShift.shift', 'cashierShift.salesBreakdown.aggregator'])
            ->get();

        $grouped = collect($handovers)->groupBy(function (CashierShiftHandover $h) {
            $cs = $h->cashierShift;
            $shiftDate = $cs?->shift_date?->format('Y-m-d');
            $branchId = $cs?->shift?->branch_id;

            return ($shiftDate ?? '').'|'.($branchId ?? '').'|'.$h->handover_to_id;
        });

        foreach ($shifts as $shift) {
            $key = ($shift->shift_date?->format('Y-m-d') ?? '').'|'.($shift->branch_id ?? '').'|'.$shift->branch_manager_id;
            $shiftHandovers = $grouped->get($key, collect());

            $shift->setAttribute('handoffs_summary', [
                'total_handovers' => $shiftHandovers->count(),
                'approved' => $shiftHandovers->where('status', 'approved')->count(),
                'pending' => $shiftHandovers->where('status', 'pending')->count(),
                'rejected' => $shiftHandovers->whereIn('status', ['rejected', 'rejected_final'])->count(),
                'rejected_final' => $shiftHandovers->where('status', 'rejected_final')->count(),
                'total_amount' => (float) $shiftHandovers->where('status', 'approved')->sum('handover_amount'),
                'total_variance' => (float) $shiftHandovers->sum('variance_amount'),
                'all_received' => $shiftHandovers->count() > 0 && $shiftHandovers->where('status', 'pending')->count() === 0,
            ]);

            $financial = $this->financialService()->computeFinancialSummaryFromHandovers($shift, $shiftHandovers);
            $shift->setAttribute('financial_summary', $financial);
        }
    }

    /**
     * Auto-archive completed shifts after 7 days
     */
    public function autoArchiveOldShifts(): void
    {
        $cutoffDate = Carbon::now()->subDays(7);

        BranchManagerShift::where('status', 'completed')
            ->where('daily_report_submitted', true)
            ->where('archived_at', null)
            ->whereDate('shift_date', '<', $cutoffDate)
            ->update(['archived_at' => now()]);
    }

    /**
     * Get available next managers for handover
     */
    public function getAvailableNextManagers(BranchManagerShift $currentShift): array
    {
        return BranchManager::where('branch_id', $currentShift->branch_id)
            ->where('id', '!=', $currentShift->branch_manager_id)
            ->where('is_active', true)
            ->get()
            ->map(fn ($manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
            ])
            ->toArray();
    }

    /**
     * Calculate variance details for a handover
     */
    public function calculateVarianceDetails(CashierShiftHandover $handover): array
    {
        $cashierShift = $handover->cashierShift;

        return [
            'total_sales' => (float) $cashierShift->total_sales,
            'handover_amount' => (float) $handover->handover_amount,
            'variance_amount' => (float) $handover->variance_amount,
            'variance_type' => $this->normalizeVarianceType((float) $handover->variance_amount),
            'reason' => $handover->variance_reason,
            'attached_files' => $handover->variance_files ?? [],
            'cashier_details' => [
                'id' => $cashierShift->cashier_id,
                'name' => $cashierShift->cashier->name,
                'shift_time' => $cashierShift->shift->name,
            ],
        ];
    }

    /**
     * Update shift statistics after handover approval/rejection
     */
    public function updateShiftStatistics(BranchManagerShift $shift): void
    {
        $handovers = $shift->cashierHandovers()->get();

        $statistics = [
            'total_cashier_shifts' => $handovers->count(),
            'approved_handovers' => $handovers->where('status', 'approved')->count(),
            'pending_handovers' => $handovers->where('status', 'pending')->count(),
            'rejected_handovers' => $handovers->whereIn('status', ['rejected', 'rejected_final'])->count(),
        ];

        $shift->update($statistics);
    }

    /**
     * Validate if shift can be ended
     */
    public function validateShiftEnd(BranchManagerShift $shift): array
    {
        $errors = [];

        if ($shift->status !== 'in_progress') {
            $errors[] = 'Shift is not in progress';
        }

        $pendingHandovers = $shift->cashierHandovers()->where('status', 'pending')->count();
        if ($pendingHandovers > 0) {
            $errors[] = "There are {$pendingHandovers} pending cashier handovers";
        }

        return [
            'can_end' => empty($errors),
            'errors' => $errors,
            'pending_count' => $pendingHandovers,
        ];
    }

    /**
     * Process handover from cashier to manager
     */
    public function processCashierHandover(CashierShiftHandover $handover): void
    {
        DB::transaction(function () use ($handover) {
            // Update cashier shift status
            $handover->cashierShift()->update([
                'status' => 'completed',
                'handed_over_at' => now(),
            ]);

            // Notify manager about new handover
            $this->notifyManagerAboutHandover();
        });
    }

    private function notifyManagerAboutHandover(): void
    {
        // Implementation for sending notification to manager
        // This could be email, push notification, or in-app notification
    }

    // ====================================
    // OPTIMIZATION METHODS
    // ====================================

    /**
     * IMPROVEMENT 1: Better Cache Key Management
     * إدارة أفضل لـ cache keys
     */
    private function getShiftCacheKey(BranchManagerShift $shift, string $type = ''): string
    {
        // Use shift ID + date instead of updated_at for more stable keys
        $date = $shift->shift_date->format('Y-m-d');

        return "shift:{$shift->id}:{$date}:{$type}";
    }

    /**
     * Get all handovers for a manager shift (optimized with caching)
     * جلب جميع الـ handovers مرة واحدة فقط مع caching
     *
     * @param  bool  $skipCache  When true (e.g. workday/current), always return fresh data so approve/reject reflect immediately
     */
    public function getShiftHandovers(BranchManagerShift $managerShift, ?string $handoverType = null, bool $skipCache = false)
    {
        if ($skipCache) {
            return $this->fetchShiftHandovers($managerShift, $handoverType);
        }

        $cacheKey = $this->getShiftCacheKey($managerShift, "handovers:{$handoverType}");

        // Use Cache Tags if available (Redis/Memcached)
        if (config('cache.default') === 'redis') {
            $shiftTag = "shift:{$managerShift->id}:{$managerShift->shift_date->format('Y-m-d')}";

            try {
                return Cache::tags([$shiftTag])->remember($cacheKey, 300, function () use ($managerShift, $handoverType) {
                    return $this->fetchShiftHandovers($managerShift, $handoverType);
                });
            } catch (\Exception $e) {
                // Fallback if tags not supported
            }
        }

        // Fallback without tags
        return Cache::remember($cacheKey, 300, function () use ($managerShift, $handoverType) {
            return $this->fetchShiftHandovers($managerShift, $handoverType);
        });
    }

    /**
     * IMPROVEMENT 4: Extract query logic to separate method
     * فصل منطق الـ query لـ method منفصلة
     */
    private function fetchShiftHandovers(BranchManagerShift $managerShift, ?string $handoverType)
    {
        $query = CashierShiftHandover::query()
            ->whereHas('cashierShift', function ($query) use ($managerShift) {
                $query->whereHas('shift', function ($q) use ($managerShift) {
                    $q->where('branch_id', $managerShift->branch_id);
                });
            })
            ->with([
                'cashierShift' => function ($q) {
                    $q->select([
                        'id',
                        'cashier_id',
                        'shift_id',
                        'shift_date',
                        'total_sales',
                        'net_sales',
                        'vat_amount',
                        'cash_collected',
                        'card_payments',
                        'variance',
                    ]);
                },
                'cashierShift.cashier:id,name',
                'cashierShift.shift:id,name,branch_id',
                'cashierShift.salesBreakdown' => function ($q) {
                    $q->select(['id', 'cashier_shift_id', 'aggregator_id', 'amount']);
                },
                'cashierShift.salesBreakdown.aggregator:id,name',
                'cashierShift.varianceDetails:id,cashier_shift_id,responsible_cashier_id,assigned_amount,reason',
                'cashierShift.varianceDetails.responsibleCashier:id,name',
                'handoverTo:id,name',
                'approvedBy:id,name',
            ]);

        switch ($handoverType) {
            case 'to_manager':
                $query->where('handover_to_type', 'branch_manager')
                    ->where('handover_to_id', $managerShift->branch_manager_id)
                    ->where(function ($q) use ($managerShift) {
                        $shiftDate = $managerShift->shift_date;
                        $sevenDaysAgo = $shiftDate->copy()->subDays(7);

                        // Today's handovers (by shift_date or handover_date) — any status
                        $q->where(function ($subQ) use ($shiftDate) {
                            $subQ->whereHas('cashierShift', function ($cq) use ($shiftDate) {
                                $cq->whereDate('shift_date', $shiftDate);
                            })->orWhereDate('handover_date', $shiftDate);
                        })
                        // Previous days (up to 7 days back): pending/rejected, plus
                        // approved handovers never swept into a submitted daily close
                        // (daily_closed_at NULL) — cash reviewed late must not vanish.
                            ->orWhere(function ($subQ) use ($managerShift, $shiftDate, $sevenDaysAgo) {
                                $subQ->whereHas('cashierShift.shift', function ($sq) use ($managerShift) {
                                    $sq->where('branch_id', $managerShift->branch_id);
                                })
                                    ->whereDate('handover_date', '>=', $sevenDaysAgo)
                                    ->whereDate('handover_date', '<', $shiftDate)
                                    ->where(function ($sq) {
                                        $sq->whereNotIn('status', ['approved', 'rejected_final'])
                                            ->orWhere(function ($aq) {
                                                $aq->where('status', 'approved')
                                                    ->whereNull('daily_closed_at');
                                            });
                                    });
                            });
                    });
                break;

            case 'between_cashiers':
                $query->where('handover_to_type', 'cashier')
                    ->whereDate('handover_date', $managerShift->shift_date);
                break;

            default:
                if ($handoverType) {
                    $query->where('handover_to_type', $handoverType);
                }
        }

        return $query->get();
    }

    /**
     * Normalise a raw handover/shift status to one of: pending | accepted | rejected.
     */
    public function normalizeHandoverStatus(?string $status): string
    {
        if (in_array($status, ['approved', 'accepted', 'completed'])) {
            return 'accepted';
        }

        if (in_array($status, ['rejected', 'rejected_final'])) {
            return 'rejected';
        }

        return 'pending';
    }

    /**
     * Transform handover to response format (reusable - maintains exact response format)
     * تحويل الـ handover لصيغة الـ response مع الحفاظ على نفس التنسيق
     */
    public function transformHandover(CashierShiftHandover $handover): array
    {
        $cashierShift = $handover->cashierShift;
        $shift = $cashierShift->shift;

        // Variance details - computed once
        $varianceDetails = null;
        if ($handover->variance_amount != 0) {
            $varianceDetails = [
                'total_sales' => (float) $cashierShift->total_sales,
                'handover_amount' => (float) $handover->handover_amount,
                'variance_amount' => (float) $handover->variance_amount,
                'variance_type' => $handover->variance_amount > 0 ? 'Over' : 'Short',
                'reason_for_variance' => $handover->variance_reason,
                'attached_files' => $handover->variance_files ?? [],
                'cashier_details' => [
                    'id' => $cashierShift->cashier_id,
                    'name' => $cashierShift->cashier->name,
                    'variance_reason' => $handover->variance_reason,
                ],
            ];

            if ($cashierShift->varianceDetails && $cashierShift->varianceDetails->isNotEmpty()) {
                $varianceDetails['other_cashiers'] = $cashierShift->varianceDetails->map(function ($detail) {
                    return [
                        'cashier_id' => $detail->responsible_cashier_id,
                        'cashier_name' => $detail->responsibleCashier?->name,
                        'amount' => (float) $detail->assigned_amount,
                        'notes' => $detail->reason,
                    ];
                })->toArray();
            }
        }

        return [
            'handover_id' => $handover->id,
            'cashier_shift_id' => $handover->cashier_shift_id,
            'cashier_name' => $cashierShift->cashier->name,
            'shift_time' => $shift ? $shift->name : 'N/A',
            'handover_amount' => (float) ($handover->handover_amount ?? $cashierShift->closing_balance ?? 0),
            'handover_date' => $handover->handover_date?->format(self::DATETIME_FORMAT),
            'handover_time' => $handover->handover_time?->format('H:i:s'),
            'handover_notes' => $handover->handover_notes,
            'handover_from' => $cashierShift->cashier->name,
            'handover_to' => $handover->handoverTo?->name,
            'handover_to_id' => $handover->handover_to_id,
            'handover_to_type' => $handover->handover_to_type,
            'total_sales' => (float) $cashierShift->total_sales,
            'variance_amount' => (float) $handover->variance_amount,
            'variance_type' => $this->normalizeVarianceType((float) $handover->variance_amount),
            'variance_reason' => $handover->variance_reason,
            'attached_files' => $handover->variance_files ?? [],
            'status' => $this->normalizeHandoverStatus($handover->status),
            'rejection_reason' => $handover->rejection_reason,
            'rejection_count' => $handover->rejection_count,
            'handed_over_at' => $handover->handed_over_at?->format(self::DATETIME_FORMAT),
            'approved_at' => $handover->approved_at?->format(self::DATETIME_FORMAT),
            'approved_by' => $handover->approvedBy?->name,
            'can_approve' => $handover->canApprove(),
            'can_reject' => $handover->canReject(),
            'variance_details' => $varianceDetails,
            'accounting_name' => 'accounting_name',

        ];
    }

    /**
     * IMPROVEMENT 6: Optimized bulkUpdateCashierShifts with DB transaction
     * تحسين الـ bulk update مع DB transaction
     */
    public function bulkUpdateCashierShifts(array $cashierBreakdown, BranchManagerShift $managerShift): void
    {
        $cashierIds = collect($cashierBreakdown)->pluck('cashier_id')->filter()->unique()->all();

        if (empty($cashierIds)) {
            return;
        }

        DB::transaction(function () use ($cashierIds, $cashierBreakdown, $managerShift) {
            // Single query to fetch all shifts
            $cashierShifts = CashierShift::whereIn('cashier_id', $cashierIds)
                ->whereDate('shift_date', $managerShift->shift_date)
                ->whereHas('shift', function ($q) use ($managerShift) {
                    $q->where('branch_id', $managerShift->branch_id);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->with('salesBreakdown')
                ->get()
                ->keyBy('cashier_id');

            // Single query to fetch all handovers (branch-scoped: cashier shift ids
            // are already limited to this branch and workday)
            $handovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $managerShift->branch_manager_id)
                ->whereIn('cashier_shift_id', $cashierShifts->pluck('id'))
                ->get()
                ->keyBy('cashier_shift_id');

            // Process updates
            foreach ($cashierBreakdown as $breakdown) {
                $cashierShift = $cashierShifts[$breakdown['cashier_id'] ?? ''] ?? null;
                $changed = $this->processCashierBreakdownItem($breakdown, $cashierShifts, $handovers);
                if ($cashierShift && $changed) {
                    $current = $this->revisions->currentCashierRevision($cashierShift);
                    $this->revisions->recordCashierRevision(
                        $cashierShift,
                        'branch_manager',
                        $managerShift->branch_manager_id,
                        $current?->revision_number ?? 0
                    );
                }
            }
        });

        // Clear caches after transaction
        $this->clearShiftCaches($managerShift);
    }

    /**
     * Process single cashier breakdown item
     */
    private function processCashierBreakdownItem(array $breakdown, $cashierShifts, $handovers): bool
    {
        $cashierId = $breakdown['cashier_id'] ?? null;
        if (! $cashierId || ! isset($cashierShifts[$cashierId])) {
            return false;
        }

        $cashierShift = $cashierShifts[$cashierId];
        $updateData = $this->prepareShiftUpdateData($breakdown);
        $changed = false;

        if (! empty($updateData)) {
            $cashierShift->fill($updateData);
            if ($cashierShift->isDirty(array_keys($updateData))) {
                $cashierShift->save();
                $changed = true;
            }
        }

        // Update handover variance
        if (isset($breakdown['variance']) && isset($handovers[$cashierShift->id])) {
            $handover = $handovers[$cashierShift->id];
            $handover->fill(['variance_amount' => $breakdown['variance']]);
            if ($handover->isDirty('variance_amount')) {
                $handover->save();
                $changed = true;
            }
        }

        // Update sales breakdown
        if (isset($breakdown['delivery_app_payments'])) {
            $changed = $this->updateSalesBreakdown($cashierShift, $breakdown['delivery_app_payments']) || $changed;
        }

        return $changed;
    }

    /**
     * Prepare shift update data
     */
    private function prepareShiftUpdateData(array $breakdown): array
    {
        $updateData = [];

        if (isset($breakdown['sales'])) {
            $salesCalculation = \App\Support\ShiftFinancialCalculator::calculateVatInclusiveSales($breakdown['sales']);
            $updateData['total_sales'] = $breakdown['sales'];
            $updateData['vat_amount'] = $salesCalculation['vat'];
            $updateData['net_sales'] = $salesCalculation['net'];
        }

        if (isset($breakdown['cash_collected'])) {
            $updateData['cash_collected'] = $breakdown['cash_collected'];
        }

        if (isset($breakdown['card_payments'])) {
            $updateData['card_payments'] = $breakdown['card_payments'];
        }

        if (isset($breakdown['variance'])) {
            $updateData['variance'] = $breakdown['variance'];
        }

        return $updateData;
    }

    /**
     * Update sales breakdown
     */
    private function updateSalesBreakdown(CashierShift $cashierShift, float $amount): bool
    {
        $existingBreakdown = $cashierShift->salesBreakdown;

        if ($existingBreakdown->isEmpty()) {
            return false;
        }

        if ($existingBreakdown->count() === 1) {
            $item = $existingBreakdown->first();
            $item->fill(['amount' => $amount]);
            if (! $item->isDirty('amount')) {
                return false;
            }
            $item->save();
        } else {
            $aggregatorId = $existingBreakdown->first()->aggregator_id;
            $existingBreakdown->each->delete();

            ShiftSalesBreakdown::create([
                'cashier_shift_id' => $cashierShift->id,
                'aggregator_id' => $aggregatorId,
                'amount' => $amount,
            ]);
        }

        return true;
    }

    /**
     * Calculate financial summary (delegates to ShiftFinancialService).
     */
    public function calculateFinancialSummary(BranchManagerShift $shift, bool $skipCache = false): array
    {
        return $this->financialService()->calculateFinancialSummary($shift, $skipCache);
    }

    /**
     * Lock the cashier inputs and manager-directed requests used by a manager
     * close/correction. The manager row is locked by the caller first.
     */
    public function lockCashierFinancialInputs(BranchManagerShift $managerShift): void
    {
        $from = $managerShift->shift_date->copy()->subDays(7)->toDateString();
        $through = $managerShift->shift_date->toDateString();

        $cashierShiftIds = CashierShift::query()
            ->where(function ($inputs) use ($from, $through, $managerShift) {
                $inputs->where(function ($recent) use ($from, $through) {
                    $recent->whereDate('shift_date', '>=', $from)->whereDate('shift_date', '<=', $through);
                })->orWhereHas('handover', function ($carried) use ($managerShift) {
                    // The daily close includes approved, unclosed carry-over even beyond seven days.
                    $carried->where('handover_to_type', 'branch_manager')
                        ->where('handover_to_id', $managerShift->branch_manager_id)
                        ->where('status', 'approved')->whereNull('daily_closed_at');
                });
            })
            ->whereHas('shift', fn ($query) => $query->where('branch_id', $managerShift->branch_id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->pluck('id');

        if ($cashierShiftIds->isNotEmpty()) {
            $reports = \Modules\Shift\Models\ShiftReportAggregate::query()
                ->where('source_type', 'cashier_shift')->whereIn('source_id', $cashierShiftIds)
                ->orderBy('source_id')->lockForUpdate()->get();
            if ($reports->contains(fn ($report) => (bool) $report->fresh_count_required)) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('PHYSICAL_RECOUNT_REQUIRED');
            }
            CashierShiftHandover::query()
                ->whereIn('cashier_shift_id', $cashierShiftIds)
                ->where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $managerShift->branch_manager_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        BranchManagerCashTransfer::query()
            ->where('branch_manager_shift_id', $managerShift->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    // =====================================================================
    // HELPERS EXTRACTED FROM CONTROLLER (reduce controller method count)
    // =====================================================================

    /**
     * Calculate the branch manager shift progress for a given shift.
     */
    public function calculateShiftProgress(BranchManagerShift $shift): array
    {
        // The manager covers the branch's whole day of cashier shifts, so the
        // planned window and duration come from the branch's shift templates
        // (3 × 8h → 24h) instead of a fixed 8-hour block.
        $workday = app(BranchWorkdayWindowService::class)->forBranch($shift->branch_id);
        $startTime = $shift->actual_start_time;
        $endTime = $shift->actual_end_time;
        $shiftDateFormatted = $shift->shift_date->format('d M Y');

        $statusLabel = match ($shift->status) {
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            default => 'Completed',
        };

        $progress = [
            'title' => "Branch Manager Shift - {$shiftDateFormatted}",
            'description' => 'Managing daily operations and cashier handovers',
            'status' => $statusLabel,
            'start_time' => $startTime ? $startTime->format('H:i') : $workday['start'],
            'end_time' => $endTime ? $endTime->format('H:i') : $workday['end'],
            // Planned length of the workday = sum of the branch's shift hours.
            'planned_hours' => $workday['totalHours'],
            'shifts_count' => $workday['shiftCount'],
            'elapsed_hours' => 0,
            'progress_percentage' => 0,
        ];

        if (! $startTime) {
            return $progress;
        }

        if ($shift->status === 'in_progress') {
            $expectedEndTime = $endTime ?: $startTime->copy()->addMinutes((int) round($workday['totalHours'] * 60));
            $totalMinutes = $startTime->diffInMinutes($expectedEndTime);
            $elapsedMinutes = $startTime->diffInMinutes(now());

            $progress['elapsed_hours'] = round($elapsedMinutes / 60, 2);
            $progress['progress_percentage'] = $totalMinutes > 0
                ? min(($elapsedMinutes / $totalMinutes) * 100, 100)
                : 0;
        } elseif ($shift->status === 'completed' && $endTime) {
            $progress['elapsed_hours'] = round($startTime->diffInHours($endTime), 2);
            $progress['progress_percentage'] = 100;
        }

        return $progress;
    }

    /**
     * Build the per-cashier breakdown array from a collection of handovers (delegates to ShiftFinancialService).
     */
    public function buildCashierBreakdownFromHandovers(Collection $handovers): array
    {
        return $this->financialService()->buildCashierBreakdownFromHandovers($handovers);
    }

    /**
     * Sum all approved handover amounts for the branch workday (delegates to ShiftFinancialService).
     */
    public function sumApprovedHandoverAmount(BranchManagerShift $managerShift): float
    {
        return $this->financialService()->sumApprovedHandoverAmount($managerShift);
    }

    /**
     * Prepare the final daily close summary (delegates to ShiftFinancialService).
     */
    public function prepareDailyCloseSummary(BranchManagerShift $shift): array
    {
        return $this->financialService()->prepareDailyCloseSummary($shift);
    }

    /**
     * Name of the dashboard accountant responsible for the branch — the final
     * daily-close approver shown on the mobile Final Handover screen. Null when
     * the branch is not linked to the ASAB world or no accountant covers it.
     */
    public function responsibleAccountantNameForBranch(?string $branchId): ?string
    {
        if (! $branchId) {
            return null;
        }

        $branch = \Modules\Branch\Models\Branch::query()
            ->select(['id', 'asab_company_id', 'asab_brand_id', 'asab_restaurant_id'])
            ->find($branchId);

        if (! $branch) {
            return null;
        }

        return app(\Modules\Admin\Services\AccountantScopeService::class)
            ->responsibleAccountantForMobileBranch($branch)?->name;
    }

    /**
     * Return correction-request details from a handover status model, or null when not applicable.
     */
    public function getCorrectionDetails($handoverStatus): ?array
    {
        if (! $this->isCorrectionDetailsEligible($handoverStatus)) {
            return null;
        }

        return [
            'requested_by' => $handoverStatus->reviewedBy?->name ?? 'N/A',
            'requested_by_id' => $handoverStatus->reviewed_by_id,
            'requested_by_type' => $this->getReviewerTypeLabel($handoverStatus->reviewed_by_type),
            'manager_comment' => $handoverStatus->manager_comment,
            'requested_at' => $handoverStatus->reviewed_at?->format(self::DATETIME_FORMAT),
            'can_cashier_edit' => $handoverStatus->canCashierEdit(),
        ];
    }

    /**
     * Convert a fully-qualified class name or slug into a human-readable reviewer label.
     */
    public function getReviewerTypeLabel(?string $reviewerType): ?string
    {
        if (! $reviewerType) {
            return null;
        }

        return $this->mapReviewerTypeToLabel($reviewerType);
    }

    private function mapReviewerTypeToLabel(string $reviewerType): string
    {
        if (str_contains($reviewerType, 'BranchManager') || $reviewerType === 'branch_manager') {
            return 'Branch Manager';
        }

        if (str_contains($reviewerType, 'Cashier') || $reviewerType === 'cashier') {
            return 'Cashier';
        }

        return class_basename($reviewerType);
    }

    /**
     * Returns true when all conditions for producing correction details are met.
     */
    private function isCorrectionDetailsEligible($handoverStatus): bool
    {
        return $handoverStatus
            && $handoverStatus->manager_comment
            && $handoverStatus->reviewed_at
            && in_array($handoverStatus->manager_approval_status, ['rejected', 'rejected_final']);
    }

    /**
     * IMPROVEMENT 2: Optimized Cache Invalidation
     * مسح الـ cache بشكل محسّن
     */
    public function clearShiftCaches(BranchManagerShift $shift): void
    {
        $shiftKey = "shift:{$shift->id}:{$shift->shift_date->format('Y-m-d')}";

        try {
            // Method 1: Using Cache Tags (Best for Redis/Memcached)
            if (config('cache.default') === 'redis') {
                try {
                    Cache::tags([$shiftKey])->flush();

                    return;
                } catch (\Exception $e) {
                    // Fallback if tags not supported
                }
            }
        } catch (\Exception $e) {
            // Continue to fallback
        }

        // Method 2: Direct Redis commands (if using Redis without tags)
        try {
            if (config('cache.default') === 'redis') {
                $redis = Redis::connection();
                $prefix = config('cache.prefix', 'laravel_cache');
                $pattern = "{$prefix}:*shift:{$shift->id}:{$shift->shift_date->format('Y-m-d')}*";

                $keys = $redis->keys($pattern);

                if (! empty($keys)) {
                    // Remove cache prefix from keys for Laravel Cache::forget()
                    $keysWithoutPrefix = array_map(function ($key) use ($prefix) {
                        return str_replace("{$prefix}:", '', $key);
                    }, $keys);

                    foreach ($keysWithoutPrefix as $key) {
                        Cache::forget($key);
                    }
                }

                return;
            }
        } catch (\Exception $e) {
            // Continue to fallback
        }

        // Method 3: Manual forget (Fallback for file/database cache)
        $types = ['', 'handovers:to_manager', 'handovers:between_cashiers', 'financial_summary'];
        foreach ($types as $type) {
            Cache::forget($this->getShiftCacheKey($shift, $type));
        }

        // Also clear with old timestamp-based keys for backward compatibility
        Cache::forget("shift_handovers_{$shift->id}_to_manager_".$shift->updated_at->timestamp);
        Cache::forget("shift_handovers_{$shift->id}_between_cashiers_".$shift->updated_at->timestamp);
        Cache::forget("financial_summary_{$shift->id}_".$shift->updated_at->timestamp);
    }

    /**
     * Resolve opening balance from the most recent completed shift for a branch, defaulting to 0.
     */
    public function resolveBranchManagerOpeningBalance(string $branchId): float
    {
        $previousShift = BranchManagerShift::where('branch_id', $branchId)
            ->where('shift_date', '<', today())
            ->where('status', 'completed')
            ->orderByDesc('shift_date')
            ->select(['handover_amount', 'closing_balance'])
            ->first();

        if (! $previousShift) {
            return 0.0;
        }

        return (float) ($previousShift->handover_amount ?? $previousShift->closing_balance ?? 0);
    }

    /**
     * Resolve the financial totals to be stored on the shift (delegates to ShiftFinancialService).
     */
    public function resolveFinancialValues(Request $request, array $financialSummary, BranchManagerShift $managerShift): array
    {
        return $this->financialService()->resolveFinancialValues($request, $financialSummary, $managerShift);
    }

    /**
     * Build the standardised daily_close_status response array (delegates to ShiftFinancialService).
     */
    public function buildDailyCloseStatusArray(BranchManagerShift $managerShift): array
    {
        return $this->financialService()->buildDailyCloseStatusArray($managerShift);
    }

    /**
     * Build the variance_details payload for a CashierShiftHandover response (delegates to ShiftFinancialService).
     */
    public function buildHandoverVarianceDetails(CashierShiftHandover $handover, CashierShift $cashierShift): ?array
    {
        return $this->financialService()->buildHandoverVarianceDetails($handover, $cashierShift);
    }

    /**
     * Map a numeric variance to a human-readable type label.
     */
    public function normalizeVarianceType(float $variance): string
    {
        if ($variance > 0) {
            return 'Over';
        }

        if ($variance < 0) {
            return 'Short';
        }

        return 'None';
    }
}
