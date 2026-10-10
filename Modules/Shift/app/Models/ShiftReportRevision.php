<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** S1-08 stable report identity only; snapshots and correction history remain S1-11. */
class ShiftReportRevision extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['revision_number' => 'integer'];

    public function aggregate(): BelongsTo
    {
        return $this->belongsTo(ShiftReportAggregate::class, 'report_aggregate_id');
    }
}
