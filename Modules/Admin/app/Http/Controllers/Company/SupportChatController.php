<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\SupportChatMessage;
use Modules\Admin\Models\SupportChatSession;
use Modules\Admin\Services\SupportChatService;

/**
 * Live support chat (FE completion request §2.1). Replaces the FE placeholder
 * alert with a real, tenant-isolated session backed by realtime on
 * chat.session.{sessionId}.
 */
class SupportChatController extends AsabController
{
    public function __construct(private readonly SupportChatService $chat) {}

    /** POST /company/me/support/chat/start */
    public function start(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $session = $this->chat->start($request->user());

            return $this->created([
                'sessionId' => $session->id,
                'agentName' => $session->agent_name,
                'queuePosition' => $session->queue_position,
                'estimatedWaitSeconds' => $session->estimated_wait_seconds,
            ]);
        });
    }

    /** GET /company/me/support/chat/{sessionId} */
    public function show(Request $request, string $sessionId): JsonResponse
    {
        return $this->run(function () use ($request, $sessionId) {
            $session = $this->findOwned($request, $sessionId);

            return $this->ok([
                'sessionId' => $session->id,
                'status' => $session->status,
                'messages' => $session->messages->map(fn (SupportChatMessage $m) => [
                    'id' => $m->id,
                    'authorType' => $m->author_type,
                    'text' => $m->text,
                    'sentAt' => optional($m->sent_at)->toIso8601String(),
                ])->all(),
            ]);
        });
    }

    /** POST /company/me/support/chat/{sessionId}/message */
    public function message(Request $request, string $sessionId): JsonResponse
    {
        return $this->run(function () use ($request, $sessionId) {
            $session = $this->findOwned($request, $sessionId);
            $data = $request->validate(['text' => 'required|string|min:1|max:4000']);
            $msg = $this->chat->postMessage($session, 'user', $request->user()->id, $data['text']);

            return $this->created([
                'id' => $msg->id,
                'sentAt' => optional($msg->sent_at)->toIso8601String(),
            ]);
        });
    }

    /** POST /company/me/support/chat/{sessionId}/close → 204 */
    public function close(Request $request, string $sessionId): JsonResponse
    {
        return $this->run(function () use ($request, $sessionId) {
            $session = $this->findOwned($request, $sessionId);
            $this->chat->close($session, 'user');

            return $this->noContent();
        });
    }

    /** Tenant + ownership isolation: the opener (or assigned agent) only. */
    private function findOwned(Request $request, string $sessionId): SupportChatSession
    {
        $user = $request->user();

        return SupportChatSession::where('company_id', $user->company_id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('agent_user_id', $user->id))
            ->findOrFail($sessionId);
    }
}
