<?php

namespace Modules\Cashier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cashier\Models\Cashier;
use Symfony\Component\HttpFoundation\Response;

class CashierMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (!auth()->user() instanceof Cashier) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Cashier access required.'
            ], 403);
        }

        if (!auth()->user()->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not active. Please contact your manager.'
            ], 403);
        }

        return $next($request);
    }
}
