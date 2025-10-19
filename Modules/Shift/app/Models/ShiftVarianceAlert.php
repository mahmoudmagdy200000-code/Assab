<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Enums\AlertType;

/**
 * Updated ShiftVarianceAlert Model
 */
class ShiftVarianceAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_shift_id',
        'variance_amount',
        'variance_percentage',
        'alert_type',
        'is_acknowledged',
        'acknowledged_by',
        'acknowledged_at',
        'notes',
    ];

    protected $casts = [
        'variance_amount' => 'decimal:2',
        'variance_percentage' => 'decimal:2',
        'alert_type' => AlertType::class,
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    // Relationships
    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(BranchManager::class, 'acknowledged_by');
    }

    // Scopes
    public function scopeAcknowledged($query)
    {
        return $query->where('is_acknowledged', true);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->where('is_acknowledged', false);
    }

    public function scopeByAlertType($query, AlertType $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('alert_type', AlertType::CRITICAL);
    }

    public function scopeMajor($query)
    {
        return $query->where('alert_type', AlertType::MAJOR);
    }

    public function scopeMinor($query)
    {
        return $query->where('alert_type', AlertType::MINOR);
    }

    // Helper Methods
    public function isAcknowledged(): bool
    {
        return $this->is_acknowledged;
    }

    public function isCritical(): bool
    {
        return $this->alert_type === AlertType::CRITICAL;
    }

    public function isMajor(): bool
    {
        return $this->alert_type === AlertType::MAJOR;
    }

    public function isMinor(): bool
    {
        return $this->alert_type === AlertType::MINOR;
    }

    public function acknowledge(int $userId, ?string $notes = null): void
    {
        $this->update([
            'is_acknowledged' => true,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'notes' => $notes ?? $this->notes,
        ]);
    }
}
