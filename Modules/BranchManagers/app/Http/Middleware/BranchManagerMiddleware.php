<?php

namespace Modules\BranchManagers\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class BranchManagerMiddleware
{
    /**
     * Handle an incoming request.
     */
   public function handle(Request $request, Closure $next): Response
    {

        if ($request->user()?->role !== 'branch_manager') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $next($request);
    }
}
