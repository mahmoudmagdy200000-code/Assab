<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;

/**
 * Meeting 2026-08-05 «عدد منتجات المشتريات يفترض 30 وليس 15»: the raw-material
 * upload seeds `branch_item` for the brand's branches AS THEY EXIST AT UPLOAD
 * TIME. A branch created afterwards therefore starts life with an empty
 * purchasing picker — the app's item lists all key on that pivot — and so does
 * every branch that was linked to the brand after its catalog was uploaded.
 *
 * This re-projects a brand's `raw_material` catalog onto its branches: resolve
 * (create-only) the legacy `items` row for each catalog row, then seed the
 * pivot. Idempotent — `firstOrCreate` keeps a branch's own price/quantity.
 *
 * It also fills a legacy row's EMPTY unit/category from the catalog, which is
 * why the app showed «(Kg)» on every line («يفترض تكون نفس وحدة القياس التي
 * رُفعت»): the mobile resources fall back to 'kg' when `items.unit` is blank,
 * and rows minted before the unit column was carried across stayed blank. Only
 * blanks are filled: overwriting a populated unit could rewrite another brand's
 * row, since `items` carries no tenant column.
 */
class RawMaterialBranchSyncService
{
    public function __construct(private readonly \Psr\Log\LoggerInterface $log) {}

    /**
     * @return array{branches:int, items:int, seeded:int, created:int, unitsFilled:int}
     */
    public function syncBrand(AsabBrand $brand, ?array $branchIds = null): array
    {
        $branchIds ??= $this->brandBranchIds($brand);

        $catalog = InventoryCatalogItem::where('brand_id', $brand->id)
            ->where('type', InventoryCatalogItem::TYPE_RAW_MATERIAL)
            ->where('status', '!=', 'inactive')
            ->get();

        $state = ['branches' => count($branchIds), 'items' => $catalog->count(), 'seeded' => 0, 'created' => 0, 'unitsFilled' => 0];

        if ($branchIds === [] || $catalog->isEmpty()) {
            return $state;
        }

        foreach ($catalog as $row) {
            $mobile = $this->resolveMobileItem($row, $state);
            if ($mobile === null) {
                continue;
            }

            foreach ($branchIds as $branchId) {
                $pivot = BranchItem::firstOrCreate(
                    ['branch_id' => $branchId, 'item_id' => $mobile->id],
                    ['price' => round(((int) $row->unit_price) / 100, 2), 'quantity' => 0],
                );

                if ($pivot->wasRecentlyCreated) {
                    $state['seeded']++;
                }
            }
        }

        return $state;
    }

    /** Seed a single (usually just-created) branch from its brand's catalog. */
    public function syncBranch(Branch $branch): array
    {
        $brandId = $branch->asab_brand_id
            ?? ($branch->asab_restaurant_id
                ? AsabRestaurant::withoutGlobalScopes()->whereKey($branch->asab_restaurant_id)->value('brand_id')
                : null);

        if ($brandId === null) {
            return ['branches' => 0, 'items' => 0, 'seeded' => 0, 'created' => 0, 'unitsFilled' => 0];
        }

        $brand = AsabBrand::withoutGlobalScopes()->find($brandId);

        return $brand === null
            ? ['branches' => 0, 'items' => 0, 'seeded' => 0, 'created' => 0, 'unitsFilled' => 0]
            : $this->syncBrand($brand, [$branch->id]);
    }

    /** Best-effort variant for request paths: a seeding failure must not fail the write. */
    public function syncBranchQuietly(Branch $branch): void
    {
        try {
            $state = $this->syncBranch($branch);
            if ($state['seeded'] > 0) {
                $this->log->info('asab.raw_materials.branch_seeded', ['branch_id' => $branch->id] + $state);
            }
        } catch (\Throwable $e) {
            $this->log->warning('asab.raw_materials.branch_seed_failed', [
                'branch_id' => $branch->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Brand branches: linked directly OR through one of the brand's restaurants
     * — resolving by `asab_brand_id` alone misses every branch attached at the
     * restaurant level (2026-08-03).
     *
     * @return string[]
     */
    public function brandBranchIds(AsabBrand $brand): array
    {
        $restaurantIds = AsabRestaurant::withoutGlobalScopes()
            ->where('brand_id', $brand->id)->pluck('id')->all();

        return Branch::query()
            ->where(function ($q) use ($brand, $restaurantIds) {
                $q->where('asab_brand_id', $brand->id);
                if ($restaurantIds !== []) {
                    $q->orWhereIn('asab_restaurant_id', $restaurantIds);
                }
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Create-only resolution into the global `items` table, mirroring the
     * upload bridge: reuse the row carrying this code (or name when codeless),
     * restoring it if trashed, and only ever FILL blanks on it.
     */
    private function resolveMobileItem(InventoryCatalogItem $row, array &$state): ?PurchaseItem
    {
        $name = trim((string) $row->name);
        if ($name === '') {
            return null;
        }

        $code = trim((string) ($row->code ?? ''));
        $existing = PurchaseItem::withTrashed()
            ->where($code !== '' ? 'code' : 'name', $code !== '' ? $code : $name)
            ->first();

        if ($existing === null) {
            $state['created']++;

            return PurchaseItem::create([
                'name' => $name,
                'code' => $code !== '' ? $code : null,
                'unit' => $row->unit ?: null,
                'category' => $row->category,
                'is_active' => true,
            ]);
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        $fill = [];
        if (trim((string) $existing->unit) === '' && trim((string) $row->unit) !== '') {
            $fill['unit'] = $row->unit;
            $state['unitsFilled']++;
        }
        if (trim((string) $existing->category) === '' && trim((string) $row->category) !== '') {
            $fill['category'] = $row->category;
        }
        if ($fill !== []) {
            $existing->fill($fill)->save();
        }

        return $existing;
    }
}
