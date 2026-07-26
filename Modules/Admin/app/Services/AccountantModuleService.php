<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabAccountantRestaurantModule;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Support\ModuleCatalog;

/**
 * Per-(accountant, restaurant) module permissions — ADM-3.3 «مصفوفة الموديولات».
 *
 * Before this service the grid wrote one flat `asab_user_roles.module_keys` per
 * accountant, so every restaurant echoed the same list and a change to one row
 * changed them all. Cells now live in `asab_accountant_restaurant_modules`;
 * `module_keys` is kept as the UNION of the cells because that is what
 * ResolveTenant / AuthService read — this service must never change what those
 * two see except as the direct result of an admin edit.
 *
 * Two rules the writes depend on:
 *  - **Coverage is authority.** An accountant is BRAND-level, so the rows of the
 *    grid are the restaurants of their brands (∪ legacy `restaurant_ids`).
 *    Writing a cell for a restaurant outside that coverage is refused rather
 *    than silently self-assigning the restaurant — which is what the old
 *    endpoint did (it also flipped `scope` to 'restaurant', widening access on
 *    what the admin thought was a permissions save).
 *  - **Legacy is materialised, never guessed.** The first cell write for an
 *    accountant that has no cells yet seeds one cell per covered restaurant from
 *    the old flat list, so an existing grant keeps its exact effective value and
 *    the union does not shrink behind the admin's back.
 */
class AccountantModuleService
{
    public function __construct(private readonly AccountantScopeService $scope) {}

    /**
     * Stored cells for the given accountants, in one query.
     *
     * @param  string[]  $accountantIds
     * @return array<string, array<string, string[]>> [accountantId][restaurantId] => module keys
     */
    public function grantsFor(array $accountantIds): array
    {
        if ($accountantIds === []) {
            return [];
        }

        $grants = [];
        foreach ($this->cellQuery($accountantIds)->get(['accountant_id', 'restaurant_id', 'module_keys']) as $cell) {
            $grants[$cell->accountant_id][$cell->restaurant_id] = $this->clean($cell->module_keys ?? []);
        }

        return $grants;
    }

    /**
     * The grid for one accountant: one row per covered restaurant, with names —
     * this is what the users screen renders instead of raw uuids.
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrix(AsabUserRole $assignment): array
    {
        $covered = $this->scope->restaurantsForAssignment($assignment);
        $cells = $this->grantsFor([$assignment->user_id])[$assignment->user_id] ?? [];
        $effective = $this->effectiveCells($assignment, $covered, $cells);
        $brands = $this->brandMeta($covered);
        $catalogSize = count(ModuleCatalog::keys());

        return $covered->map(function (AsabRestaurant $r) use ($cells, $effective, $brands, $catalogSize) {
            $explicit = array_key_exists($r->id, $cells);
            $modules = $effective[$r->id];
            $brand = $brands[$r->brand_id] ?? null;

            return [
                'restaurantId' => $r->id,
                'restaurantName' => $r->name,
                'brandId' => $r->brand_id,
                'brandName' => $brand['name'] ?? null,
                'modules' => $modules,
                'moduleCount' => count($modules),
                // Advisory only (the admin may still tick anything in the
                // catalog): the brand's subscribed modules, so the screen can
                // grey out what the package does not include. An empty
                // `modules` column means "the whole catalog" — same convention
                // as the brand module widget (BUG-1).
                'availableModules' => $brand['modules'] ?: ModuleCatalog::keys(),
                'total' => $catalogSize,
                'isExplicit' => $explicit,
            ];
        })->values()->all();
    }

    /**
     * Effective modules per restaurant for one accountant, from cells that were
     * already loaded — so a list screen (distribution, users) resolves many
     * accountants without a query per row.
     *
     * A restaurant with no cell falls back to the accountant's pre-ADM-3.3 flat
     * list, which IS still their effective grant until an admin edits the grid.
     *
     * @param  Collection<int, AsabRestaurant>  $covered
     * @param  array<string, string[]>  $cells  [restaurantId] => module keys
     * @return array<string, string[]> [restaurantId] => module keys
     */
    public function effectiveCells(AsabUserRole $assignment, Collection $covered, array $cells): array
    {
        $legacy = $this->clean($assignment->module_keys ?? []);

        $effective = [];
        foreach ($covered as $restaurant) {
            $effective[$restaurant->id] = $cells[$restaurant->id] ?? $legacy;
        }

        return $effective;
    }

