<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ShiftVarianceReviewEvidence extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'shift_variance_review_evidence';

    protected $guarded = ['id'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Shift variance review evidence records are immutable.'));
        static::deleting(fn () => throw new LogicException('Shift variance review evidence records are immutable.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
