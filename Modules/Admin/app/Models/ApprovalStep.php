<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApprovalStep extends Model
{
    use HasUuids;

    protected $table = 'asab_approval_steps';

    protected $fillable = [
        'operation_id', 'stage_id', 'action', 'actor_user_id', 'actor_label', 'note', 'meta', 'occurred_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];
}
