<?php

namespace Modules\Supplier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Supplier\Models\Supplier;
use Symfony\Component\HttpFoundation\Response;

class SupplierMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum authenticates via token, so we check the authenticated user
        $user = auth()->user();

        // Check authentication
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Check if user is a Supplier instance
        if (! $user instanceof Supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Supplier access required.',
            ], 403);
        }

        // Check account status
        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact administrator.',
            ], 403);
        }

        // Update last seen
        $user->updateLastSeen();

        return $next($request);
    }
}
