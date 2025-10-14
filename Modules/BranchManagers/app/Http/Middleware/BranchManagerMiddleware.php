<?php

namespace Modules\BranchManagers\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Symfony\Component\HttpFoundation\Response;
class BranchManagerMiddleware
{
    /**
     * Handle an incoming request.
     */
   public function handle(Request $request, Closure $next): Response
    {

        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (!auth()->user() instanceof BranchManager) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Branch Manager access required.'
            ], 403);
        }

        $manager = auth()->user();

        if (!$manager->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact administrator.'
            ], 403);
        }

        if ($manager->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact administrator.'
            ], 403);
        }

        if ($request->user()?->role !== 'branch_manager') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $next($request);
    }
}
