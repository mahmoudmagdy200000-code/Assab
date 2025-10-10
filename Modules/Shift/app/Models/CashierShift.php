<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Cashier\Models\Cashier;
use Modules\BranchManagers\Models\BranchManager;

class CashierShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_id',
        'shift_id',
        'shift_date',
        'status',
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
    ];

    protected $casts = [
        'shift_date' => 'date',
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
    ];

    // Relationships
    public function cashier()
    {
        return $this->belongsTo(Cashier::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function nextCashier()
    {
        return $this->belongsTo(Cashier::class, 'next_cashier_id');
    }

    public function originalCashier()
    {
        return $this->belongsTo(Cashier::class, 'original_cashier_id');
    }

    public function reassignedBy()
    {
        return $this->belongsTo(BranchManager::class, 'reassigned_by');
    }

    public function salesBreakdown()
    {
        return $this->hasMany(ShiftSalesBreakdown::class);
    }

    public function varianceDetails()
    {
        return $this->hasMany(ShiftVarianceDetail::class);
    }

    public function handoverStatus()
    {
        return $this->hasOne(ShiftHandoverStatus::class);
    }

    public function history()
    {
        return $this->hasMany(CashierShiftHistory::class);
    }

    public function varianceAlerts()
    {
        return $this->hasMany(ShiftVarianceAlert::class);
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

    public function scopeReassigned($query)
    {
        return $query->where('status', 'reassigned');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('shift_date', today());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('shift_date', '>=', today());
    }

    // Helper Methods
    public function calculateVAT()
    {
        if ($this->total_sales > 0) {
            $this->vat_amount = $this->total_sales * 0.15;
            $this->net_sales = $this->total_sales - $this->vat_amount;
            $this->save();
        }
    }

    public function calculateVariance()
    {
        $this->variance = $this->closing_balance - $this->expected_balance;
        $this->save();
        return $this->variance;
    }

    public function hasVariance()
    {
        return $this->variance != 0;
    }

    public function isOverVariance()
    {
        return $this->variance > 0;
    }

    public function isShortVariance()
    {
        return $this->variance < 0;
    }
}
