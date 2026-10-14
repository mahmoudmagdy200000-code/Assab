<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Support\AsabResponse;
use Symfony\Component\HttpFoundation\Response;

/** Enforce the family bearer boundary before Sanctum authentication side effects. */
class EnsureAdminAccessToken
{
    use AsabResponse;

    public function handle(Request $request, Closure $next, string $mode = ''): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;

        // Missing/invalid proof remains the existing guard/controller's responsibility.
        // Genuinely familyless tokens retain their legacy authentication contract.
        if (! $token || $token->session_id === null) {
            return $next($request);
        }

        // Inspect stored proof directly: Sanctum discards expired bearers, and a
        // refresh bearer must be rejected even with an otherwise valid D08 body.
        $user = $token->tokenable;
        if (! $user instanceof AsabUser
            || $token->tokenable_type !== $user->getMorphClass()
            || (string) $token->tokenable_id !== (string) $user->getKey()
            || $token->name !== 'access'
            || $token->abilities !== ['admin:access']) {
            return $this->deny();
        }

        $session = AdminSession::where('id', $token->session_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $session || ! $session->isActive()
            || (string) $session->current_access_token_id !== (string) $token->id
            || $token->expires_at === null) {
            return $this->deny();
        }

        if (now()->greaterThanOrEqualTo($token->expires_at)) {
            // An expired, correctly identified Access bearer may fall back to
            // D08. The unchanged controller/service verifies the body secret.
            if ($mode !== 'logout' || ! is_string($request->input('refreshToken'))
                || trim($request->input('refreshToken')) === '') {
                return $this->deny();
            }

            // At the exact expiry boundary Sanctum's isPast() can still accept
            // the bearer. Remove expired Access proof so logout uses D08 only.
            $request->headers->remove('Authorization');
        }

        return $next($request);
    }

    private function deny(): Response
    {
        return $this->fail('INVALID_TOKEN', 'Invalid access token', 'رمز الدخول غير صالح', [], 401);
    }
}
