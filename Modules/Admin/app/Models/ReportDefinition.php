<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** A saved custom-report definition (FE completion request §2.4). */
class ReportDefinition extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_report_definitions';

    protected $fillable = ['company_id', 'created_by_id', 'name', 'description_ar', 'definition'];

    protected $casts = ['definition' => 'array'];
}
