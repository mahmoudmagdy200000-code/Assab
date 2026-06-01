<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Message thread on a support ticket — COMPANY_DASHBOARD_API_SPEC.md §3.5. */
class TicketMessage extends Model
{
    use HasUuids;

    protected $table = 'asab_ticket_messages';

    public $timestamps = false;

    protected $fillable = ['ticket_id', 'author_id', 'author_type', 'body', 'is_internal', 'created_at'];

    protected $casts = ['is_internal' => 'boolean', 'created_at' => 'datetime'];
}
