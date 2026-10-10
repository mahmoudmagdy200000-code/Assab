<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * S1-11: Immutable fine-grained field correction audit row for shift report revisions.
 */
class ShiftReportCorrection extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'previous_revision_number' => 'integer',
        'new_revision_number' => 'integer',
        'old_halalas' => 'integer',
        'new_halalas' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
        static::updating(fn () => throw new LogicException('Report correction evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Report correction evidence is immutable.'));
    }

    public function aggregate(): BelongsTo
    {
        return $this->belongsTo(ShiftReportAggregate::class, 'report_aggregate_id');
    }

    public function previousRevision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'previous_revision_id');
    }

    public function newRevision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'new_revision_id');
    }
}
