<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;

class RestaurantController extends AsabController
{
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
            'accountantCount' => $r->accountant_count,
            'status' => $r->status,
        ];
    }
}
