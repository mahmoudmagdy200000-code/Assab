<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Services\ApiKeyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates 3rd-party integration requests by `Authorization: Bearer asab_live_…`
 * and enforces a required scope (FE completion request §3.3). Attach as
 * `asab.apikey:operations:read`. Sets `api_key` / `api_company_id` request attributes.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer || ! str_starts_with($bearer, 'asab_live_')) {
            return $this->fail('API_KEY_REQUIRED', 'A valid API key is required', 'مطلوب مفتاح API صالح', 401);
        }

        $key = $this->keys->authenticate($bearer);
        if (! $key) {
            return $this->fail('INVALID_API_KEY', 'Invalid or expired API key', 'مفتاح API غير صالح أو منتهٍ', 401);
        }

        if ($scope !== null && ! in_array($scope, $key->scopes ?? [], true)) {
            return $this->fail('INSUFFICIENT_SCOPE', "Missing required scope: {$scope}", 'صلاحية المفتاح غير كافية', 403, ['requiredScope' => $scope]);
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_company_id', $key->company_id);

        return $next($request);
    }

    private function fail(string $code, string $message, string $messageAr, int $status, array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message, 'messageAr' => $messageAr];
        if ($details) {
            $error['details'] = $details;
        }

        return response()->json([
            'error' => $error,
            'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
        ], $status);
    }
}
