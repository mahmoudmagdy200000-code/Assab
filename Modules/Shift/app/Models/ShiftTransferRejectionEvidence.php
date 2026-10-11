<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** S1-10 / D11: append-only physical amount a recipient counted when rejecting a transfer for correction. */
class ShiftTransferRejectionEvidence extends Model
{
    use HasUuids;

    protected $table = 'shift_transfer_rejection_evidence';

    protected $guarded = ['id'];

    protected $casts = [
        'requested_halalas' => 'integer',
        'physical_halalas' => 'integer',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rejection evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Rejection evidence is immutable.'));
    }
}
