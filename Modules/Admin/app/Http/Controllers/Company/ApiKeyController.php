<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\ApiKey;
use Modules\Admin\Services\ApiKeyService;

/**
 * Tenant API-key management (FE completion request §3.3). The plaintext key is
 * returned exactly once, on creation.
 */
class ApiKeyController extends AsabController
{
    public function __construct(private readonly ApiKeyService $keys) {}

    /** GET /company/me/api-keys */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $rows = ApiKey::where('company_id', $request->user()->company_id)
                ->whereNull('revoked_at')->orderByDesc('created_at')->get();

            return $this->listResponse($rows->map(fn (ApiKey $k) => $this->keys->present($k))->all());
        });
    }

    /** POST /company/me/api-keys → key returned ONCE. */
    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:120',
                'scopes' => 'required|array|min:1',
                'scopes.*' => 'in:'.implode(',', ApiKeyService::SCOPES),
                'expiresInDays' => 'sometimes|nullable|integer|min:1|max:3650',
            ]);

            $result = $this->keys->create(
                $request->user()->company_id,
                $data['name'],
                $data['scopes'],
                $data['expiresInDays'] ?? null,
                $request->user()->id,
            );
            $key = $result['model'];

            return $this->created([
                'id' => $key->id,
                'name' => $key->name,
                'key' => $result['plainKey'],
                'scopes' => $key->scopes ?? [],
                'expiresAt' => optional($key->expires_at)->toIso8601String(),
            ]);
        });
    }

    /** DELETE /company/me/api-keys/{id} → 204 */
    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $key = ApiKey::where('company_id', $request->user()->company_id)->findOrFail($id);
            $this->keys->revoke($key);

            return $this->noContent();
        });
    }
}
