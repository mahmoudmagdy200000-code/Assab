<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\PermissionMatrixEntry;

/**
 * Resolves a user's effective permission map from the permission_matrix
 * (BACKEND_API_SPEC.md §4 /auth/me → permissions: { [moduleKey]: Permission }).
 */
class PermissionResolver
{
    /** Permission strength order for merging multiple roles. */
    private const RANK = ['none' => 0, 'view' => 1, 'submit' => 2, 'review' => 3, 'approve' => 4, 'final' => 5];

    /**
     * @return array<string, string> map of module → permission (view|submit|review|approve|final|none)
     */
    public function forUser(AsabUser $user): array
    {
        $user->loadMissing('roleAssignments');
        $roleKeys = $user->roleAssignments->pluck('role_key')->all();
        if (empty($roleKeys)) {
            return [];
        }

        // Platform-default matrix (company_id null) merged with company override if present.
        $entries = PermissionMatrixEntry::query()
            ->whereIn('role_key', $roleKeys)
            ->where(function ($q) use ($user) {
                $q->whereNull('company_id');
                if ($user->company_id) {
                    $q->orWhere('company_id', $user->company_id);
                }
            })
            ->get();

        $map = [];
        foreach ($entries as $e) {
            $current = $map[$e->module] ?? 'none';
            if ((self::RANK[$e->permission] ?? 0) >= (self::RANK[$current] ?? 0)) {
                $map[$e->module] = $e->permission;
            }
        }

        return $map;
    }
}
