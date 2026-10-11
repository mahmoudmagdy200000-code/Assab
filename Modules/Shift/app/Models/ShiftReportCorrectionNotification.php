<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftReportCorrectionNotification extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'dispatched_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ShiftReportRevision::class, 'report_revision_id');
    }
}
