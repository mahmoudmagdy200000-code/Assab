<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class Reminder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_reminders';

    protected $fillable = [
        'company_id', 'public_id', 'branch_id', 'report_type', 'module_key', 'required_by',
        'days_missing', 'urgency', 'reminder_status', 'response', 'sent_at', 'responded_at', 'message',
    ];

    protected $casts = [
        'required_by' => 'datetime',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'days_missing' => 'integer',
    ];
}
