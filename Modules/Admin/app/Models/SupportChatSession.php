<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Live support chat session (FE completion request §2.1). */
class SupportChatSession extends Model
{
    use HasUuids;

    protected $table = 'asab_support_chat_sessions';

    protected $fillable = [
        'company_id', 'user_id', 'agent_user_id', 'agent_name', 'status',
        'queue_position', 'estimated_wait_seconds', 'closed_by', 'close_reason', 'closed_at',
    ];

    protected $casts = [
        'queue_position' => 'integer',
        'estimated_wait_seconds' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(SupportChatMessage::class, 'session_id')->orderBy('sent_at');
    }
}
