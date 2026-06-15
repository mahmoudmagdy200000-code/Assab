<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;

class BrandController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = AsabBrand::query();
            if ($companyId = $request->query('companyId')) {
                $q->where('company_id', $companyId);
            }
            $brands = $q->orderBy('name')->get();

            return $this->listResponse($brands->map(fn ($b) => $this->present($b, true))->all());
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'companyId' => 'required|string',
                'name' => 'required|string|max:120',
                'abbr' => 'nullable|string|max:8',
                'color' => 'nullable|string|max:16',
                // Doc §1.2: owner is the brand owner's display NAME (persisted to the
                // brand `owner` attribute, previously never set). ownerEmail stays.
                'owner' => 'nullable|string|max:191',
                'ownerEmail' => 'nullable|email|max:191',
                // Doc §1.2: plan is silver|gold|platinum. Kept nullable + max:32 so any
                // already-broader callers don't break (non-breaking superset).
                'plan' => 'nullable|in:silver,gold,platinum,فضي,ذهبي,بلاتيني',
                'modules' => 'nullable|array',
            ]);

            $brand = DB::transaction(fn () => AsabBrand::create([
                'company_id' => $data['companyId'],
                'name' => $data['name'],
                'abbr' => $data['abbr'] ?? null,
                'color' => $data['color'] ?? null,
                'owner' => $data['owner'] ?? null,
                'owner_email' => $data['ownerEmail'] ?? null,
                'plan' => $data['plan'] ?? null,
                'sub_status' => 'active',
                'modules' => $data['modules'] ?? [],
                'status' => 'active',
            ]));

            return $this->created($this->present($brand));
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $brand = AsabBrand::findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:120',
                'abbr' => 'sometimes|string|max:8',
                'color' => 'sometimes|string|max:16',
                'plan' => 'sometimes|string|max:32',
                'modules' => 'sometimes|array',
            ]);
            DB::transaction(fn () => $brand->update(array_filter([
                'name' => $data['name'] ?? null,
                'abbr' => $data['abbr'] ?? null,
                'color' => $data['color'] ?? null,
                'plan' => $data['plan'] ?? null,
                'modules' => $data['modules'] ?? null,
            ], fn ($v) => $v !== null)));

            return $this->ok($this->present($brand->fresh()));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            AsabBrand::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    /**
     * POST /admin/brands/{brandId}/auto-reminder — toggle the brand-level
     * auto-reminder switch (FE completion request §1.2). The toggle lives on the
     * brand row in AdminSubscriptions, not on a subscription id.
     */
    public function autoReminder(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $brandId) {
            $data = $request->validate(['enabled' => 'required|boolean']);
            $brand = AsabBrand::findOrFail($brandId);
            $brand->update(['auto_reminder_enabled' => $data['enabled']]);

            return $this->ok([
                'brandId' => $brand->id,
                'enabled' => (bool) $brand->auto_reminder_enabled,
                'updatedAt' => optional($brand->updated_at)->toIso8601String(),
            ]);
        });
    }

    private function present(AsabBrand $b, bool $withChildren = false): array
    {
        $data = [
            'id' => $b->id,
            'companyId' => $b->company_id,
            'name' => $b->name,
            'abbr' => $b->abbr,
            'color' => $b->color,
            'owner' => $b->owner,
            'ownerEmail' => $b->owner_email,
            'plan' => $b->plan,
            'subStatus' => $b->sub_status,
            'daysLeft' => $b->days_left,
            'modules' => $b->modules ?? [],
            'status' => $b->status,
        ];

        if ($withChildren) {
            $data['restaurants'] = AsabRestaurant::where('brand_id', $b->id)->orderBy('name')->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'name' => $r->name, 'city' => $r->city, 'status' => $r->status,
                ])->all();
        }

        return $data;
    }
}
