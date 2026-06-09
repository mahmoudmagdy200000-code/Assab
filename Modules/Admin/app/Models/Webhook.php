<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Tenant-registered outbound webhook (FE completion request §3.5). */
class Webhook extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_webhooks';

    protected $fillable = [
        'company_id', 'url', 'events', 'secret', 'secret_prefix',
        'is_active', 'description', 'last_triggered_at', 'failure_count', 'created_by_id',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id')->latest('attempted_at');
    }
}
