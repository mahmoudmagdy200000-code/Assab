<?php

namespace Modules\Admin\Services;

use Illuminate\Database\QueryException;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\Asset;
use Modules\Admin\Support\UniqueViolation;

/**
 * Allocates `FA-0001` register ids, **per company**.
 *
 * `Asset::count() + 1` under the tenant global scope produced a per-company
 * counter against a globally unique column: the second company to register its
 * first asset collided on `FA-001` and 500'd. The sequence is now read across
 * tenants but filtered to the target company, the unique key is composite
 * (company_id, public_id), and concurrent inserts retry.
 */
final class AssetSequence
{
    public const PREFIX = 'FA';

    public static function format(int $n): string
    {
        return self::PREFIX.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** The next `$count` free ids for a company. Racy by nature — always create via createBatch(). */
    public static function reserve(string $companyId, int $count = 1): array
    {
        $last = Asset::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $companyId)
            ->where('public_id', 'like', self::PREFIX.'-%')
            ->orderByRaw('LENGTH(public_id) DESC')
            ->orderBy('public_id', 'desc')
            ->value('public_id');

        $next = $last ? self::sequenceOf($last) + 1 : 1;

        return array_map(fn (int $i) => self::format($next + $i), range(0, max(1, $count) - 1));
    }

    /**
     * Run `$make($publicIds)` against freshly reserved ids, re-reserving when a
     * concurrent insert took one. `$make` must own its own transaction so a
     * unique violation rolls the failed attempt back before the next reserve.
     *
     * @template T
     *
     * @param  callable(string[]): T  $make
     * @return T
     */
    public static function createBatch(string $companyId, int $count, callable $make, int $attempts = 5)
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $make(self::reserve($companyId, $count));
            } catch (QueryException $e) {
                if (! UniqueViolation::matches($e) || $attempt === $attempts) {
                    throw $e;
                }
            }
        }

        throw new AsabException(
            'PUBLIC_ID_EXHAUSTED',
            'Could not allocate a unique asset id',
            'تعذّر توليد رمز أصل فريد',
            503,
            ['companyId' => $companyId],
        );
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $make
     * @return T
     */
    public static function createOne(string $companyId, callable $make)
    {
        return self::createBatch($companyId, 1, fn (array $ids) => $make($ids[0]));
    }

    private static function sequenceOf(string $publicId): int
    {
        return (int) substr($publicId, strlen(self::PREFIX) + 1);
    }
}
