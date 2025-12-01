<?php

namespace Modules\Shift\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Branch\Models\Branch;

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
     * Get cashier shifts through shift relationship
     * باستخدام HasManyThrough للوصول إلى cashier_shifts عبر shifts
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
     * Get cashier handovers for this manager's shift
     */
    public function cashierHandovers(): HasMany
    {
        return $this->hasMany(CashierShiftHandover::class, 'handover_to_id', 'branch_manager_id')
            ->where('handover_to_type', 'branch_manager')
            ->whereDate('handover_date', $this->shift_date);
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

    public function scopeWithCashierHandovers($query)
    {
        return $query->with(['cashierHandovers' => function($q) {
            $q->with(['cashierShift.cashier', 'cashierShift.shift', 'approvedBy']);
        }]);
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

        // Check if all cashier handovers are approved
        $pendingHandovers = $this->cashierHandovers()->where('status', 'pending')->count();
        return $pendingHandovers === 0;
    }

    public function getProgressPercentage(): float
    {
        if (!$this->actual_start_time) return 0;

        $totalMinutes = 8 * 60; // 8 hours shift
        $elapsedMinutes = Carbon::now()->diffInMinutes($this->actual_start_time);

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
        return $this->cashierHandovers()->where('status', 'pending')->count();
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
