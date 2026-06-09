<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Background-job run record for admin monitoring (FE completion request §3.6). */
class JobRun extends Model
{
    use HasUuids;

    protected $table = 'asab_job_runs';

    protected $fillable = [
        'type', 'status', 'company_id', 'branch_id', 'triggered_by_id', 'triggered_by_name',
        'progress_pct', 'attempt_count', 'last_error', 'ref_type', 'ref_id',
        'queued_at', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'progress_pct' => 'integer',
        'attempt_count' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
