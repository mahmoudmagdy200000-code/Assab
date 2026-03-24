<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogThrottledRequestsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 429) {
            Log::warning('Request rate-limited', [
                'route' => $request->path(),
                'method' => $request->method(),
                'user_id' => optional($request->user())->getAuthIdentifier(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}
