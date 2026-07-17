<?php

namespace Modules\Admin\Services\Provisioning;

use Modules\Admin\Models\AsabUser;

/**
 * Gives a newly created dashboard user the matching legacy (mobile) login, so
 * the one password the admin emails opens both worlds (client requirement:
 * "every user I add is emailed a password they can log in with — the dashboard
 * roles and the supplier can use it on BOTH mobile and dashboard").
 *
 * Implementations run inside the caller's DB transaction and must not send
 * mail: UserController@store emails the temporary password after the commit.
 */
interface LegacyProvisioner
{
    /** The AsabUserRole role_key this provisioner serves. */
    public function roleKey(): string;

    /**
     * @param  array  $data  the validated store() payload (supplierId / branches / …)
     * @param  string  $temporaryPassword  the plaintext store() minted and will email
     *
     * @throws \Modules\Admin\Exceptions\AsabException 422 when no honest legacy login can be built.
     */
    public function provision(AsabUser $user, array $data, string $temporaryPassword): void;
}
