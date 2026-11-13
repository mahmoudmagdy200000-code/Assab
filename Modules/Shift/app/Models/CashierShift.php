<?php

namespace Modules\Shift\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Helpers\ShiftHelper;
use Modules\Shift\Services\CashierShiftStartService;

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
        'operational_chain_id',
        'operational_ended_at',
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
        'operational_ended_at' => 'datetime',
        'actual_end_time' => 'datetime',
        'handed_over_at' => 'datetime',
        'reassigned_at' => 'datetime',
        'status' => \Modules\Shift\Enums\ShiftStatus::class,
    ];

    // Default relationships to load
    protected $with = ['cashier', 'shift', 'nextCashier', 'notWorkedHistory'];

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
        return $this->hasOne(CashierShiftHandover::class, 'cashier_shift_id')
            ->active()->orderByDesc('created_at')->orderByDesc('id');
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

    public function notWorkedHistory(): HasMany
    {
        return $this->history()->where('action', 'marked_not_worked');
    }

    public function notWorkedOutcome(): ?array
    {
        // One constrained relation; never infer no-work from ordinary cancellation.
        $fact = $this->notWorkedHistory->first();
        if (! $fact) {
            return null;
        }
        return [
            'outcome' => 'not_worked', 'reason' => $fact->notes,
            'recorded_by' => (string) $fact->performed_by,
            'recorded_by_type' => $fact->performed_by_type,
            'recorded_at' => $fact->created_at?->toIso8601String(),
        ];
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
    public function scopeCurrentOperational($query)
    {
        return $query->whereNotNull('actual_start_time')->whereNull('operational_ended_at')
            ->whereNull('actual_end_time')->whereNotIn('status', [ShiftStatus::COMPLETED->value, ShiftStatus::CANCELED->value]);
    }

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

    /**
     * Seven calendar days plus older pending shifts whose start window is still open.
     * Explicit historical/future date filters remain exact. Carry-over IDs are
     * resolved before pagination using the same clock/rule as the start endpoint.
     */
    public function scopePendingDateWindow(Builder $query, ?string $dateFrom = null, ?string $dateTo = null): Builder
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Riyadh'));
        $today = $now->toDateString();
        $from = $dateFrom ?? $today;
        $to = $dateTo ?? $now->copy()->addDays(6)->toDateString();
        $carryOverIds = [];

        if ($from === $today && $to >= $today) {
            // A schedule may span midnight and its two-hour grace period can
            // cross another midnight. No older business date can still be open.
            // Clone preserves caller ownership/branch predicates; never fetch
            // the entire historical list or filter a page after pagination.
            $carryOverIds = (clone $query)
                ->reorder()
                ->withOnly('shift')
                ->upcoming()
                ->whereNull('actual_start_time')
                ->whereNull('operational_ended_at')
                ->whereDate('shift_date', '>=', $now->copy()->subDays(2)->toDateString())
                ->whereDate('shift_date', '<', $today)
                ->get()
                ->filter(fn (CashierShift $shift) => ShiftHelper::getShiftStartWindow($shift, $now)['can_start'])
                ->modelKeys();
        }

        return $query->where(function (Builder $dates) use ($from, $to, $carryOverIds) {
            $dates->where(function (Builder $range) use ($from, $to) {
                $range->whereDate('shift_date', '>=', $from)
                    ->whereDate('shift_date', '<=', $to);
            });

            if ($carryOverIds !== []) {
                $dates->orWhereIn('cashier_shifts.id', $carryOverIds);
            }
        });
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
        $started = app(CashierShiftStartService::class)->startShift($this);
        $this->setRawAttributes($started->getAttributes(), true);
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
     * Legacy channel difference between total sales and collected payments breakdown.
     * Retained strictly for legacy display/backcompat; must NOT drive shortage, surplus,
     * liability, approval, correction, or daily-close readiness.
     */
    public function calculateChannelDifference(): float
    {
        $totalCollected = (float) $this->cash_collected + (float) $this->card_payments;

        // Add delivery apps (aggregators) payments
        $deliveryAppsTotal = (float) $this->salesBreakdown()->sum('amount');

        return (float) $this->total_sales - ($totalCollected + $deliveryAppsTotal);
    }

    /**
     * Authoritative financial variance from the CURRENT report revision's stored physical-count evidence.
     * Negative represents shortage, positive represents surplus.
     */
    public function calculateVariance(): float
    {
        $count = app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($this->id);
        if ($count !== null) {
            return $count->variance_halalas / 100;
        }

        return $this->calculateChannelDifference();
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
        return abs($this->calculateVariance()) > 0.001;
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
