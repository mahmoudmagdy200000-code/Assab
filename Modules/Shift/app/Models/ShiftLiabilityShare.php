<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftLiabilityShare extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['employee_response_status' => 'pending'];

    protected $casts = [
        'amount_halalas' => 'integer',
        'employee_responded_at' => 'datetime',
    ];

    public static function buildLineageKey(string $cashierShiftId, string $responsibleType, string $responsibleId): string
    {
        return "{$cashierShiftId}:{$responsibleType}:{$responsibleId}";
    }

    protected static function booted(): void
    {
        static::creating(function (ShiftLiabilityShare $share): void {
            if (! $share->lineage_key && $share->allocation_id && $share->responsible_type && $share->responsible_id) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('shift_liability_shares', 'lineage_key')) {
                    return;
                }

                $allocation = $share->relationLoaded('allocation')
                    ? $share->allocation
                    : ShiftLiabilityAllocation::find($share->allocation_id);

                if ($allocation) {
                    $share->lineage_key = self::buildLineageKey(
                        $allocation->cashier_shift_id,
                        $share->responsible_type,
                        $share->responsible_id
                    );
                }
            }
        });
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(ShiftLiabilityAllocation::class, 'allocation_id');
    }
}
