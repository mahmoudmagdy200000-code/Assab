<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows access for Branch Managers and Brand Owners only.
 *
 * Branch managers are branch-scoped: their branch_id is exposed on the request
 * as `manager_branch_id` so controllers can enforce tenant isolation. Brand
 * owners have no branch scope and see data across all branches.
 */
class BranchManagerOrBrandOwnerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ($user instanceof BranchManager) {
            if (! $user->is_active) {
                return response()->json(['success' => false, 'message' => 'Your account is inactive. Please contact administrator.'], 403);
            }

            if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
                return response()->json(['success' => false, 'message' => 'Your account has been suspended. Please contact administrator.'], 403);
            }

            if (! $user->branch_id) {
                return response()->json(['success' => false, 'message' => 'Branch manager is not assigned to any branch. Please contact administrator.'], 403);
            }

            $request->merge(['manager_branch_id' => $user->branch_id]);

            return $next($request);
        }

        if ($user instanceof BrandOwner) {
            if (! $user->is_active) {
                return response()->json(['success' => false, 'message' => 'Your account is inactive. Please contact administrator.'], 403);
            }

            if ($user->isSuspended()) {
                return response()->json(['success' => false, 'message' => 'Your account has been suspended. Please contact administrator.'], 403);
            }

            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Brand owner or branch manager access required.',
        ], 403);
    }
}
