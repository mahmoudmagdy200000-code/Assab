<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ResponsibilityType;
use Modules\Shift\Enums\VarianceType;

/**
 * Updated ShiftVarianceDetail Model
 */
class ShiftVarianceDetail extends Model
{
    use HasFactory , HasUuids;

    protected $fillable = [
        'cashier_shift_id',
        'variance_amount',
        'variance_type',
        'responsibility_type',
        'responsible_cashier_id',
        'assigned_amount',
        'reason',
        'supporting_files',
        'responsibility_status',
        'rejection_reason',
        'reviewed_by_id',
        'reviewed_by_type',
        'reviewed_at',
    ];

    protected $casts = [
        'variance_amount' => 'decimal:2',
        'assigned_amount' => 'decimal:2',
        'variance_type' => VarianceType::class,
        'responsibility_type' => ResponsibilityType::class,
        'supporting_files' => 'array',
        'reviewed_at' => 'datetime',
    ];

    // Relationships
    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function responsibleCashier()
    {
        return $this->belongsTo(Cashier::class, 'responsible_cashier_id');
    }

    // Scopes
    public function scopeByVarianceType($query, VarianceType $type)
    {
        return $query->where('variance_type', $type);
    }

    public function scopeByResponsibilityType($query, ResponsibilityType $type)
    {
        return $query->where('responsibility_type', $type);
    }

    public function scopeWithResponsibleCashier($query)
    {
        return $query->whereNotNull('responsible_cashier_id');
    }

    public function scopeExternalFactors($query)
    {
        return $query->whereNull('responsible_cashier_id');
    }

    // Helper Methods
    public function isOver(): bool
    {
        return $this->variance_type === VarianceType::OVER;
    }

    public function isShort(): bool
    {
        return $this->variance_type === VarianceType::SHORT;
    }

    public function isSelfResponsibility(): bool
    {
        return $this->responsibility_type === ResponsibilityType::I_WAS_RESPONSIBLE;
    }

    public function isSharedResponsibility(): bool
    {
        return in_array($this->responsibility_type, [
            ResponsibilityType::ME_AND_OTHER_FACTORS,
            ResponsibilityType::MIXED_FACTORS,
        ]);
    }

    public function isExternalFactors(): bool
    {
        return $this->responsibility_type === ResponsibilityType::OTHER_FACTORS;
    }

    public function isPending(): bool
    {
        return $this->responsibility_status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->responsibility_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->responsibility_status === 'rejected';
    }

    public function hasSupportingFiles(): bool
    {
        return ! empty($this->supporting_files);
    }

    public function getSupportingFilesUrls(): array
    {
        if (! $this->hasSupportingFiles()) {
            return [];
        }

        return array_map(
            fn ($file) => asset('storage/'.$file),
            $this->supporting_files
        );
    }
}
