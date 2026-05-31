<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\IdempotencyKey as IdempotencyKeyModel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honors the `Idempotency-Key` header on mutations (spec §2). A repeated key
 * within 24h replays the stored response instead of re-running the mutation.
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $existing = IdempotencyKeyModel::query()
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing && $existing->response_body !== null) {
            return response()->json($existing->response_body, $existing->status ?? 200);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 300) {
            IdempotencyKeyModel::query()->updateOrCreate(
                ['key' => $key],
                [
                    'user_id' => optional($request->user())->id,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                    'expires_at' => now()->addDay(),
                ]
            );
        }

        return $response;
    }
}
