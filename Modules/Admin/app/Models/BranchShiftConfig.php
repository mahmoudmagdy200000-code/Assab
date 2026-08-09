<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch shift override. Same columns as {@see BrandShiftConfig}; when a row
 * exists for a branch it WINS over its brand's config (meeting 2026-08-09:
 * «تعيين الشفتات يكون إما عن طريق العلامة التجارية أو الفروع»).
 */
class BranchShiftConfig extends Model
{
    use HasUuids;

    protected $table = 'asab_branch_shift_configs';

    protected $fillable = ['branch_id', 'num_shifts', 'duration_hours', 'duration_minutes', 'first_shift_start', 'shifts'];

    protected $casts = [
        'num_shifts' => 'integer',
        'duration_hours' => 'integer',
        'duration_minutes' => 'integer',
        'shifts' => 'array',
    ];
}
