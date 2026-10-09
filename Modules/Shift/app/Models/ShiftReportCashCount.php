<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * S1-10: the physical count and server calculation of ONE report revision, in integer halalas.
 * A revision without a row has no count evidence (never an implied 0). Rows are immutable.
 */
class ShiftReportCashCount extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'gross_halalas' => 'integer',
        'cards_halalas' => 'integer',
        'apps_halalas' => 'integer',
        'confirmed_opening_halalas' => 'integer',
        'pending_incoming_counted_halalas' => 'integer',
        'counted_halalas' => 'integer',
        'expected_halalas' => 'integer',
        'variance_halalas' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash count evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Cash count evidence is immutable.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
