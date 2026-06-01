<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Personal reminders for head + accountant — COMPANY_DASHBOARD_API_SPEC.md §5.2/§5.3. */
class PersonalReminder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_personal_reminders';

    protected $fillable = ['company_id', 'user_id', 'role_key', 'title', 'body', 'type', 'priority', 'due_at', 'done'];

    protected $casts = ['due_at' => 'datetime', 'done' => 'boolean'];
}
