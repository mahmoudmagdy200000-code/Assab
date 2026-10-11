<?php

namespace Modules\Shift\Liability;

use InvalidArgumentException;

final class AllocationRules
{
    public const MAX_HALALAS = 999999999999;

    /** Validate full allocation in integer halalas; no defaults or external remainder. */
    public static function validate(int $variance, array $shares): array
    {
        if ($variance < -self::MAX_HALALAS || $variance > self::MAX_HALALAS) {
            throw new InvalidArgumentException('Variance exceeds the monetary column limit.');
        }
        if ($variance >= 0) {
            if ($shares !== []) {
                throw new InvalidArgumentException('Balanced reports and branch surplus have no employee liability.');
            }

            return [];
        }

        $total = 0;
        $seen = [];
        foreach ($shares as $share) {
            if (! is_array($share)
                || ! in_array($share['type'] ?? null, ['cashier', 'branch_manager', 'employee'], true)
                || ! is_string($share['id'] ?? null) || trim($share['id']) === ''
                || ! is_int($share['amount'] ?? null) || $share['amount'] <= 0
                || $share['amount'] > self::MAX_HALALAS) {
                throw new InvalidArgumentException('Invalid responsible actor or integer-halalas share.');
            }
            $key = $share['type'].':'.$share['id'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate responsible actor.');
            }
            $seen[$key] = true;
            // Subtraction avoids overflow even for an untrusted oversized list.
            if ($share['amount'] > -$variance - $total) {
                throw new InvalidArgumentException('Allocation exceeds shortage.');
            }
            $total += $share['amount'];
        }
        if ($total !== -$variance) {
            throw new InvalidArgumentException('ALLOCATION_INCOMPLETE');
        }
        usort($shares, fn ($a, $b) => strcmp($a['type'].':'.$a['id'], $b['type'].':'.$b['id']));

        return $shares;
    }
}
