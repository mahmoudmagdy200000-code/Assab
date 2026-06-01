<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** File attached to a support ticket/message — COMPANY_DASHBOARD_API_SPEC.md §3.5. */
class TicketAttachment extends Model
{
    use HasUuids;

    protected $table = 'asab_ticket_attachments';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'message_id', 'filename', 'mime_type', 'size', 'storage_key', 'uploaded_by_id', 'uploaded_at',
    ];

    protected $casts = ['size' => 'integer', 'uploaded_at' => 'datetime'];
}
