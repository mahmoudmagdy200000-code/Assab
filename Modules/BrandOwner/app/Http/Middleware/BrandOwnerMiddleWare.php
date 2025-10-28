<?php

namespace Modules\BrandOwner\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BrandOwnerMiddleWare
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
        if (auth()->check() && auth()->user()->hasRole('brand_owner')) {
            return $next($request);
        }
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
