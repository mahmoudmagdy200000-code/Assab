<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AutoReminderRule extends Model
{
    use HasUuids;

    protected $table = 'asab_auto_reminder_rules';

    protected $fillable = ['company_id', 'module', 'trigger_hour', 'repeat_hours', 'active'];

    protected $casts = ['repeat_hours' => 'integer', 'active' => 'boolean'];
}
