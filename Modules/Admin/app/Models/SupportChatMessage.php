<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A single turn in a support chat session (FE completion request §2.1). */
class SupportChatMessage extends Model
{
    use HasUuids;

    protected $table = 'asab_support_chat_messages';

    protected $fillable = ['session_id', 'author_type', 'author_id', 'text', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function session()
    {
        return $this->belongsTo(SupportChatSession::class, 'session_id');
    }
}
