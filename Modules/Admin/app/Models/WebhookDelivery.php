<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A single webhook delivery attempt (FE completion request §3.5). */
class WebhookDelivery extends Model
{
    use HasUuids;

    protected $table = 'asab_webhook_deliveries';

    protected $fillable = [
        'webhook_id', 'event', 'payload', 'status_code', 'response', 'error', 'attempted_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status_code' => 'integer',
        'attempted_at' => 'datetime',
    ];
}
