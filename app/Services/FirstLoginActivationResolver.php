<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves whose password the mobile «activate Account» screen is setting.
 *
 * That screen only has two password boxes, and the reset endpoint used to be
 * behind `auth:sanctum` — so a build that does not attach the first-login token
 * gets a bare "Unauthenticated." on a screen it cannot recover from (reported
 * for a dashboard-created supplier, 2026-07-26). The same screen serves
 * suppliers, brand owners and branch managers, and the cashier flow already
 * activates with the default password instead of a token.
 *
 * So three proofs are accepted, and every one of them is proof of a credential
 * the admin issued:
 *  1. the Sanctum token from first-login, as a Bearer header (original contract)
 *  2. the same token in the body (`token`)
 *  3. the default password again (`identifier` + `default_password`) — the
 *     Cashier `/activate` contract
 *
 * Nothing here relaxes WHO may set a password: the caller still has to hold the
 * token or know the current password, the callers keep their
 * `is_first_login`/active checks, and the routes are throttled.
 */
class FirstLoginActivationResolver
{
    public function __construct(private readonly Hasher $hasher) {}

    /**
     * @param  class-string  $model  the account model for this surface
     * @param  Authenticatable|null  $authenticated  `$request->user('sanctum')`
     * @param  array<string, mixed>  $input  token / identifier / default_password
     *
     * @throws AuthenticationException when no proof holds
     */
    public function resolve(string $model, ?Authenticatable $authenticated, array $input): Authenticatable
    {
        if ($authenticated instanceof $model) {
            return $authenticated;
        }

        $token = $this->stringOrNull($input['token'] ?? null);
        if ($token !== null && ($account = $this->fromToken($model, $token)) !== null) {
            return $account;
        }

        $identifier = $this->stringOrNull($input['identifier'] ?? null);
        $password = $this->stringOrNull($input['default_password'] ?? null);
        if ($identifier !== null && $password !== null) {
            $account = $this->fromCredentials($model, $identifier, $password);
            if ($account !== null) {
                return $account;
            }
        }

        throw new AuthenticationException;
    }

    /** The token's owner, only when it belongs to THIS surface's model. */
    private function fromToken(string $model, string $token): ?Authenticatable
    {
        $tokenable = PersonalAccessToken::findToken($token)?->tokenable;

        return $tokenable instanceof $model ? $tokenable : null;
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

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
