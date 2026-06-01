<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'asab_audit_logs';

    protected $fillable = [
        'company_id', 'actor_user_id', 'actor_label', 'actor_role', 'action',
        'entity_type', 'entity_id', 'description', 'ip', 'user_agent', 'before', 'after', 'occurred_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'occurred_at' => 'datetime',
    ];
}
