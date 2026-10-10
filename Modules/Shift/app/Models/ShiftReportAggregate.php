<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftReportAggregate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['current_revision_number' => 'integer'];

    public function revisions(): HasMany
    {
        return $this->hasMany(ShiftReportRevision::class, 'report_aggregate_id');
    }
}
