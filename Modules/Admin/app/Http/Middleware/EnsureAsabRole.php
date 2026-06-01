<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\AsabUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for ASAB endpoints. Usage: `asab.role:admin` or `asab.role:accountant,head`.
 * Zero-trust: no blanket allow — the user must hold one of the listed roles.
 */
class EnsureAsabRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof AsabUser) {
            return $this->deny('UNAUTHORIZED', 'Authentication required', 'يلزم تسجيل الدخول', 401);
        }

        if (! $user->hasAnyAsabRole($roles)) {
            return $this->deny('WRONG_ROLE', 'You do not have permission to access this resource', 'ليس لديك صلاحية للوصول', 403);
        }

        if ($user->status !== 'active') {
            return $this->deny('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
        }

        return $next($request);
    }

    private function deny(string $code, string $message, string $messageAr, int $status): Response
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'messageAr' => $messageAr],
            'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
        ], $status);
    }
}
