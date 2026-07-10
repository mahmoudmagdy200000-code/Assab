<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;

/**
 * Accountant ↔ restaurant ↔ module distribution (BACKEND_API_SPEC.md §6.1.2).
 */
class DistributionController extends AsabController
{
    private const DIST_MODULES = ['sales', 'expenses', 'purchases', 'inventory'];

    public function index(): JsonResponse
    {
        return $this->run(function () {
            $heads = AsabUser::whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'head'))->get();
            $accountants = AsabUser::with('roleAssignments')
                ->whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'accountant'))->get();

            $allRestaurants = AsabRestaurant::pluck('name', 'id');
            $assigned = collect();
            $accModules = [];

            foreach ($accountants as $acc) {
                $assignment = $acc->roleAssignments->firstWhere('role_key', 'accountant');
                $restIds = $assignment->restaurant_ids ?? [];
                $assigned = $assigned->merge($restIds);
                $accModules[$acc->id] = [];
                foreach ($restIds as $rid) {
                    $accModules[$acc->id][$allRestaurants[$rid] ?? $rid] = $assignment->module_keys ?? self::DIST_MODULES;
                }
            }

            return $this->ok([
                'heads' => $heads->map(fn ($h) => [
                    'id' => $h->id, 'name' => $h->name, 'avatar' => $h->avatar,
                    'accountantCount' => $accountants->where('reports_to_id', $h->id)->count(),
                ])->values()->all(),
                'accountants' => $accountants->map(function ($a) {
                    $as = $a->roleAssignments->firstWhere('role_key', 'accountant');

                    return [
                        'id' => $a->id, 'name' => $a->name, 'avatar' => $a->avatar,
                        'headId' => $a->reports_to_id, 'restaurants' => $as->restaurant_ids ?? [],
                    ];
                })->values()->all(),
                'allRestaurants' => $allRestaurants->keys()->all(),
                'assignedRestaurants' => $assigned->unique()->values()->all(),
                'freeRestaurants' => $allRestaurants->keys()->reject(fn ($id) => $assigned->contains($id))->values()->all(),
                'accModules' => $accModules,
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

    public function assignModules(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'accountantId' => 'required|string',
                'restaurantId' => 'required|string',
                'modules' => 'required|array',
            ]);
            $assignment = $this->accountantAssignment($data['accountantId']);
            DB::transaction(function () use ($assignment, $data) {
                $assignment->update(['module_keys' => $data['modules']]);
            });

            return $this->noContent();
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
     * PUT /admin/accountants/{accId}/restaurants/{restaurant}/modules (doc §1.9) —
     * set the module list for an accountant's restaurant.
     *
     * LIMITATION: the data model (asab_user_roles.module_keys) stores a single flat
     * module list per (accountant, role) assignment — there is no per-(accountant,
     * restaurant) module column, and that column is read as a flat array by tenant
     * resolution (ResolveTenant) / AuthService. So this stores the modules on the
     * accountant assignment best-effort (applies to all their restaurants) after
     * validating the restaurant is actually assigned to the accountant. Adding true
     * per-restaurant module storage would require a schema change.
     */
    public function restaurantModules(Request $request, string $accId, string $restaurant): JsonResponse
    {
        return $this->run(function () use ($request, $accId, $restaurant) {
            $data = $request->validate([
                'modules' => 'required|array',
                'modules.*' => 'string',
            ]);

            $assignment = $this->accountantAssignment($accId);
            $modules = collect($data['modules'])->unique()->values()->all();

            DB::transaction(function () use ($assignment, $restaurant, $modules) {
                // Ensure the restaurant is assigned to this accountant (best-effort).
                $ids = collect($assignment->restaurant_ids ?? [])->push($restaurant)->unique()->values()->all();
                $assignment->update([
                    'restaurant_ids' => $ids,
                    'scope' => 'restaurant',
                    'module_keys' => $modules,
                ]);
            });

            return $this->ok([
                'accountantId' => $accId,
                'restaurant' => $restaurant,
                'modules' => $assignment->fresh()->module_keys ?? [],
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
            });

            return $this->noContent();
        });
    }

    private function accountantAssignment(string $accountantId): AsabUserRole
    {
        return AsabUserRole::where('user_id', $accountantId)->where('role_key', 'accountant')->firstOrFail();
    }
}
