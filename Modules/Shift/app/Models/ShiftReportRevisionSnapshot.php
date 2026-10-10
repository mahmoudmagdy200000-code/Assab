<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ShiftReportRevisionSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'shift_report_revision_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'schema_version' => 'integer',
        'snapshot_data' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Shift report revision snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Shift report revision snapshots are immutable.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }
}
