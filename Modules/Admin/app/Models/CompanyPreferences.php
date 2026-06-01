<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Per-company app preferences (notifications, reminders, integrations) — COMPANY_DASHBOARD_API_SPEC.md §3.6. */
class CompanyPreferences extends Model
{
    use BelongsToTenant;

    protected $table = 'asab_company_preferences';

    protected $primaryKey = 'company_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'notify_on_approval', 'notify_on_rejection', 'notify_on_low_stock',
        'notify_on_sub_expiring', 'auto_reminder_enabled', 'reminder_trigger_hour',
        'reminder_repeat_hours', 'pos_integrations', 'delivery_app_integrations',
    ];

    protected $casts = [
        'notify_on_approval' => 'boolean', 'notify_on_rejection' => 'boolean',
        'notify_on_low_stock' => 'boolean', 'notify_on_sub_expiring' => 'boolean',
        'auto_reminder_enabled' => 'boolean', 'reminder_repeat_hours' => 'integer',
        'pos_integrations' => 'array', 'delivery_app_integrations' => 'array',
    ];
}
