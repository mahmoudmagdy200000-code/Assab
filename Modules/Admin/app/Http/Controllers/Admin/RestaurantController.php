<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Services\AccountantScopeService;
use Modules\Admin\Services\AsabSubscriptionService;
use Modules\Admin\Services\RealtimeBroadcaster;

class RestaurantController extends AsabController
{
    public function __construct(private readonly AccountantScopeService $scope) {}

    /**
     * POST /admin/restaurants/{restaurantId}/subscription/renew — renew the
     * restaurant's subscription by id (FE completion request §1.1). The admin
     * UI only holds the restaurant id, not the subscription id.
     */
    public function renewSubscription(Request $request, AsabSubscriptionService $subs, RealtimeBroadcaster $rt, string $restaurantId): JsonResponse
    {
        return $this->run(function () use ($request, $subs, $rt, $restaurantId) {
            AsabRestaurant::findOrFail($restaurantId);
            $data = $request->validate(['months' => 'sometimes|integer|min:1|max:60']);

            $sub = $subs->forRestaurant($restaurantId);
            if (! $sub) {
                throw new AsabException('NOT_FOUND', 'No subscription for restaurant', 'لا يوجد اشتراك لهذا المطعم', 404);
            }

            $fresh = $subs->renew($sub, (int) ($data['months'] ?? 12));
            $rt->asabSubscriptionUpdated($fresh);

            return $this->ok($subs->presentWithNames($fresh));
        });
    }

    public function store(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $brandId) {
            $brand = AsabBrand::findOrFail($brandId);
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'city' => 'nullable|string|max:80',
                'status' => 'nullable|in:active,suspended',
            ]);

            $restaurant = DB::transaction(fn () => AsabRestaurant::create([
                'brand_id' => $brand->id,
                'company_id' => $brand->company_id,
                'name' => $data['name'],
                'city' => $data['city'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]));

            return $this->created($this->present($restaurant));
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $restaurant = AsabRestaurant::findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200',
                'city' => 'sometimes|string|max:80',
                'status' => 'sometimes|in:active,suspended',
            ]);
            DB::transaction(fn () => $restaurant->update(array_filter($data, fn ($v) => $v !== null)));

            return $this->ok($this->present($restaurant->fresh()));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            AsabRestaurant::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    private function present(AsabRestaurant $r): array
    {
        return [
            'id' => $r->id,
            'brandId' => $r->brand_id,
            'companyId' => $r->company_id,
            'name' => $r->name,
            'city' => $r->city,
            // Derived from brand-scoped accountant assignments, not the stored
            // column (which is never updated for brand-level accountants and
            // reported "0" in the field).
            'accountantCount' => $this->scope->accountantCounts([$r])[$r->id] ?? 0,
            'status' => $r->status,
        ];
    }
}
