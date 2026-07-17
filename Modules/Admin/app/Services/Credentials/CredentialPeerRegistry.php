<?php

namespace Modules\Admin\Services\Credentials;

/**
 * The set of legacy worlds a dashboard credential may be mirrored into.
 *
 * Deliberately does NOT include a cashier peer: ENTITY_CASHIER links an
 * asab_employee to a cashier, and an Employee is not an AsabUser — a cashier
 * peer would push asab_user password hashes onto unrelated cashier rows.
 */
class CredentialPeerRegistry
{
    /** @var CredentialPeer[] */
    private readonly array $peers;

    /** @param iterable<CredentialPeer> $peers */
    public function __construct(iterable $peers)
    {
        $this->peers = is_array($peers) ? $peers : iterator_to_array($peers);
    }

    /** @return CredentialPeer[] */
    public function all(): array
    {
        return $this->peers;
    }

    public function get(string $entityType): ?CredentialPeer
    {
        foreach ($this->peers as $peer) {
            if ($peer->entityType() === $entityType) {
                return $peer;
            }
        }

        return null;
    }
}
