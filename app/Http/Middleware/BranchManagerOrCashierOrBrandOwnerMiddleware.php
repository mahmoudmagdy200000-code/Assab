<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows access for Branch Managers, Cashiers, and Brand Owners.
 * For branch-scoped users (BM/cashier), sets manager_branch_id on the request.
 * Brand Owner has no branch scope.
 */
class BranchManagerOrCashierOrBrandOwnerMiddleware
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
                return response()->json(['success' => false, 'message' => 'Your account is inactive.'], 403);
            }
            if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
                return response()->json(['success' => false, 'message' => 'Your account has been suspended.'], 403);
            }
            if (! $user->branch_id) {
                return response()->json(['success' => false, 'message' => 'Branch manager is not assigned to any branch.'], 403);
            }
            $request->merge(['manager_branch_id' => $user->branch_id]);

            return $next($request);
        }

        if ($user instanceof Cashier) {
            if (method_exists($user, 'isActive') ? ! $user->isActive() : empty($user->is_active)) {
                return response()->json(['success' => false, 'message' => 'Your account is not active.'], 403);
            }
            if (! $user->branch_id) {
                return response()->json(['success' => false, 'message' => 'Cashier is not assigned to any branch.'], 403);
            }
            $request->merge(['manager_branch_id' => $user->branch_id]);

            return $next($request);
        }

        if ($user instanceof BrandOwner) {
            if (! $user->is_active) {
                return response()->json(['success' => false, 'message' => 'Your account is inactive.'], 403);
            }
            if ($user->isSuspended()) {
                return response()->json(['success' => false, 'message' => 'Your account has been suspended.'], 403);
            }

            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized.',
        ], 403);
    }
}
