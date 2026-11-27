<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\AsabUser;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $routeName = $route?->uri();
        $protectedBySanctum = collect($route?->gatherMiddleware() ?? [])
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'auth:sanctum'));

        // This runs in the API group before route middleware. Inspect the bearer
        // record directly so Sanctum does not update last_used_at before the
        // existing admin token-family guard has validated it.
        $bearer = $protectedBySanctum ? $request->bearerToken() : null;
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        $user = $token?->tokenable;

        // Preserve actingAs/session-authenticated route behavior (and the
        // route-level Admin middleware) when no bearer was supplied.
        if ($protectedBySanctum && $bearer === null) {
            $user = $request->user();
        }

        if ($token instanceof PersonalAccessToken
            && $token->name === 'first-login-token'
            && ! str_ends_with((string) $routeName, '/auth/logout')
            && ! str_ends_with((string) $routeName, '/logout')) {
            return response()->json([
                'ok' => false,
                'code' => 'FIRST_LOGIN_SESSION_RESTRICTED',
                'message' => 'Complete password setup before using this session.',
            ], 403);
        }

        if ($user instanceof AsabUser
            && $user->must_change_password
            && ! in_array($routeName, ['api/v1/auth/me', 'api/v1/auth/change-password', 'api/v1/auth/logout'], true)) {
            return response()->json([
                'ok' => false,
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => 'You must change your temporary password before continuing.',
                'messageAr' => 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.',
            ], 403);
        }

        return $next($request);
    }
}
