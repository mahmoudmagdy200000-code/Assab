<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrandPackage;

/**
 * Brand subscription package catalog (WS6 — client meeting: "manage packages",
 * the FE component for selecting a package when adding a brand). Admin sees
 * active and inactive packages; destroy() soft-deletes.
 */
class PackageController extends AsabController
{
    public function index(): JsonResponse
    {
        return $this->run(function () {
            $packages = AsabBrandPackage::orderBy('price')->get();

            return $this->listResponse($packages->map(fn ($p) => $this->present($p))->all());
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'code' => ['required', 'string', 'max:32', Rule::unique('asab_brand_packages', 'code')->whereNull('deleted_at')],
                'name' => 'required|string|max:120',
                'nameEn' => 'nullable|string|max:120',
                'price' => 'required|integer|min:0',
                'isActive' => 'sometimes|boolean',
            ]);

            // The DB unique index on code still covers soft-deleted rows, so
            // recreating a deleted code restores it (mirrors the seeder's
            // withTrashed()->updateOrCreate) instead of hitting the constraint.
            $package = DB::transaction(function () use ($data) {
                $attributes = [
                    'name' => $data['name'],
                    'name_en' => $data['nameEn'] ?? null,
                    'price' => $data['price'],
                    'is_active' => $data['isActive'] ?? true,
                ];

                $trashed = AsabBrandPackage::withTrashed()->where('code', $data['code'])->first();
                if ($trashed !== null) {
                    $trashed->restore();
                    $trashed->update($attributes);

                    return $trashed;
                }

                return AsabBrandPackage::create(['code' => $data['code']] + $attributes);
            });

            return $this->created($this->present($package));
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $package = AsabBrandPackage::findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:120',
                'nameEn' => 'sometimes|nullable|string|max:120',
                'price' => 'sometimes|integer|min:0',
                'isActive' => 'sometimes|boolean',
            ]);

            $updates = [];
            foreach (['name' => 'name', 'nameEn' => 'name_en', 'price' => 'price'] as $in => $col) {
                if (array_key_exists($in, $data)) {
                    $updates[$col] = $data[$in];
                }
            }
            if (array_key_exists('isActive', $data)) {
                $updates['is_active'] = (bool) $data['isActive'];
            }
            DB::transaction(fn () => $package->update($updates));

            return $this->ok($this->present($package->fresh()));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            AsabBrandPackage::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    private function present(AsabBrandPackage $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'nameEn' => $p->name_en,
            'price' => (int) $p->price,
            'isActive' => (bool) $p->is_active,
        ];
    }
}
