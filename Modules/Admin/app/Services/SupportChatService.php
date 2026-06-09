<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\SupportChatMessage;
use Modules\Admin\Models\SupportChatSession;

/**
 * Live support chat (FE completion request §2.1). Sessions queue until an agent
 * picks them up; turns broadcast on the private `chat.session.{id}` channel.
 */
class SupportChatService
{
    /** Estimated handling time per queued session (seconds). */
    private const WAIT_PER_POSITION = 120;

    public function __construct(private readonly RealtimeBroadcaster $rt) {}

    public function start(AsabUser $user): SupportChatSession
    {
        $ahead = SupportChatSession::where('company_id', $user->company_id)
            ->where('status', 'queued')->count();
        $position = $ahead + 1;

        return SupportChatSession::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'status' => 'queued',
            'queue_position' => $position,
            'estimated_wait_seconds' => $position * self::WAIT_PER_POSITION,
        ]);
    }

    public function postMessage(SupportChatSession $session, string $authorType, ?string $authorId, string $text): SupportChatMessage
    {
        $msg = SupportChatMessage::create([
            'session_id' => $session->id,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'text' => $text,
            'sent_at' => now(),
        ]);

        $this->rt->chatMessageNew($msg);

        return $msg;
    }

    public function close(SupportChatSession $session, string $closedBy, ?string $reason = null): void
    {
        $session->update(['status' => 'closed', 'closed_by' => $closedBy, 'close_reason' => $reason, 'closed_at' => now()]);
        $this->rt->chatSessionClosed($session->id, $closedBy, $reason);
    }
}
