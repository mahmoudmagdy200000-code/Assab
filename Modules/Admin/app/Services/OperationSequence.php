<?php

namespace Modules\Admin\Services;

use Illuminate\Database\QueryException;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\UniqueViolation;

/**
 * Allocates the human-facing `public_id` (OPS-0001, EXP-0042 …).
 *
 * The column is globally unique, so the sequence is read across tenants and
 * across soft-deleted rows — a `count()+1` would collide the moment a row is
 * trashed or two branches upload at once. Concurrent inserts still race, so
 * every write goes through the retry wrapper below.
 */
final class OperationSequence
{
    /** Next free id for a prefix. Racy by nature — always create via createWithPublicId(). */
    public static function next(string $prefix): string
    {
        $last = Operation::withoutGlobalScopes()->withTrashed()
            ->where('public_id', 'like', $prefix.'-%')
            ->orderByRaw('LENGTH(public_id) DESC')
            ->orderBy('public_id', 'desc')
            ->value('public_id');

        return self::format($prefix, $last ? self::sequenceOf($prefix, $last) + 1 : 1);
    }

    public static function format(string $prefix, int $n): string
    {
        return $prefix.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Run `$make($publicId)` against a freshly allocated id, retrying with the
     * next id when a concurrent insert took it. `$make` must own its own
     * transaction: a unique violation rolls the failed attempt back before the
     * next one starts.
     *
     * @template T
     *
     * @param  callable(string): T  $make
     * @return T
     */
    public static function createWithPublicId(string $prefix, callable $make, int $attempts = 5)
    {
        $publicId = self::next($prefix);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $make($publicId);
            } catch (QueryException $e) {
                if (! UniqueViolation::matches($e) || $attempt === $attempts) {
                    throw $e;
                }
                $publicId = self::format($prefix, self::sequenceOf($prefix, $publicId) + 1);
            }
        }

        throw new AsabException(
            'PUBLIC_ID_EXHAUSTED',
            'Could not allocate a unique public id',
            'تعذّر توليد رقم عملية فريد',
            503,
            ['prefix' => $prefix],
        );
    }

    private static function sequenceOf(string $prefix, string $publicId): int
    {
        return (int) substr($publicId, strlen($prefix) + 1);
    }
}
