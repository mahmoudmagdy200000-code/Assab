<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Whether a mobile account must complete the «activate Account» screen before
 * it can be used — the one place the flag is read.
 *
 * Two states, and the difference matters operationally:
 *
 *  - **OFF (default).** A successful sign-in with the admin-issued password
 *    completes activation itself (`is_first_login` cleared, first-login answers
 *    `requires_password_reset: false`). This is what unblocked the 2026-07-26
 *    lock-out: the app's activation screen posts neither the first-login token
 *    nor the default password, so the forced flow had no exit and the account
 *    stayed closed for good.
 *  - **ON.** The flag is left set until the activation endpoint is called, and
 *    the normal login refuses a not-yet-activated account. Only flip this once
 *    the app is confirmed to send one of the accepted proofs (token in the
 *    header/body, or `identifier` + `default_password`) — otherwise it
 *    reproduces the lock-out verbatim, retroactively, for every account that
 *    still carries the flag.
 */
class FirstLoginPolicy
{
    public function __construct(private readonly Config $config) {}

    public function forcesReset(): bool
    {
        return (bool) $this->config->get('features.mobile_force_first_login_reset', false);
    }
}
