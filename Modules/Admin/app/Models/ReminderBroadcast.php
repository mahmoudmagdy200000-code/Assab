<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Log of a bulk reminder broadcast (MISSING_Dashboard §11.5) — backs broadcastId.
 */
class ReminderBroadcast extends Model
{
    use HasUuids;

    protected $table = 'asab_reminder_broadcasts';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'sender_user_id', 'message_ar', 'message_en',
        'audience', 'branch_ids', 'sent_count', 'failed_count', 'created_at',
    ];

    protected $casts = [
        'branch_ids' => 'array',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'created_at' => 'datetime',
    ];
}
