<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Str;
use Modules\Admin\Models\ApiKey;

/**
 * API keys for 3rd-party integrations (FE completion request §3.3). The full key
 * is shown once; only its SHA-256 hash is stored.
 */
class ApiKeyService
{
    public const SCOPES = ['operations:read', 'operations:write', 'reports:read', 'inventory:read', 'inventory:write'];

    /**
     * @return array{model: ApiKey, plainKey: string}
     */
    public function create(string $companyId, string $name, array $scopes, ?int $expiresInDays, ?string $createdById): array
    {
        $random = Str::random(40);
        $plainKey = 'asab_live_'.$random;
        $prefix = 'asab_live_'.substr($random, 0, 6);

        $model = ApiKey::create([
            'company_id' => $companyId,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plainKey),
            'scopes' => array_values(array_intersect($scopes, self::SCOPES)),
            'expires_at' => $expiresInDays ? now()->addDays($expiresInDays) : null,
            'created_by_id' => $createdById,
        ]);

        return ['model' => $model, 'plainKey' => $plainKey];
    }

    /** Authenticate a raw key string; bumps last_used_at on success. */
    public function authenticate(string $plainKey): ?ApiKey
    {
        $key = ApiKey::where('key_hash', hash('sha256', $plainKey))->first();
        if (! $key || ! $key->isUsable()) {
            return null;
        }
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key;
    }

    public function revoke(ApiKey $key): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
    }

    /** @return array<string, mixed> */
    public function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes ?? [],
            'lastUsedAt' => optional($key->last_used_at)->toIso8601String(),
            'createdAt' => optional($key->created_at)->toIso8601String(),
            'expiresAt' => optional($key->expires_at)->toIso8601String(),
        ];
    }
}
