<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who an «activate Account» request resolved to, and HOW it proved itself.
 *
 * The how decides whether a password may be set, because since 2026-07-26 a
 * successful sign-in already clears `is_first_login` (the screen could not
 * complete, so the flag locked accounts out). Without that distinction any live
 * session token would be able to rotate an active account's password.
 */
final class FirstLoginActivation
{
    public function __construct(
        public readonly Authenticatable $account,
        public readonly bool $provedWithPassword,
        public readonly bool $viaFirstLoginToken = false,
    ) {}

    /**
     * The current password always qualifies (that is change-password), and so
     * does the token minted by first-login (that is the activation screen doing
     * its job). A plain session token does NOT — it must go through the
     * change-password endpoint, which asks for the current password.
     */
    public function maySetPassword(bool $accountIsInFirstLogin): bool
    {
        return $accountIsInFirstLogin || $this->provedWithPassword || $this->viaFirstLoginToken;
    }
}
