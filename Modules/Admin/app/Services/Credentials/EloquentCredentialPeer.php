<?php

namespace Modules\Admin\Services\Credentials;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared credential write for peers backed by a soft-deleting Eloquent model
 * with a `password` + `is_first_login` pair — which all three legacy login
 * tables (brand_owners / suppliers / branch_managers) happen to be today.
 * A world that later diverges overrides applyPassword() instead of forcing a
 * conditional into the sweep.
 */
abstract class EloquentCredentialPeer implements CredentialPeer
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    public function find(string $legacyId): ?Model
    {
        // withTrashed: a soft-deleted peer still owns its email, so a link may
        // legitimately point at one; the caller decides whether to restore it.
        return $this->model()::withTrashed()->find($legacyId);
    }

    public function applyPassword(Model $row, string $hash, bool $forceReset): void
    {
        // is_active/status are deliberately untouched: a password sync must not
        // resurrect an account an admin disabled.
        $row->forceFill([
            'password' => $hash,
            'is_first_login' => $forceReset,
        ])->save();

        if ($forceReset) {
            $row->tokens()->delete();
        }
    }

    public function disable(Model $row): void
    {
        // Tokens first: is_active alone would not end a session already holding
        // a bearer token, and every legacy guard authenticates the token before
        // it consults the flag.
        $row->tokens()->delete();
        $row->forceFill(['is_active' => false])->save();
    }
}
