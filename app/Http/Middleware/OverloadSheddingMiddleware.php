<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OverloadSheddingMiddleware
{
    public function handle(Request $request, Closure $next, string $scope = 'utility'): Response
    {
        if (! $this->isOverloaded()) {
            return $next($request);
        }

        if ($scope !== 'utility') {
            return $next($request);
        }

        Log::warning('Request shedded due to overload mode', [
            'route' => $request->path(),
            'method' => $request->method(),
            'user_id' => optional($request->user())->getAuthIdentifier(),
            'ip' => $request->ip(),
            'module_scope' => $scope,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'System busy, please retry shortly.',
        ], 429);
    }

    private function isOverloaded(): bool
    {
        return (bool) Cache::get('system:overloaded', false);
    }
}
