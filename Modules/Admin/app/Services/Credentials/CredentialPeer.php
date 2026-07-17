<?php

namespace Modules\Admin\Services\Credentials;

use Illuminate\Database\Eloquent\Model;

/**
 * One legacy (mobile) world a dashboard credential can be mirrored into. Each
 * implementation knows ONLY its own entity type, model and column names, so
 * CredentialSyncService can sweep every linked world without a growing
 * if/else over model classes (OCP: a new world = a new peer + a tag entry).
 */
interface CredentialPeer
{
    /** The AsabIdentityMap::ENTITY_* type whose links name this world's rows. */
    public function entityType(): string;

    /** Resolve a legacy row by id, including soft-deleted ones. */
    public function find(string $legacyId): ?Model;

    /**
     * Replicate $hash onto the legacy row. $hash is already hashed and every
     * peer model casts password => 'hashed', which passes a hashed value
     * through untouched rather than re-hashing it.
     *
     * $forceReset marks the row first-login so the app makes the user choose
     * their own password — right for an admin-issued temporary password, wrong
     * for a password the user just chose themselves.
     */
    public function applyPassword(Model $row, string $hash, bool $forceReset): void;

    /**
     * Revoke this world's access. Provisioning creates a real mobile login, so
     * disabling the dashboard user has to reach it too — otherwise a removed
     * user keeps working app access with the password they were emailed.
     */
    public function disable(Model $row): void;
}
