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
