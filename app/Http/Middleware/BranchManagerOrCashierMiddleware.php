<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows access for both Branch Managers and Cashiers.
 * Sets manager_branch_id on request for branch-scoped operations.
 */
class BranchManagerOrCashierMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ($user instanceof BranchManager) {
            return $this->handleBranchManager($request, $next, $user);
        }

        if ($user instanceof Cashier) {
            return $this->handleCashier($request, $next, $user);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Branch Manager or Cashier access required.',
        ], 403);
    }

    /**
     * Validate Branch Manager and continue.
     */
    protected function handleBranchManager(Request $request, Closure $next, BranchManager $user): Response
    {
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact administrator.',
            ], 403);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact administrator.',
            ], 403);
        }

        if (!$user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Branch manager is not assigned to any branch. Please contact administrator.',
            ], 403);
        }

        $request->merge(['manager_branch_id' => $user->branch_id]);

        return $next($request);
    }

    /**
     * Validate Cashier and continue.
     */
    protected function handleCashier(Request $request, Closure $next, Cashier $user): Response
    {
        if (!$user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not active. Please contact your manager.',
            ], 403);
        }

        if (!$user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cashier is not assigned to any branch. Please contact administrator.',
            ], 403);
        }

        $request->merge(['manager_branch_id' => $user->branch_id]);

        return $next($request);
    }
}
