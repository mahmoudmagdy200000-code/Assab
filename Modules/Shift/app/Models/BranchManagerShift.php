<?php

namespace Modules\Shift\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Branch\Models\Branch;
use Modules\Shift\Enums\ShiftStatus;

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
        'total_sales',
        'net_sales',
        'vat_amount',
        'cash_collected',
        'card_payments',
        'aggregator_payments',
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'variance',
        'next_manager_id',
        'handed_over_at',
        'handover_notes',
        'total_cashier_shifts',
        'completed_cashier_shifts',
        'pending_cashier_shifts',
        'daily_report_submitted',
        'daily_report_submitted_at',
        'daily_report_notes',
        'can_reopen',
        'reopened_at',
        'reopen_reason',
        'approved_by',
        'approved_at',
        'archived_at',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'actual_start_time' => 'datetime',
        'actual_end_time' => 'datetime',
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
        'daily_report_submitted' => 'boolean',
        'can_reopen' => 'boolean',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'approved_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(\Modules\Shift\Models\Shift::class, 'shift_id');
    }

    public function cashierShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class, 'shift_date', 'shift_date')
            ->whereHas('shift', function ($q) {
                $q->where('branch_id', $this->branch_id);
            });
    }

    // Scopes
    public function scopeNotStarted($query)
    {
        return $query->where('status', 'not_started');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('shift_date', today());
    }

    public function scopeForManager($query, string $managerId)
    {
        return $query->where('branch_manager_id', $managerId);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('daily_report_submitted', true);
    }

    public function scopePendingSubmission($query)
    {
        return $query->where('status', 'completed')
            ->where('daily_report_submitted', false);
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    // Methods
    public function startShift(): void
    {
        $this->update([
            'status' => 'in_progress',
            'actual_start_time' => now(),
        ]);
    }

    public function endShift(array $data = []): void
    {
        $this->update([
            'status' => 'completed',
            'actual_end_time' => now(),
            'total_sales' => $data['total_sales'] ?? $this->total_sales,
            'net_sales' => $data['net_sales'] ?? $this->net_sales,
            'vat_amount' => $data['vat_amount'] ?? $this->vat_amount,
            'cash_collected' => $data['cash_collected'] ?? $this->cash_collected,
            'card_payments' => $data['card_payments'] ?? $this->card_payments,
            'aggregator_payments' => $data['aggregator_payments'] ?? $this->aggregator_payments,
        ]);
    }

    public function calculateVariance(): float
    {
        $totalCollected = $this->cash_collected + $this->card_payments + $this->aggregator_payments;
        return $this->total_sales - $totalCollected;
    }

    public function hasVariance(): bool
    {
        return abs($this->calculateVariance()) > 0.01;
    }

    public function updateStatistics(): void
    {
        $shifts = $this->cashierShifts;

        $this->update([
            'total_cashier_shifts' => $shifts->count(),
            'completed_cashier_shifts' => $shifts->where('status', ShiftStatus::COMPLETED)->count(),
            'pending_cashier_shifts' => $shifts->whereIn('status', [
                ShiftStatus::NOT_STARTED,
                ShiftStatus::IN_PROGRESS
            ])->count(),
        ]);
    }

    public function aggregateSalesData(): void
    {
        $completedShifts = $this->cashierShifts()
            ->where('status', ShiftStatus::COMPLETED)
            ->get();

        $this->update([
            'total_sales' => $completedShifts->sum('total_sales'),
            'net_sales' => $completedShifts->sum('net_sales'),
            'vat_amount' => $completedShifts->sum('vat_amount'),
            'cash_collected' => $completedShifts->sum('cash_collected'),
            'card_payments' => $completedShifts->sum('card_payments'),
            'aggregator_payments' => $completedShifts->sum(function ($shift) {
                return $shift->salesBreakdown->sum('amount');
            }),
        ]);
    }

    public function canStart(): bool
    {
        return $this->status === 'not_started' && $this->shift_date->isToday();
    }

    public function canEnd(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        // Section A requirement: cannot end until End Time is reached
        if ($this->shift && $this->shift->end_time) {
            $endDateTime = Carbon::parse($this->shift_date->format('Y-m-d') . ' ' . $this->shift->end_time);
            return now()->greaterThanOrEqualTo($endDateTime);
        }

        return true;
    }

    public function getProgressPercentage(): float
    {
        if (!$this->actual_start_time || $this->total_cashier_shifts === 0) {
            return 0;
        }

        return ($this->completed_cashier_shifts / $this->total_cashier_shifts) * 100;
    }

    public function hasPendingHandoffs(): bool
    {
        return $this->cashierShifts()
            ->whereHas('handoverStatus', function ($q) {
                $q->where('manager_approval_status', 'pending');
            })
            ->exists();
    }

    public function canSubmitDailyReport(): bool
    {
        return $this->status === 'completed'
            && !$this->daily_report_submitted
            && !$this->hasPendingHandoffs();
    }

    public function canReopenShift(): bool
    {
        return $this->can_reopen
            && $this->daily_report_submitted
            && $this->shift_date->isToday();
    }
}
