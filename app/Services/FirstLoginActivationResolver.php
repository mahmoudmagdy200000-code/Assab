<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Log\LoggerInterface;

/**
 * Resolves whose password the mobile «activate Account» screen is setting.
 *
 * That screen has two password boxes and nothing else, and the reset endpoint
 * used to sit behind `auth:sanctum` — so a build that does not attach the
 * first-login token gets a bare "Unauthenticated." on a screen it cannot
 * recover from, and the account stays locked (`is_first_login` keeps the normal
 * login closed). Reported for a dashboard-created supplier, 2026-07-26.
 *
 * Every proof accepted here is proof of a credential the admin issued:
 *  1. the first-login Sanctum token — `Authorization: Bearer …`, a bare
 *     `Authorization:` value, `X-Auth-Token`/`X-Access-Token`, or a body/query
 *     field (`token`, `access_token`, `api_token`, `auth_token`)
 *  2. the default password again — `identifier` + `default_password` (also
 *     accepted as `current_password`/`old_password`, camelCase included), the
 *     shape the Cashier `/activate` screen uses
 *
 * The alias lists are wide on purpose: the same screen ships in several app
 * builds, and a rejected activation is a locked-out user, not a retry.
 *
 * Nothing here relaxes WHO may set a password — the caller still has to hold the
 * token or know the current password, the callers keep their
 * `is_first_login`/active checks, and the routes are throttled. A refusal is
 * logged with the KEY NAMES received (never values) so a build that sends none
 * of these can be identified from one attempt.
 */
class FirstLoginActivationResolver
{
    /** The token name every module's firstLogin() mints. */
    public const FIRST_LOGIN_TOKEN_NAME = 'first-login-token';

    /** Body/query fields that may carry the first-login token. */
    private const TOKEN_FIELDS = ['token', 'access_token', 'accessToken', 'api_token', 'apiToken', 'auth_token', 'authToken', 'bearer_token'];

    /** Headers that may carry it instead of `Authorization`. */
    private const TOKEN_HEADERS = ['Authorization', 'X-Auth-Token', 'X-Access-Token', 'X-Api-Token'];

    /** Fields that may carry the admin-issued (default) password. */
    private const DEFAULT_PASSWORD_FIELDS = ['default_password', 'defaultPassword', 'current_password', 'currentPassword', 'old_password', 'oldPassword'];

    public function __construct(
        private readonly Hasher $hasher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  class-string  $model  the account model for this surface
     *
     * @throws AuthenticationException when no proof holds
     */
    public function resolveFromRequest(string $model, Request $request): FirstLoginActivation
    {
        // A password proof is tried FIRST: it is the stronger one, and it is what
        // lets an already-activated account set a new password here.
        $identifier = $this->stringOrNull($request->input('identifier'));
        if ($identifier !== null) {
            foreach (self::DEFAULT_PASSWORD_FIELDS as $field) {
                $password = $this->stringOrNull($request->input($field));
                if ($password !== null && ($account = $this->fromCredentials($model, $identifier, $password)) !== null) {
                    return new FirstLoginActivation($account, provedWithPassword: true);
                }
            }
        }

        // The route is public, so this is Sanctum reading a well-formed Bearer
        // header — the original contract, still the happy path.
        $authenticated = $request->user('sanctum');
        if ($authenticated instanceof $model) {
            return new FirstLoginActivation(
                $authenticated,
                provedWithPassword: false,
                viaFirstLoginToken: $this->isFirstLoginToken($authenticated->currentAccessToken()?->name),
            );
        }

        foreach ($this->candidateTokens($request) as $token) {
            $accessToken = PersonalAccessToken::findToken($token);
            $account = $accessToken?->tokenable;
            if ($account instanceof $model) {
                return new FirstLoginActivation(
                    $account,
                    provedWithPassword: false,
                    viaFirstLoginToken: $this->isFirstLoginToken($accessToken->name),
                );
            }
        }

        $this->logRefusal($model, $request);

        throw new AuthenticationException;
    }

    /**
     * Every string that could be a token, header values first.
     *
     * @return string[]
     */
    private function candidateTokens(Request $request): array
    {
        $candidates = [];

        foreach (self::TOKEN_HEADERS as $header) {
            $value = $this->stringOrNull($request->header($header));
            if ($value === null) {
                continue;
            }
            // Accept «Bearer x», «Token x» and a bare «x» — builds differ.
            $parts = preg_split('/\s+/', $value) ?: [];
            $candidates[] = count($parts) > 1 ? end($parts) : $value;
        }

        foreach (self::TOKEN_FIELDS as $field) {
            $value = $this->stringOrNull($request->input($field));
            if ($value !== null) {
                $candidates[] = $value;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Whether this is the token minted by first-login. Only that one may set a
     * password on its own; a plain session token has to use change-password.
     */
    private function isFirstLoginToken(?string $tokenName): bool
    {
        return $tokenName === self::FIRST_LOGIN_TOKEN_NAME;
    }

    /**
     * The account whose current (default) password matches. Email vs phone is
     * decided the same way every module's login does, so an identifier that
     * works for login works here.
     */
    private function fromCredentials(string $model, string $identifier, string $password): ?Authenticatable
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        /** @var Authenticatable|null $account */
        $account = $model::query()->where($field, $identifier)->first();

        if ($account === null || ! $this->hasher->check($password, $account->getAuthPassword())) {
            return null;
        }

        return $account;
    }

    /**
     * What the client actually sent — key names and the Authorization scheme
     * only. NEVER values: this request carries two passwords and a token.
     */
    private function logRefusal(string $model, Request $request): void
    {
        $authorization = (string) $request->header('Authorization');

        $this->logger->warning('First-login activation refused: no proof of the issued credential', [
            'model' => $model,
            'path' => $request->path(),
            'bodyKeys' => array_keys($request->all()),
            'headerNames' => array_values(array_intersect(
                array_map('strtolower', self::TOKEN_HEADERS),
                array_keys($request->headers->all()),
            )),
            'authorizationScheme' => $authorization === '' ? null : strtok($authorization, ' '),
            'authorizationLength' => strlen($authorization),
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
