<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\BranchManagers\Models\BranchManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class BranchManagerShiftService
{
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
            ->map(fn($manager) => [
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
            'variance_type' => $handover->variance_amount > 0 ? 'Over' : ($handover->variance_amount < 0 ? 'Short' : 'None'),
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
        $handovers = $shift->cashierHandovers;

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
            $this->notifyManagerAboutHandover($handover);
        });
    }

    private function notifyManagerAboutHandover(CashierShiftHandover $handover): void
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
     */
    public function getShiftHandovers(BranchManagerShift $managerShift, ?string $handoverType = null)
    {
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
                        'variance'
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
                'approvedBy:id,name'
            ]);

        switch ($handoverType) {
            case 'to_manager':
                $query->where('handover_to_type', 'branch_manager')
                    ->where('handover_to_id', $managerShift->branch_manager_id)
                    ->where(function ($q) use ($managerShift) {
                        // Include handovers where either shift_date or handover_date matches manager's shift_date
                        // This handles cases where shift ends on a different date than it started
                        $q->where(function ($subQ) use ($managerShift) {
                            $subQ->whereHas('cashierShift', function ($cashierQuery) use ($managerShift) {
                                $cashierQuery->whereDate('shift_date', $managerShift->shift_date);
                            });
                        })
                            ->orWhereDate('handover_date', $managerShift->shift_date);
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
                        'amount' => (float) $detail->amount,
                        'notes' => $detail->notes,
                    ];
                })->toArray();
            }
        }

        return [
            'handover_id' => $handover->id,
            'cashier_shift_id' => $handover->cashier_shift_id,
            'cashier_name' => $cashierShift->cashier->name,
            'shift_time' => $shift ? $shift->name : 'N/A',
            'handover_amount' => (float) $handover->handover_amount,
            'handover_date' => $handover->handover_date?->format('Y-m-d H:i:s'),
            'handover_time' => $handover->handover_time?->format('H:i:s'),
            'handover_notes' => $handover->handover_notes,
            'handover_from' => $cashierShift->cashier->name,
            'handover_to' => $handover->handoverTo?->name,
            'handover_to_id' => $handover->handover_to_id,
            'handover_to_type' => $handover->handover_to_type,
            'total_sales' => (float) $cashierShift->total_sales,
            'variance_amount' => (float) $handover->variance_amount,
            'variance_type' => $handover->variance_amount > 0 ? 'Over' : ($handover->variance_amount < 0 ? 'Short' : 'None'),
            'variance_reason' => $handover->variance_reason,
            'attached_files' => $handover->variance_files ?? [],
            'status' => $handover->status,
            'rejection_reason' => $handover->rejection_reason,
            'rejection_count' => $handover->rejection_count,
            'handed_over_at' => $handover->handed_over_at?->format('Y-m-d H:i:s'),
            'approved_at' => $handover->approved_at?->format('Y-m-d H:i:s'),
            'approved_by' => $handover->approvedBy?->name,
            'can_approve' => $handover->canApprove(),
            'can_reject' => $handover->canReject(),
            'variance_details' => $varianceDetails,

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
                ->with('salesBreakdown')
                ->get()
                ->keyBy('cashier_id');

            // Single query to fetch all handovers
            $handovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $managerShift->branch_manager_id)
                ->whereIn('cashier_shift_id', $cashierShifts->pluck('id'))
                ->get()
                ->keyBy('cashier_shift_id');

            // Process updates
            foreach ($cashierBreakdown as $breakdown) {
                $this->processCashierBreakdownItem($breakdown, $cashierShifts, $handovers);
            }
        });

        // Clear caches after transaction
        $this->clearShiftCaches($managerShift);
    }

    /**
     * Process single cashier breakdown item
     */
    private function processCashierBreakdownItem(array $breakdown, $cashierShifts, $handovers): void
    {
        $cashierId = $breakdown['cashier_id'] ?? null;
        if (!$cashierId || !isset($cashierShifts[$cashierId])) {
            return;
        }

        $cashierShift = $cashierShifts[$cashierId];
        $updateData = $this->prepareShiftUpdateData($breakdown);

        if (!empty($updateData)) {
            $cashierShift->update($updateData);
        }

        // Update handover variance
        if (isset($breakdown['variance']) && isset($handovers[$cashierShift->id])) {
            $handovers[$cashierShift->id]->update(['variance_amount' => $breakdown['variance']]);
        }

        // Update sales breakdown
        if (isset($breakdown['delivery_app_payments'])) {
            $this->updateSalesBreakdown($cashierShift, $breakdown['delivery_app_payments']);
        }
    }

    /**
     * Prepare shift update data
     */
    private function prepareShiftUpdateData(array $breakdown): array
    {
        $updateData = [];

        if (isset($breakdown['sales'])) {
            $updateData['total_sales'] = $breakdown['sales'];
            $updateData['vat_amount'] = $breakdown['sales'] * 0.15;
            $updateData['net_sales'] = $breakdown['sales'] - $updateData['vat_amount'];
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
    private function updateSalesBreakdown(CashierShift $cashierShift, float $amount): void
    {
        $existingBreakdown = $cashierShift->salesBreakdown;

        if ($existingBreakdown->isEmpty()) {
            return;
        }

        if ($existingBreakdown->count() === 1) {
            $existingBreakdown->first()->update(['amount' => $amount]);
        } else {
            $aggregatorId = $existingBreakdown->first()->aggregator_id;
            $existingBreakdown->each->delete();

            ShiftSalesBreakdown::create([
                'cashier_shift_id' => $cashierShift->id,
                'aggregator_id' => $aggregatorId,
                'amount' => $amount,
            ]);
        }
    }

    /**
     * IMPROVEMENT 5: Updated calculateFinancialSummary with better caching
     * تحديث method مع caching محسّن
     */
    public function calculateFinancialSummary(BranchManagerShift $shift): array
    {
        $cacheKey = $this->getShiftCacheKey($shift, 'financial_summary');

        if (config('cache.default') === 'redis') {
            $shiftTag = "shift:{$shift->id}:{$shift->shift_date->format('Y-m-d')}";

            try {
                return Cache::tags([$shiftTag])->remember($cacheKey, 300, function () use ($shift) {
                    return $this->computeFinancialSummary($shift);
                });
            } catch (\Exception $e) {
                // Fallback if tags not supported
            }
        }

        return Cache::remember($cacheKey, 300, function () use ($shift) {
            return $this->computeFinancialSummary($shift);
        });
    }

    /**
     * Extract computation logic
     */
    private function computeFinancialSummary(BranchManagerShift $shift): array
    {
        $handovers = $this->getShiftHandovers($shift, 'to_manager');

        return $handovers->reduce(function ($summary, $handover) {
            $cashierShift = $handover->cashierShift;

            $summary['total_sales'] += $cashierShift->total_sales ?? 0;
            $summary['cash_collected'] += $cashierShift->cash_collected ?? 0;
            $summary['card_payments'] += $cashierShift->card_payments ?? 0;
            $summary['delivery_app_payments'] += $cashierShift->salesBreakdown->sum('amount');
            $summary['total_variance'] += $handover->variance_amount ?? 0;

            return $summary;
        }, [
            'total_sales' => 0,
            'cash_collected' => 0,
            'card_payments' => 0,
            'delivery_app_payments' => 0,
            'total_variance' => 0,
        ]);
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

                if (!empty($keys)) {
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
        Cache::forget("shift_handovers_{$shift->id}_to_manager_" . $shift->updated_at->timestamp);
        Cache::forget("shift_handovers_{$shift->id}_between_cashiers_" . $shift->updated_at->timestamp);
        Cache::forget("financial_summary_{$shift->id}_" . $shift->updated_at->timestamp);
    }
}
