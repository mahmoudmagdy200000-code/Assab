<?php

namespace Modules\Admin\Support;

use Illuminate\Database\QueryException;

/**
 * Driver-agnostic "this insert lost the race for a unique key" check, shared by
 * every public-id allocator (operations, assets).
 */
final class UniqueViolation
{
    public static function matches(QueryException $e): bool
    {
        // 1062 = MySQL ER_DUP_ENTRY, 19 = SQLITE_CONSTRAINT.
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return in_array($driverCode, ['1062', '19'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
