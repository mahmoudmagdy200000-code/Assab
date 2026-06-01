<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Company support ticket — COMPANY_DASHBOARD_API_SPEC.md §3.5. */
class SupportTicket extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_support_tickets';

    protected $fillable = [
        'public_id', 'company_id', 'opened_by_id', 'category', 'subject', 'body', 'priority',
        'status', 'assigned_to_id', 'first_response_at', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('created_at');
    }
}
