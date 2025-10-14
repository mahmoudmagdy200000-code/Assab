<?php

namespace Modules\BranchManagers\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerMiddleware
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
                'message' => 'Unauthenticated'
            ], 401);
        }


        if (!$user instanceof BranchManager) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Branch Manager access required.'
            ], 403);
        }


        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact administrator.'
            ], 403);
        }


        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact administrator.'
            ], 403);
        }


        return $next($request);
    }
}
