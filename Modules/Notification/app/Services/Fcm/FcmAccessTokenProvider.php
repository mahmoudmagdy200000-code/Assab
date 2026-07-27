<?php

namespace Modules\Notification\Services\Fcm;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Notification\Exceptions\FcmConfigurationException;

/**
 * Mints Google OAuth2 access tokens for the FCM HTTP v1 API from the
 * service-account key (RFC 7523 JWT bearer flow).
 *
 * The legacy `key=AAAA...` server key was decommissioned in 2024; v1 is the only
 * supported transport, and every request needs a short-lived bearer token. The
 * token is cached just under its one-hour lifetime so a burst of pushes performs
 * one token exchange, not one per message.
 */
class FcmAccessTokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'notification:fcm:access_token';

    /** Google issues 3600s tokens; refresh at 55 min to absorb clock skew. */
    private const CACHE_TTL_SECONDS = 3300;

    public function __construct(
        private readonly FcmCredentials $credentials,
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->exchange();

        $this->cache->put(self::CACHE_KEY, $token, self::CACHE_TTL_SECONDS);

        return $token;
    }

    /**
     * Drop the cached token. Called after a 401 so the next attempt re-mints
     * rather than replaying a token Google already rejected.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    private function exchange(): string
    {
        $now = time();

        $assertion = JWT::encode(
            [
                'iss' => $this->credentials->clientEmail(),
                'scope' => self::SCOPE,
                'aud' => $this->credentials->tokenUri(),
                'iat' => $now,
                'exp' => $now + 3600,
            ],
            $this->credentials->privateKey(),
            'RS256'
        );

        $response = $this->http
            ->asForm()
            ->timeout($this->timeoutSeconds)
            ->post($this->credentials->tokenUri(), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            throw FcmConfigurationException::tokenExchangeFailed(
                'HTTP '.$response->status().' — '.$response->json('error_description', $response->json('error', 'unknown error'))
            );
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw FcmConfigurationException::tokenExchangeFailed('response contained no access_token');
        }

        return $accessToken;
    }
}
