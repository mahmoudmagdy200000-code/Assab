<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Inbound payment-gateway webhook (idempotent by event_id) — COMPANY_DASHBOARD_API_SPEC.md §3.2. */
class WebhookEvent extends Model
{
    use HasUuids;

    protected $table = 'asab_webhook_events';

    public $timestamps = false;

    protected $fillable = [
        'provider', 'event_id', 'event_type', 'payload', 'signature',
        'signature_verified', 'processed_at', 'processing_error', 'received_at',
    ];

    protected $casts = [
        'payload' => 'array', 'signature_verified' => 'boolean',
        'processed_at' => 'datetime', 'received_at' => 'datetime',
    ];
}