    /**
     * Set the modules of ONE (accountant, restaurant) cell. Caller wraps in a
     * transaction — two tables are written (the cell and the mirrored union).
     *
     * @param  string[]  $modules
     * @return array<string, mixed> the updated grid row
     */
    public function setModules(AsabUserRole $assignment, string $restaurantId, array $modules, ?string $actorId = null): array
    {
        $covered = $this->assertCovered($assignment, [$restaurantId]);
        $this->seedFromLegacy($assignment, $covered);
        $this->writeCell($assignment->user_id, $restaurantId, $modules, $actorId);
        $this->syncUnion($assignment, $covered);

        return $this->rowFor($assignment, $restaurantId);
    }

    /**
     * Replace several cells at once (the grid's "save" button). Only the rows
     * present in $rows are touched; unlisted restaurants keep their cell.
     *
     * @param  array<int, array{restaurantId: string, modules: string[]}>  $rows
     * @return array<int, array<string, mixed>> the whole grid, after the write
     */
    public function replaceMatrix(AsabUserRole $assignment, array $rows, ?string $actorId = null): array
    {
        $ids = array_map(fn ($row) => $row['restaurantId'], $rows);
        // Assert every id BEFORE writing anything: a partially applied grid is
        // worse than a refused one.
        $covered = $this->assertCovered($assignment, $ids);
        $this->seedFromLegacy($assignment, $covered);

        foreach ($rows as $row) {
            $this->writeCell($assignment->user_id, $row['restaurantId'], $row['modules'] ?? [], $actorId);
        }
        $this->syncUnion($assignment, $covered);

        return $this->matrix($assignment->fresh());
    }

    /**
     * Recompute the mirrored union after the accountant's COVERAGE changed (a
     * brand reassignment): a cell for a restaurant they no longer cover must
     * stop granting its modules to auth resolution. Cells are kept, not deleted
     * — reassigning the brand back restores the grid the admin built.
     *
     * No-op for an accountant with no cells, so the legacy flat list is left
     * exactly as the assignment endpoints wrote it.
     */
    public function resyncUnion(AsabUserRole $assignment): void
    {
        if (! $this->cellQuery([$assignment->user_id])->exists()) {
            return;
        }

        $this->syncUnion($assignment, $this->scope->restaurantsForAssignment($assignment));
    }

    /**
     * Distinct modules an accountant is granted anywhere in their coverage —
     * the «n صلاحية» count on the users screen.
     *
     * @param  array<string, string[]>  $cells  [restaurantId] => module keys
     * @param  string[]  $legacy
     * @return string[]
     */
    public function grantedUnion(array $cells, array $legacy = []): array
    {
        return $cells === []
            ? $this->clean($legacy)
            : $this->clean(array_merge(...array_values($cells)));
    }

    /**
     * Coverage check for a set of restaurant ids (zero-trust): an id outside the
     * accountant's brand coverage is refused with the reason, because on the
     * admin surface "not found" would hide a fixable assignment mistake.
     *
     * @param  string[]  $restaurantIds
     * @return Collection<int, AsabRestaurant> the accountant's coverage
     */
    private function assertCovered(AsabUserRole $assignment, array $restaurantIds): Collection
    {
        $covered = $this->scope->restaurantsForAssignment($assignment);
        $coveredIds = $covered->pluck('id')->all();
        $outside = array_values(array_diff(array_unique($restaurantIds), $coveredIds));

        if ($outside !== []) {
            throw new AsabException(
                'RESTAURANT_NOT_ASSIGNED',
                'Restaurant is not covered by this accountant. Assign its brand to the accountant first.',
                'المطعم غير مخصّص لهذا المحاسب — عيّن براند المطعم للمحاسب أولاً.',
                422,
                ['restaurants' => $outside],
            );
        }

        return $covered;
    }

