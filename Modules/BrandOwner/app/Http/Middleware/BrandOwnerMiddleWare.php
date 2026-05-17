<?php

namespace Modules\BrandOwner\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BrandOwner\Models\BrandOwner;
use Symfony\Component\HttpFoundation\Response;

class BrandOwnerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (!$user instanceof BrandOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Brand Owner access required.',
            ], 403);
        }

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

        return $next($request);
    }
}
