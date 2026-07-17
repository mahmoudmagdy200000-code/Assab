<?php

namespace Modules\Admin\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\Credentials\CredentialPeerRegistry;

/**
 * Keeps one credential usable in both worlds (client requirement: "the same
 * password works on the mobile app and the dashboard"). The two worlds are
 * separate auth stacks — config/auth.php maps the `asab` guard to asab_users
 * and the `supplier` / `brand_owner` guards to their own tables — so a write to
 * one table cannot reach the other. IdentityMapService supplies the cross-world
 * link; CredentialPeerRegistry supplies the set of worlds to reach.
 *
 * Copies the HASH, never a plaintext: every model casts `password => 'hashed'`
 * and that cast is idempotent (it passes an already-hashed value through
 * untouched), so assigning the peer's hash replicates the credential without
 * re-hashing it — and works in the mobile->dashboard direction, where no
 * plaintext exists at all.
 */
class CredentialSyncService
{
    public function __construct(
        private readonly IdentityMapService $identity,
        private readonly CredentialPeerRegistry $peers,
    ) {}

    /**
     * Push a dashboard user's credential onto EVERY linked mobile account.
     * A user holds at most one link per entity type, and reaching all of them
     * is precisely what "one password everywhere" means — a user who is both a
     * supplier and a brand owner must not end up with two passwords.
     *
     * `$forceReset` marks the mobile side first-login so the app makes the user
     * choose their own password, which is what an admin-issued temporary
     * password warrants; a self-service change carries the user's own choice
     * across and must not re-trigger it.
     *
     * @return int how many linked mobile accounts were updated
     */
    public function pushToMobile(AsabUser $user, bool $forceReset = false): int
    {
        $updated = 0;

        foreach ($this->peers->all() as $peer) {
            $legacyId = $this->identity->legacyIdFor($peer->entityType(), $user->id);
            if ($legacyId === null) {
                continue;
            }

            // A dangling link (e.g. branch_managers.branch_id cascades on branch
            // delete while the map row survives) resolves to nothing — skip it.
            $row = $peer->find($legacyId);
            if ($row === null) {
                continue;
            }

            $peer->applyPassword($row, $user->password, $forceReset);
            $updated++;
        }

        return $updated;
    }

    /**
     * Revoke every linked mobile login. Provisioning creates real app access,
     * so deleting or deactivating the dashboard user must reach it: otherwise
     * the admin UI shows the user gone while they keep working in the app with
     * the password they were emailed.
     *
     * @return int how many linked mobile accounts were disabled
     */
    public function disableOnMobile(AsabUser $user): int
    {
        $disabled = 0;

        foreach ($this->peers->all() as $peer) {
            $legacyId = $this->identity->legacyIdFor($peer->entityType(), $user->id);
            if ($legacyId === null) {
                continue;
            }

            $row = $peer->find($legacyId);
            if ($row === null) {
                continue;
            }

            $peer->disable($row);
            $disabled++;
        }

        return $disabled;
    }

    /**
     * Pull a mobile credential back onto its linked dashboard user, so a
     * password the user set on the app also opens the dashboard. Without this
     * the two drift apart on the very first mobile login: provisioning marks
     * the mobile row is_first_login, and the app forces a reset there.
     *
     * The entity type comes from the calling listener, which knows its own
     * world; the legacy row alone cannot say which link to follow.
     *
     * @return bool whether a linked dashboard user was found and updated
     */
    public function pullFromMobile(string $entityType, Model $legacyRow): bool
    {
        $userId = $this->identity->dashboardIdFor($entityType, $legacyRow->getKey());
        if ($userId === null) {
            return false;
        }

        $user = AsabUser::find($userId);
        if ($user === null) {
            return false;
        }

        $user->forceFill(['password' => $legacyRow->password])->save();
        $user->tokens()->delete();

        return true;
    }
}