    /**
     * Materialise the pre-ADM-3.3 flat list into one cell per covered
     * restaurant, once, so the first per-restaurant edit does not silently
     * revoke the other restaurants' modules when the union is recomputed.
     *
     * @param  Collection<int, AsabRestaurant>  $covered
     */
    private function seedFromLegacy(AsabUserRole $assignment, Collection $covered): void
    {
        $legacy = $this->clean($assignment->module_keys ?? []);
        if ($legacy === [] || $this->cellQuery([$assignment->user_id])->exists()) {
            return;
        }

        foreach ($covered as $restaurant) {
            $this->writeCell($assignment->user_id, $restaurant->id, $legacy, null);
        }
    }

    /** @param  string[]  $modules */
    private function writeCell(string $accountantId, string $restaurantId, array $modules, ?string $actorId): void
    {
        AsabAccountantRestaurantModule::updateOrCreate(
            ['accountant_id' => $accountantId, 'restaurant_id' => $restaurantId],
            ['module_keys' => $this->clean($modules), 'updated_by_id' => $actorId],
        );
    }

    /**
     * Mirror the cells onto `asab_user_roles.module_keys`. Only cells inside the
     * CURRENT coverage count: a stale cell left behind by a brand reassignment
     * must not keep widening what auth resolution grants.
     *
     * @param  Collection<int, AsabRestaurant>  $covered
     */
    private function syncUnion(AsabUserRole $assignment, Collection $covered): void
    {
        $coveredIds = $covered->pluck('id')->all();
        $cells = $this->grantsFor([$assignment->user_id])[$assignment->user_id] ?? [];
        $inScope = array_intersect_key($cells, array_flip($coveredIds));

        $assignment->update(['module_keys' => $this->grantedUnion($inScope)]);
    }

    /** @return array<string, mixed> */
    private function rowFor(AsabUserRole $assignment, string $restaurantId): array
    {
        $row = collect($this->matrix($assignment->fresh()))->firstWhere('restaurantId', $restaurantId);

        return $row ?? [
            'restaurantId' => $restaurantId,
            'modules' => [],
            'moduleCount' => 0,
            'total' => count(ModuleCatalog::keys()),
        ];
    }

    /**
     * id => {name, modules} for the brands behind a coverage set, in one query.
     *
     * @param  Collection<int, AsabRestaurant>  $covered
     * @return array<string, array{name: string, modules: string[]}>
     */
    private function brandMeta(Collection $covered): array
    {
        $brandIds = $covered->pluck('brand_id')->filter()->unique()->values()->all();
        if ($brandIds === []) {
            return [];
        }

        return AsabBrand::whereIn('id', $brandIds)->get(['id', 'name', 'modules'])
            ->mapWithKeys(fn (AsabBrand $b) => [$b->id => [
                'name' => $b->name,
                'modules' => $this->clean($b->modules ?? []),
            ]])->all();
    }

    /** @param  string[]  $accountantIds */
    private function cellQuery(array $accountantIds)
    {
        return AsabAccountantRestaurantModule::query()->whereIn('accountant_id', $accountantIds);
    }

    /**
     * Keep only real catalog keys, de-duplicated, in catalog order — so a stale
     * or invented key stored in the past cannot travel back out as a grant.
     *
     * @param  array<int, mixed>  $modules
     * @return string[]
     */
    private function clean(array $modules): array
    {
        return array_values(array_intersect(ModuleCatalog::keys(), array_unique($modules)));
    }
}
