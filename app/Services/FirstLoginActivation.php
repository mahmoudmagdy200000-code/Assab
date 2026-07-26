<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who an «activate Account» request resolved to, and HOW it proved itself.
 *
 * The how matters: an account that has already completed activation may still
 * set a new password here (the screen is reachable in older builds), but only
 * with the current password — that is change-password semantics. A bare token
 * must not be enough to rotate an active account's password.
 */
final class FirstLoginActivation
{
    public function __construct(
        public readonly Authenticatable $account,
        public readonly bool $provedWithPassword,
    ) {}
}
