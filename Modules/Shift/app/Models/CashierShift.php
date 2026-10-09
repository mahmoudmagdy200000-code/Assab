<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;

class CashierShift extends Model
{
    use HasFactory ,HasUuids;

    protected $fillable = [
        'cashier_id',
        'shift_id',
        'shift_date',
        'status',
        // Two-worlds feedback (WS1a): the dashboard's review decision, mirrored
        // here informationally. Never conflated with `status` (see migration).
        'review_status',
        'reviewed_at',
        'review_reason',
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'variance',
        'total_sales',
        'net_sales',
        'vat_amount',
        'cash_collected',
        'card_payments',
        'pos_receipt',
        'actual_start_time',
        'actual_end_time',
        'next_cashier_id',
        'handed_over_at',
        'handover_notes',
        'original_cashier_id',
        'reassigned_by',
        'reassignment_reason',
        'reassigned_at',
        'assigned_by',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'reviewed_at' => 'datetime',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'variance' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'net_sales' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'cash_collected' => 'decimal:2',
        'card_payments' => 'decimal:2',
        'actual_start_time' => 'datetime',
        'actual_end_time' => 'datetime',
        'handed_over_at' => 'datetime',
        'reassigned_at' => 'datetime',
        'status' => \Modules\Shift\Enums\ShiftStatus::class,
    ];

    // Default relationships to load
    protected $with = ['cashier', 'shift', 'nextCashier'];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\Shift\Database\Factories\CashierShiftFactory::new();
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'assigned_by');
    }

    // Relationships
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'cashier_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function nextCashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'next_cashier_id')->withTrashed();
    }

    public function originalCashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'original_cashier_id');
    }

    public function reassignedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'reassigned_by');
    }

    public function salesBreakdown(): HasMany
    {
        return $this->hasMany(ShiftSalesBreakdown::class);
    }

    public function handoverStatus(): HasOne
    {
        return $this->hasOne(ShiftHandoverStatus::class);
    }

    public function handover(): HasOne
    {
        return $this->hasOne(CashierShiftHandover::class, 'cashier_shift_id');
    }

    public function varianceDetails(): HasMany
    {
        return $this->hasMany(ShiftVarianceDetail::class);
    }

    public function varianceAlerts(): HasMany
    {
        return $this->hasMany(ShiftVarianceAlert::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(CashierShiftHistory::class);
    }

    public function receivedHandoverReceipts(): HasMany
    {
        return $this->hasMany(CashierShiftHandoverReceipt::class, 'receiving_cashier_shift_id');
    }

    public function reportAggregate(): HasOne
    {
        return $this->hasOne(ShiftReportAggregate::class, 'source_id')
            ->where('source_type', 'cashier_shift');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', ShiftStatus::NOT_STARTED->value);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', [
            ShiftStatus::NOT_STARTED->value,
            ShiftStatus::REASSIGNED->value,
        ]);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', ShiftStatus::IN_PROGRESS->value);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', ShiftStatus::COMPLETED)
            ->orderBy('shift_date', 'desc');
    }

    public function scopeReassigned($query)
    {
        return $query->where('status', ShiftStatus::REASSIGNED)
            ->orderBy('reassigned_at', 'desc');
    }

    // Methods
    public function startShift(): void
    {
        $this->update([
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now(),
        ]);

        $this->recordHistory('started', null, [
            'status' => ShiftStatus::IN_PROGRESS->value,
            'actual_start_time' => now(),
        ]);
    }

    public function endShift(array $data): void
    {
        $this->update([
            'status' => ShiftStatus::COMPLETED,
            'actual_end_time' => now(),
            'total_sales' => $data['total_sales'],
            'net_sales' => $data['net_sales'],
            'vat_amount' => $data['vat_amount'],
            'cash_collected' => $data['cash_collected'],
            'card_payments' => $data['card_payments'],
            'pos_receipt' => $data['pos_receipt'] ?? null,
        ]);

        $this->recordHistory('ended', [
            'status' => $this->status->value,
        ], [
            'status' => ShiftStatus::COMPLETED->value,
            'sales_data' => $data,
        ]);
    }

    /**
     * Calculate variance as the difference between total sales and total collected payments
     * Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
     */
    public function calculateVariance(): float
    {
        $totalCollected = $this->cash_collected + $this->card_payments;

        // Add delivery apps (aggregators) payments
        $deliveryAppsTotal = $this->salesBreakdown()->sum('amount');

        return $this->total_sales - ($totalCollected + $deliveryAppsTotal);
    }

    public function recordHistory(string $action, ?array $oldValue, array $newValue): void
    {
        $user = auth()->user();
        $performedBy = $user?->id ?? 0;
        $performedByType = $user
            ? match ($user->getMorphClass()) {
                \Modules\BranchManagers\Models\BranchManager::class => 'branch_manager',
                \Modules\Cashier\Models\Cashier::class => 'cashier',
                default => 'system',
            }
        : 'system';

        $this->history()->create([
            'action' => $action,
            'performed_by' => $performedBy,
            'performed_by_type' => $performedByType,
            'old_value' => $oldValue ? json_encode($oldValue) : null,
            'new_value' => json_encode($newValue),
            'notes' => null,
        ]);
    }

    public function hasVariance(): bool
    {
        return abs($this->calculateVariance()) > 0.01;
    }

    public function creator()
    {
        return $this->belongsTo(\Modules\BranchManagers\Models\BranchManager::class, 'created_by');
    }

    /**
     * Load all necessary relationships for API responses
     */
    public function loadFullRelationships(): self
    {
        return $this->load([
            'cashier',
            'shift.branch',
            // 'shift.creator',
            'nextCashier',
            'originalCashier',
            'reassignedBy',
            'salesBreakdown.aggregator',
            'handoverStatus.reviewedBy',
            'varianceDetails.responsibleCashier',
        ]);
    }
}
