<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Populates the request-scoped TenantContext from the authenticated AsabUser:
 * company_id + the user's primary role scope (brand/restaurant/branch ids,
 * module keys). Aborts when a non-admin user has no company.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof AsabUser) {
            return response()->json([
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required', 'messageAr' => 'يلزم تسجيل الدخول'],
                'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
            ], 401);
        }

        $ctx = app(TenantContext::class);
        $ctx->companyId = $user->company_id;
        $ctx->isAdmin = $user->hasAsabRole('admin');

        $assignment = $user->roleAssignments->first();
        if ($assignment) {
            $ctx->roleKey = $assignment->role_key;
            $ctx->scope = $assignment->scope ?? 'all';
            $ctx->brandIds = $assignment->brand_ids ?? [];
            $ctx->restaurantIds = $assignment->restaurant_ids ?? [];
            $ctx->branchIds = $assignment->branch_ids ?? [];
            $ctx->moduleKeys = $assignment->module_keys ?? [];
        }

        if (! $ctx->isAdmin && ! $ctx->hasTenant()) {
            return response()->json([
                'error' => ['code' => 'WRONG_TENANT', 'message' => 'User is not attached to a company', 'messageAr' => 'المستخدم غير مرتبط بشركة'],
                'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
            ], 403);
        }

        return $next($request);
    }
}
