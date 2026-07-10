<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AsabBrandPackage extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'asab_brand_packages';

    protected $fillable = ['code', 'name', 'name_en', 'price', 'is_active'];

    protected $casts = ['price' => 'integer', 'is_active' => 'boolean'];

    /** Legacy Arabic plan values → package codes (accepted as aliases everywhere). */
    public const CODE_ALIASES = ['فضي' => 'silver', 'ذهبي' => 'gold', 'بلاتيني' => 'platinum'];

    /**
     * The pre-table hardcoded catalog. Used ONLY as a fallback while
     * asab_brand_packages is unseeded so existing deployments keep working.
     */
    public const LEGACY_CATALOG = [
        'silver' => ['name' => 'فضي', 'price' => 100000],
        'gold' => ['name' => 'ذهبي', 'price' => 175000],
        'platinum' => ['name' => 'بلاتيني', 'price' => 250000],
    ];

    /** Map a legacy Arabic alias to its package code (codes pass through). */
    public static function resolveCode(string $plan): string
    {
        return self::CODE_ALIASES[$plan] ?? $plan;
    }

    /**
     * Validation rule for a plan/package code: exists-in-table once the catalog
     * is populated, legacy silver/gold/platinum before that.
     */
    public static function codeRule(): object|string
    {
        return self::query()->exists()
            ? \Illuminate\Validation\Rule::exists('asab_brand_packages', 'code')->where('is_active', true)->whereNull('deleted_at')
            : 'in:'.implode(',', array_keys(self::LEGACY_CATALOG));
    }

    /**
     * Resolve a validated code to its stored plan name + monthly price —
     * table first, hardcoded legacy catalog only when the table has no match.
     *
     * @return array{name: string, price: int}
     */
    public static function catalogEntry(string $code): array
    {
        $pkg = self::where('code', $code)->where('is_active', true)->first();
        if ($pkg) {
            return ['name' => $pkg->name, 'price' => (int) $pkg->price];
        }

        return self::LEGACY_CATALOG[$code] ?? ['name' => $code, 'price' => 0];
    }
}
