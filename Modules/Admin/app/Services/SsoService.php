<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Http;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanySso;

/**
 * Enterprise SSO (FE completion request §3.2). Config CRUD is fully supported;
 * sign-in is implemented for OIDC (authorization-code flow). Full SAML assertion
 * handling is intentionally deferred — config is stored for a future SAML stack.
 */
class SsoService
{
    public function __construct(private readonly AuthService $auth) {}

    public function get(string $companyId): ?CompanySso
    {
        return CompanySso::where('company_id', $companyId)->first();
    }

    public function upsert(string $companyId, array $data): CompanySso
    {
        return CompanySso::updateOrCreate(['company_id' => $companyId], [
            'provider' => $data['provider'],
            'enabled' => $data['enabled'] ?? true,
            'metadata_url' => $data['metadataUrl'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'entity_id' => $data['entityId'] ?? null,
            'x509cert' => $data['x509cert'] ?? null,
            'oidc_issuer' => $data['oidcIssuer'] ?? null,
            'oidc_client_id' => $data['oidcClientId'] ?? null,
            'oidc_client_secret' => $data['oidcClientSecret'] ?? null,
            'default_role' => $data['defaultRole'] ?? 'accountant',
        ]);
    }

    public function disable(string $companyId): void
    {
        CompanySso::where('company_id', $companyId)->delete();
    }

    /** @return array<string, mixed> */
    public function present(CompanySso $sso): array
    {
        return [
            'enabled' => (bool) $sso->enabled,
            'provider' => $sso->provider,
            'metadataUrl' => $sso->metadata_url,
            'entityId' => $sso->entity_id,
            'x509cert' => $sso->x509cert,
            'oidcIssuer' => $sso->oidc_issuer,
            'oidcClientId' => $sso->oidc_client_id,
            'defaultRole' => $sso->default_role,
        ];
    }

    /**
     * OIDC authorization-code callback. The auth code is exchanged directly with
     * the IdP token endpoint over TLS; the id_token claims provision/match a user.
     * (JWKS signature verification via firebase/php-jwt is the production hardening step.)
     *
     * @return array spec auth response { accessToken, refreshToken, expiresIn, user }
     */
    public function handleOidcCallback(string $companyId, string $code, string $redirectUri): array
    {
        $cfg = $this->get($companyId);
        if (! $cfg || ! $cfg->enabled || $cfg->provider !== 'oidc') {
            throw new AsabException('SSO_NOT_CONFIGURED', 'OIDC is not enabled for this company', 'تسجيل الدخول الموحد غير مُفعّل', 409);
        }

        try {
            $disco = Http::timeout(10)->get(rtrim($cfg->oidc_issuer, '/').'/.well-known/openid-configuration')->json();
            $tokenEndpoint = $disco['token_endpoint'] ?? null;
            if (! $tokenEndpoint) {
                throw new AsabException('SSO_DISCOVERY_FAILED', 'Could not resolve the IdP token endpoint', 'تعذّر الوصول لمزود الهوية', 502);
            }

            $token = Http::asForm()->timeout(10)->post($tokenEndpoint, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $cfg->oidc_client_id,
                'client_secret' => $cfg->oidc_client_secret,
            ])->json();

            $claims = $this->decodeIdToken($token['id_token'] ?? '');
            $email = $claims['email'] ?? null;
            if (! $email) {
                throw new AsabException('SSO_NO_EMAIL', 'The IdP did not return an email claim', 'لم يُرجِع مزود الهوية بريداً', 422);
            }
        } catch (AsabException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AsabException('SSO_EXCHANGE_FAILED', 'OIDC token exchange failed', 'فشل تبادل الرموز مع مزود الهوية', 502, ['reason' => $e->getMessage()]);
        }

        $user = $this->findOrProvision($companyId, $email, $claims['name'] ?? $email, $cfg->default_role);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->auth->issueTokens($user);
    }

    private function findOrProvision(string $companyId, string $email, string $name, string $defaultRole): AsabUser
    {
        $user = AsabUser::where('company_id', $companyId)->where('email', $email)->first();
        if ($user) {
            return $user;
        }

        $user = AsabUser::create([
            'company_id' => $companyId,
            'name' => $name,
            'email' => $email,
            'password' => \Illuminate\Support\Str::password(32), // unusable for password login
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => $defaultRole, 'scope' => 'all']);

        return $user;
    }

    /** Decode the JWT payload segment (claims). */
    private function decodeIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return [];
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));

        return json_decode($payload, true) ?: [];
    }
}
