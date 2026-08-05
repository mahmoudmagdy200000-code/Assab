<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\AccountantModuleService;
use Modules\Admin\Services\AccountantScopeService;
use Modules\Admin\Services\AuditService;
use Modules\Admin\Support\ModuleCatalog;

/**
 * Accountant ↔ restaurant ↔ module distribution (BACKEND_API_SPEC.md §6.1.2).
 *
 * Module permissions are stored per (accountant, restaurant) by
 * AccountantModuleService — see ADM-3.3. Every payload here carries restaurant
 * NAMES next to the ids: the grid rendered raw uuids because the ids were the
 * only thing it could reach.
 */
class DistributionController extends AsabController
{
    /**
     * Modules an accountant gets by default when a restaurant is assigned from
     * the distribution screen. Kept as-is (pre-ADM-3.3 behaviour) so assigning a
     * restaurant does not change what auth resolution grants today; the grid
     * endpoints below are how an admin narrows or widens it explicitly.
     */
    private const DIST_MODULES = ['sales', 'expenses', 'purchases', 'inventory'];

    public function __construct(
        private readonly AccountantScopeService $scope,
        private readonly AccountantModuleService $modules,
        private readonly AuditService $audit,
    ) {}

    public function index(): JsonResponse
    {
        return $this->run(function () {
            $heads = AsabUser::whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'head'))->get();
            $accountants = AsabUser::with('roleAssignments')
                ->whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'accountant'))->get();

            $restaurants = AsabRestaurant::query()->get(['id', 'name', 'brand_id']);
            $restaurantNames = $restaurants->pluck('name', 'id');
            // Brand names cover the restaurants' brands AND the brands assigned
            // to accountants (an accountant may hold a brand with no restaurant
            // yet, and its chip must still render a name).
            $assignedBrandIds = $accountants
                ->flatMap(fn ($a) => $a->roleAssignments->firstWhere('role_key', 'accountant')?->brand_ids ?? [])
                ->filter()->unique();
            $brandNames = AsabBrand::whereIn(
                'id',
                $restaurants->pluck('brand_id')->filter()->merge($assignedBrandIds)->unique()->all(),
            )->pluck('name', 'id');
            // «تابعين لـ undefined» on the screen: the row carried headId only,
            // so the client had no name to print (2026-08-04).
            $headNames = $heads->pluck('name', 'id');
            // Every accountant's stored cells in ONE query — the grid used to be
            // faked from the flat list, and resolving it per row would be N+1.
            $cellsByAccountant = $this->modules->grantsFor($accountants->pluck('id')->all());

            $assigned = collect();
            $accModules = [];
            $accModulesByRestaurant = [];

            $accountantRows = $accountants->map(function ($acc) use (
                $restaurantNames, $brandNames, $headNames, $cellsByAccountant, &$assigned, &$accModules, &$accModulesByRestaurant
            ) {
                $assignment = $acc->roleAssignments->firstWhere('role_key', 'accountant');
                // Accountants are BRAND-level: the restaurants they cover are the
                // restaurants of their brands, resolved here rather than read off
                // the (empty) restaurant_ids that made the UI show "zero".
                $covered = $this->scope->restaurantsForAssignment($assignment);
                $restIds = $covered->pluck('id')->all();
                $assigned = $assigned->merge($restIds);

                $effective = $this->modules->effectiveCells($assignment, $covered, $cellsByAccountant[$acc->id] ?? []);
                $accModulesByRestaurant[$acc->id] = $effective;
                // Legacy name-keyed shape kept for the existing client; the
                // id-keyed map above is the one a grid can index by row.
                $accModules[$acc->id] = [];
                foreach ($effective as $rid => $mods) {
                    $accModules[$acc->id][$restaurantNames[$rid] ?? $rid] = $mods;
                }
                $granted = $this->modules->grantedUnion($effective);

                $brandIds = array_values(array_filter($assignment->brand_ids ?? []));

                return [
                    'id' => $acc->id, 'name' => $acc->name, 'avatar' => $acc->avatar,
                    'headId' => $acc->reports_to_id,
                    'headName' => $acc->reports_to_id ? ($headNames[$acc->reports_to_id] ?? null) : null,
                    // The accountant's own brand scope — «العلامة التجارية» reads
                    // it, and an empty array is exactly why their portal said «لا
                    // توجد علامات تجارية مخصّصة لك بعد».
                    'brands' => $brandIds,
                    'brandsNamed' => array_values(array_map(
                        fn (string $id) => ['id' => $id, 'name' => $brandNames[$id] ?? null],
                        $brandIds,
                    )),
                    'brandCount' => count($brandIds),
                    // A companyless accountant is an INERT account: every company
                    // surface answers WRONG_TENANT. Surfaced so the screen can flag
                    // it instead of the admin discovering it from the user's phone.
                    'companyId' => $acc->company_id,
                    'needsCompany' => $acc->company_id === null,
                    'restaurants' => $restIds,
                    // Names alongside ids so the screen renders restaurant names,
                    // not raw uuids (client meeting).
                    'restaurantsNamed' => $covered->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
                    // The «n مطعم · n صلاحية» pills, so the client counts what the
                    // backend actually granted rather than an empty id array.
                    'restaurantCount' => count($restIds),
                    'modules' => $granted,
                    'moduleCount' => count($granted),
                ];
            })->values()->all();

            return $this->ok([
                'heads' => $heads->map(fn ($h) => [
                    'id' => $h->id, 'name' => $h->name, 'avatar' => $h->avatar,
                    'accountantCount' => $accountants->where('reports_to_id', $h->id)->count(),
                ])->values()->all(),
                'accountants' => $accountantRows,
                'allRestaurants' => $restaurantNames->keys()->all(),
                // Same list with names (and their brand) so a picker/grid never
                // has to render an id.
                'allRestaurantsNamed' => $restaurants->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'brandId' => $r->brand_id,
                    'brandName' => $brandNames[$r->brand_id] ?? null,
                ])->values()->all(),
                // id => name map so any restaurant id in this payload resolves to a
                // display name client-side.
                'restaurantNames' => $restaurantNames->all(),
                'assignedRestaurants' => $assigned->unique()->values()->all(),
                'freeRestaurants' => $restaurantNames->keys()->reject(fn ($id) => $assigned->contains($id))->values()->all(),
                'accModules' => $accModules,
                'accModulesByRestaurant' => $accModulesByRestaurant,
                // The 9 modules with their Arabic labels: the grid's columns come
                // from the backend catalogue, so no screen invents a tenth.
                'moduleCatalog' => ModuleCatalog::catalog(),
            ]);
        });
    }

    public function assignRestaurant(Request $request): JsonResponse
    {
        return $this->mutateRestaurant($request, true);
    }

    public function unassignRestaurant(Request $request): JsonResponse
    {
        return $this->mutateRestaurant($request, false);
    }

    /**
     * POST /admin/distribution/assign-modules — the grid's per-cell save. The
     * body always named a restaurant; it now actually writes that restaurant's
     * cell instead of one flat list for all of them (ADM-3.3).
     */
    public function assignModules(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'accountantId' => 'required|string',
                'restaurantId' => 'required|string',
                'modules' => 'present|array',
                'modules.*' => ['string', Rule::in(ModuleCatalog::keys())],
            ]);
            $assignment = $this->accountantAssignment($data['accountantId']);

            $row = DB::transaction(fn () => $this->modules->setModules(
                $assignment, $data['restaurantId'], $data['modules'], $request->user()?->id,
            ));
            $this->auditModules($request, $data['accountantId'], $data['restaurantId'], $row['modules']);

            return $this->ok($row);
        });
    }

    public function moveToHead(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['accountantId' => 'required|string', 'headId' => 'required|string']);
            $acc = AsabUser::findOrFail($data['accountantId']);
            DB::transaction(fn () => $acc->update(['reports_to_id' => $data['headId']]));

            return $this->noContent();
        });
    }

    /**
     * PATCH /admin/accountants/{accId}/assignments (doc §1.8) — set the accountant's
     * head (when headId present) AND reconcile restaurant assignments to EXACTLY the
     * given array in one transaction. Reuses the moveToHead/assign/unassign logic.
     */
    public function assignments(Request $request, string $accId): JsonResponse
    {
        return $this->run(function () use ($request, $accId) {
            $data = $request->validate([
                'headId' => 'nullable|string',
                'brands' => 'sometimes|array|min:1',
                'brands.*' => 'string',
                'restaurants' => 'required_without:brands|array',
                'restaurants.*' => 'string',
            ]);

            $acc = AsabUser::findOrFail($accId);
            $assignment = $this->accountantAssignment($accId);
            $brands = isset($data['brands']) ? collect($data['brands'])->unique()->values()->all() : null;
            $restaurants = isset($data['restaurants']) ? collect($data['restaurants'])->unique()->values()->all() : null;

            DB::transaction(function () use ($acc, $assignment, $data, $brands, $restaurants) {
                // Reuse moveToHead logic: set reports_to_id when a head is provided.
                if (array_key_exists('headId', $data) && $data['headId'] !== null) {
                    $acc->update(['reports_to_id' => $data['headId']]);
                }
                $updates = ['module_keys' => $assignment->module_keys ?: self::DIST_MODULES];
                if ($brands !== null) {
                    // Accountants are BRAND-level (client meeting): a brands
                    // payload writes brand scope; stale restaurant ids are
                    // cleared unless restaurants were explicitly sent.
                    $updates['brand_ids'] = $brands;
                    $updates['scope'] = 'brand';
                    $updates['restaurant_ids'] = $restaurants ?? [];
                } else {
                    // Backward compat: restaurants-only payloads keep the old
                    // reconcile-to-EXACTLY-the-given-array restaurant scope.
                    $updates['restaurant_ids'] = $restaurants;
                    $updates['scope'] = 'restaurant';
                }
                $assignment->update($updates);
                // Coverage just changed: a module cell for a restaurant that is
                // no longer covered must stop counting towards `module_keys`,
                // which is what auth resolution reads. No-op before the grid is
                // first edited (the legacy flat list above stands).
                $this->modules->resyncUnion($assignment);
                // …and the account's TENANT. Distribution used to write scope
                // only, so an accountant created without a company stayed
                // companyless after being assigned brands — every company
                // surface then answered «المستخدم غير مرتبط بشركة» and their
                // brand list was empty (reported 2026-08-04).
                $this->backfillCompanyFromScope($acc, $brands, $restaurants);
            });

            $fresh = $assignment->fresh();

            return $this->ok([
                'id' => $accId,
                'headId' => $acc->fresh()->reports_to_id,
                'scope' => $fresh->scope,
                'brands' => $fresh->brand_ids ?? [],
                'restaurants' => $fresh->restaurant_ids ?? [],
            ]);
        });
    }

    /**
     * Give a companyless account the tenant its new scope implies.
     *
     * Only ever FILLS a NULL — moving an accountant between companies is not a
     * side effect of a distribution edit, and their operations/reports already
     * carry the old company. Brands (or the restaurants' brands) must resolve to
     * exactly one company; anything else is left alone for an admin to fix
     * explicitly rather than guessed at.
     *
     * @param  array<int, string>|null  $brands
     * @param  array<int, string>|null  $restaurants
     */
    private function backfillCompanyFromScope(AsabUser $acc, ?array $brands, ?array $restaurants): void
    {
        if ($acc->company_id !== null) {
            return;
        }

        $companyIds = collect();
        if (! empty($brands)) {
            $companyIds = AsabBrand::withoutGlobalScope('tenant')->whereIn('id', $brands)->pluck('company_id');
        } elseif (! empty($restaurants)) {
            $companyIds = AsabRestaurant::withoutGlobalScope('tenant')->whereIn('id', $restaurants)->pluck('company_id');
        }

        $resolved = $companyIds->filter()->unique()->values();
        if ($resolved->count() === 1) {
            $acc->forceFill(['company_id' => $resolved->first()])->save();
        }
    }

    /**
     * GET /admin/accountants/{accId}/modules — the «مصفوفة الموديولات» grid:
     * one row per restaurant the accountant covers, WITH its name, brand and the
     * modules granted for it. This is the read the screen was missing; it used
     * to derive rows from a bare id list, so it printed uuids and 0/9.
     */
    public function modulesMatrix(string $accId): JsonResponse
    {
        return $this->run(function () use ($accId) {
            $acc = AsabUser::findOrFail($accId);
            $assignment = $this->accountantAssignment($accId);
            $rows = $this->modules->matrix($assignment);
            $granted = $this->modules->grantedUnion(
                collect($rows)->mapWithKeys(fn ($r) => [$r['restaurantId'] => $r['modules']])->all(),
            );

            return $this->ok([
                'accountantId' => $acc->id,
                'accountantName' => $acc->name,
                'headId' => $acc->reports_to_id,
                'brands' => $assignment->brand_ids ?? [],
                'moduleCatalog' => ModuleCatalog::catalog(),
                'restaurants' => $rows,
                'totals' => [
                    'restaurants' => count($rows),
                    'modulesGranted' => count($granted),
                    'modules' => count(ModuleCatalog::keys()),
                ],
                // Empty `restaurants` has exactly one cause worth telling the
                // admin about: the accountant covers no restaurant yet because
                // no brand (with restaurants) is assigned to them.
                'reason' => $rows === [] ? 'NO_COVERED_RESTAURANTS' : null,
            ]);
        });
    }

    /**
     * PUT /admin/accountants/{accId}/modules — save several grid rows at once.
     * Only the restaurants present in the body are written; an id outside the
     * accountant's coverage refuses the WHOLE save (no half-applied grid).
     */
    public function replaceModulesMatrix(Request $request, string $accId): JsonResponse
    {
        return $this->run(function () use ($request, $accId) {
            $data = $request->validate([
                'restaurants' => 'required|array|min:1',
                'restaurants.*.restaurantId' => 'required|string',
                'restaurants.*.modules' => 'present|array',
                'restaurants.*.modules.*' => ['string', Rule::in(ModuleCatalog::keys())],
            ]);

            $assignment = $this->accountantAssignment($accId);
            $rows = DB::transaction(fn () => $this->modules->replaceMatrix(
                $assignment, $data['restaurants'], $request->user()?->id,
            ));
            foreach ($data['restaurants'] as $row) {
                $this->auditModules($request, $accId, $row['restaurantId'], $row['modules'] ?? []);
            }

            return $this->ok([
                'accountantId' => $accId,
                'moduleCatalog' => ModuleCatalog::catalog(),
                'restaurants' => $rows,
            ]);
        });
    }

    /**
     * PUT /admin/accountants/{accId}/restaurants/{restaurant}/modules (doc §1.9) —
     * set the module list for ONE of the accountant's restaurants.
     *
     * Now genuinely per-restaurant (ADM-3.3): the cell is stored in
     * `asab_accountant_restaurant_modules` and `module_keys` keeps the union for
     * auth resolution. The old version wrote the flat list AND pushed the
     * restaurant into `restaurant_ids` with `scope='restaurant'` — a permissions
     * save that silently widened the accountant's data scope.
     */
    public function restaurantModules(Request $request, string $accId, string $restaurant): JsonResponse
    {
        return $this->run(function () use ($request, $accId, $restaurant) {
            $data = $request->validate([
                'modules' => 'present|array',
                'modules.*' => ['string', Rule::in(ModuleCatalog::keys())],
            ]);

            $assignment = $this->accountantAssignment($accId);
            $row = DB::transaction(fn () => $this->modules->setModules(
                $assignment, $restaurant, $data['modules'], $request->user()?->id,
            ));
            $this->auditModules($request, $accId, $restaurant, $row['modules']);

            return $this->ok([
                'accountantId' => $accId,
                'restaurant' => $restaurant,
                'restaurantName' => $row['restaurantName'] ?? null,
                'modules' => $row['modules'],
                'moduleCount' => $row['moduleCount'],
                'total' => $row['total'],
            ]);
        });
    }

    private function mutateRestaurant(Request $request, bool $add): JsonResponse
    {
        return $this->run(function () use ($request, $add) {
            $data = $request->validate(['accountantId' => 'required|string', 'restaurantId' => 'required|string']);
            $assignment = $this->accountantAssignment($data['accountantId']);

            DB::transaction(function () use ($assignment, $data, $add) {
                $ids = collect($assignment->restaurant_ids ?? []);
                $ids = $add ? $ids->push($data['restaurantId'])->unique() : $ids->reject(fn ($i) => $i === $data['restaurantId']);
                $assignment->update([
                    'restaurant_ids' => $ids->values()->all(),
                    'scope' => 'restaurant',
                    'module_keys' => $assignment->module_keys ?: self::DIST_MODULES,
                ]);
                $this->modules->resyncUnion($assignment);
            });

            return $this->noContent();
        });
    }

    /** @param  string[]  $modules */
    private function auditModules(Request $request, string $accId, string $restaurantId, array $modules): void
    {
        $this->audit->record(
            'permissions', $request->user(), 'accountant_restaurant_modules', $accId,
            'تعديل صلاحيات موديولات المحاسب لمطعم',
            [], ['restaurantId' => $restaurantId, 'modules' => $modules], $request,
        );
    }

    private function accountantAssignment(string $accountantId): AsabUserRole
    {
        return AsabUserRole::where('user_id', $accountantId)->where('role_key', 'accountant')->firstOrFail();
    }
}
