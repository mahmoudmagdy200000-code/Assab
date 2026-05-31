<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Services\AuditService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every successful admin mutation (non-GET) into audit_logs
 * (BACKEND_API_SPEC.md §6.1.7). DRY alternative to per-method audit calls.
 */
class AuditMutations
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)
            && $response->getStatusCode() < 300
            && $request->user()) {
            $this->audit->record(
                action: strtolower($request->method()).'.'.$this->entityFromPath($request->path()),
                actor: $request->user(),
                entityType: $this->entityFromPath($request->path()),
                description: $request->method().' '.$request->path(),
                request: $request,
            );
        }

        return $response;
    }

    /** Derive a coarse entity label from the request path (e.g. .../admin/companies/{id} → companies). */
    private function entityFromPath(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path), fn ($p) => $p !== '' && $p !== 'api' && $p !== 'v1' && $p !== 'admin'));

        return $parts[0] ?? 'unknown';
    }
}
