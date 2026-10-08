<?php

namespace Modules\Shift\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerShift extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'branch_manager_id',
        'branch_id',
        'shift_date',
        'status',
        'actual_start_time',
        'actual_end_time',

        // Financial Summary
        'total_sales',
        'net_sales',
        'vat_amount',
        'cash_collected',
        'card_payments',
        'aggregator_payments',

        // Balances
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'variance',

        // Handover to next manager
        'next_manager_id',
        'handover_from',
        'handover_to',
        'handover_amount',
        'handover_date',
        'handover_time',
        'handover_timing',
        'handover_status',
        'handover_notes',

        // Statistics
        'total_cashier_shifts',
        'completed_cashier_shifts',
        'pending_cashier_shifts',

        // Daily Report
        'daily_report_submitted',
        'daily_report_submitted_at',
        'daily_report_notes',

        // Reopen capability
        'can_reopen',
        'reopened_at',
        'reopen_reason',

        // Approval
        'approved_by',
        'approved_at',

        // Archive
        'archived_at',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'actual_start_time' => 'datetime',
        'actual_end_time' => 'datetime',
        'handover_date' => 'date',
        'handover_time' => 'datetime',
        'handed_over_at' => 'datetime',
        'daily_report_submitted_at' => 'datetime',
        'reopened_at' => 'datetime',
        'approved_at' => 'datetime',
        'archived_at' => 'datetime',
        'total_sales' => 'decimal:2',
        'net_sales' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'cash_collected' => 'decimal:2',
        'card_payments' => 'decimal:2',
        'aggregator_payments' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'variance' => 'decimal:2',
        'handover_amount' => 'decimal:2',
        'daily_report_submitted' => 'boolean',
        'can_reopen' => 'boolean',
        'handover_status' => 'string',
        'handover_timing' => 'string',
    ];

    // Relationships
    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'branch_manager_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashTransfers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BranchManagerCashTransfer::class, 'branch_manager_shift_id');
    }

    public function reportAggregate(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ShiftReportAggregate::class, 'source_id')
            ->where('source_type', 'branch_manager_shift');
    }

    public function nextManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'next_manager_id');
    }

    public function handoverFrom(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'handover_from');
    }

    public function handoverTo(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'handover_to');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    /**
     * Get all cashier shifts for this manager's shift date and branch
     * جميع شيفتات الكاشيرز في نفس اليوم والبرانش
     */
    public function cashierShifts(): HasManyThrough
    {
        return $this->hasManyThrough(
            CashierShift::class,      // النموذج النهائي
            Shift::class,            // النموذج الوسيط
            'branch_id',             // Foreign key on shifts table
            'shift_id',              // Foreign key on cashier_shifts table
            'branch_id',             // Local key on branch_manager_shifts table
            'id'                     // Local key on shifts table
        )->whereDate('cashier_shifts.shift_date', $this->shift_date);
    }

    /**
     * Get all cashier shifts for this manager's shift (direct relationship)
     * الحصول على جميع شيفتات الكاشيرز في نفس اليوم والبرانش مباشرة
     */
    public function getAllCashierShifts()
    {
        return CashierShift::whereHas('shift', function ($query) {
            $query->where('branch_id', $this->branch_id);
        })
            ->whereDate('shift_date', $this->shift_date)
            ->with(['cashier', 'shift', 'salesBreakdown.aggregator', 'varianceDetails'])
            ->get();
    }

    /**
     * Cashier handovers belonging to this manager shift's workday.
     * Only requests addressed to this assigned manager belong to this workday.
     */
    public function cashierHandovers()
    {
        return CashierShiftHandover::query()
            ->where('handover_to_type', 'branch_manager')
            ->where('handover_to_id', $this->branch_manager_id)
            ->whereHas('cashierShift', function ($query) {
                $query->whereDate('shift_date', $this->shift_date)
                    ->whereHas('shift', function ($q) {
                        $q->where('branch_id', $this->branch_id);
                    });
            });
    }

    // Scopes
    public function scopeToday($query)
    {
        return $query->whereDate('shift_date', today());
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // Methods
    public function canStart(): bool
    {
        return $this->status === 'not_started' && $this->shift_date->isToday();
    }

    public function canEnd(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        // A handover addressed to this manager must be resolved first.
        // Cashiers who ended their shift without a handover (endShiftOnly) create no record here,
        // so they do not block the manager from ending the workday.
        $pendingHandovers = $this->cashierHandovers()
            ->where('status', 'pending')
            ->count();

        if ($pendingHandovers > 0) {
            return false;
        }

        // A handover addressed to this manager must not be in a rejected state
        // (rejected_final means manager permanently rejected and it was not resolved).
        $rejectedFinalHandovers = $this->cashierHandovers()
            ->where('status', 'rejected_final')
            ->count();

        return $rejectedFinalHandovers === 0;
    }

    public function getProgressPercentage(): float
    {
        if (! $this->actual_start_time) {
            return 0;
        }

        $totalMinutes = 8 * 60; // 8 hours shift
        $elapsedMinutes = $this->actual_start_time->diffInMinutes(Carbon::now());

        return min(($elapsedMinutes / $totalMinutes) * 100, 100);
    }

    public function getHandoverSummary(): array
    {
        $handovers = $this->cashierHandovers()->get();

        $summary = [
            'total_handovers' => $handovers->count(),
            'approved' => $handovers->where('status', 'approved')->count(),
            'pending' => $handovers->where('status', 'pending')->count(),
            'rejected' => $handovers->whereIn('status', ['rejected', 'rejected_final'])->count(),
            'total_amount' => $handovers->where('status', 'approved')->sum('handover_amount'),
        ];

        return $summary;
    }

    /**
     * Get pending handovers count
     */
    public function getPendingHandoversCount(): int
    {
        return $this->cashierHandovers()
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Get all approved cashier handovers
     */
    public function getApprovedHandovers()
    {
        return $this->cashierHandovers()
            ->where('status', 'approved')
            ->with(['cashierShift.cashier', 'cashierShift.shift'])
            ->get();
    }
}
