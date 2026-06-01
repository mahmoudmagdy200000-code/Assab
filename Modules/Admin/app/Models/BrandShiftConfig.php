<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Per-brand shift configuration — COMPANY_DASHBOARD_API_SPEC.md §5.3.9. */
class BrandShiftConfig extends Model
{
    use HasUuids;

    protected $table = 'asab_brand_shift_configs';

    protected $fillable = ['brand_id', 'num_shifts', 'duration_hours', 'first_shift_start', 'shifts'];

    protected $casts = ['num_shifts' => 'integer', 'duration_hours' => 'integer', 'shifts' => 'array'];
}
