<?php

namespace Modules\Admin\Services\Provisioning;

/**
 * Resolves the legacy provisioner for a role_key, so UserController@store
 * dispatches without a match statement over roles (OCP: a new dual-written
 * role = a new provisioner + a tag entry, zero controller edits).
 */
class LegacyProvisionerRegistry
{
    /** @var LegacyProvisioner[] */
    private readonly array $provisioners;

    /** @param iterable<LegacyProvisioner> $provisioners */
    public function __construct(iterable $provisioners)
    {
        $this->provisioners = is_array($provisioners) ? $provisioners : iterator_to_array($provisioners);
    }

    /** Null for roles that exist only on the dashboard (admin / head / accountant / procurement). */
    public function for(string $roleKey): ?LegacyProvisioner
    {
        foreach ($this->provisioners as $provisioner) {
            if ($provisioner->roleKey() === $roleKey) {
                return $provisioner;
            }
        }

        return null;
    }
}
