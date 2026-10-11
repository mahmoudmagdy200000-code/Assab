<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ShiftReportManagerReview extends Model
{
    use HasUuids;

    protected $table = 'shift_report_manager_reviews';

    protected $fillable = [
        'company_id',
        'branch_id',
        'cashier_shift_id',
        'report_revision_id',
        'report_revision_number',
        'status',
        'variance_halalas',
        'has_shortage',
        'reviewer_type',
        'reviewer_id',
        'reviewed_at',
        'reason',
    ];

    protected $casts = [
        'variance_halalas' => 'integer',
        'has_shortage' => 'boolean',
        'reviewed_at' => 'datetime',
        'report_revision_number' => 'integer',
    ];

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function reportRevision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }

    public function reviewer(): MorphTo
    {
        return $this->morphTo();
    }
}
