<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Services\AsabSubscriptionService;

class SubscriptionController extends AsabController
{
    public function __construct(private readonly AsabSubscriptionService $subs) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = AsabSubscription::query();
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            $subs = $q->orderBy('expires_at')->get();
            $map = $this->subs->restaurantsByBrand($subs);

            return $this->listResponse($subs->map(fn ($s) => $this->subs->present($s, $map))->all());
        });
    }

    public function restaurants(): JsonResponse
    {
        return $this->run(function () {
            $subs = AsabSubscription::query()->whereNotNull('restaurant_id')->orderBy('expires_at')->get();
            $restaurants = \Modules\Admin\Models\AsabRestaurant::whereIn('id', $subs->pluck('restaurant_id')->filter())->get()->keyBy('id');
            $brands = AsabBrand::whereIn('id', $subs->pluck('brand_id')->filter())->get()->keyBy('id');
            $map = $this->subs->restaurantsByBrand($subs);

            return $this->listResponse($subs->map(function ($s) use ($restaurants, $brands, $map) {
                $base = $this->subs->present($s, $map);
                $base['restaurantName'] = optional($restaurants->get($s->restaurant_id))->name;
                $base['brandName'] = optional($brands->get($s->brand_id))->name;

                return $base;
            })->all());
        });
    }

    /**
     * POST /admin/subscriptions — create a brand subscription (Admin dashboard
     * contract batch 1, A4). English plan keys are mapped to the stored Arabic
     * values exactly like changePlan().
     */
    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'brandId' => 'required|string',
                'plan' => 'required|in:silver,gold,platinum,فضي,ذهبي,بلاتيني',
                'startDate' => 'sometimes|date',
                'months' => 'required|integer|min:1|max:36',
                'monthlyPrice' => 'sometimes|integer|min:0',
                'reminderEnabled' => 'sometimes|boolean',
                'modules' => 'sometimes|array',
                'modules.*' => 'string',
            ]);

            $brand = AsabBrand::findOrFail($data['brandId']);
            $plan = ['silver' => 'فضي', 'gold' => 'ذهبي', 'platinum' => 'بلاتيني'][$data['plan']] ?? $data['plan'];
            $price = $data['monthlyPrice'] ?? (['فضي' => 100000, 'ذهبي' => 175000, 'بلاتيني' => 250000][$plan] ?? 0);
            $start = isset($data['startDate']) ? Carbon::parse($data['startDate']) : now();
            $expires = $start->copy()->addMonths((int) $data['months']);

            $sub = DB::transaction(fn () => AsabSubscription::create([
                'company_id' => $brand->company_id,
                'brand_id' => $brand->id,
                'plan' => $plan,
                'status' => 'active',
                'expires_at' => $expires,
                'days_left' => (int) now()->diffInDays($expires),
                'monthly_price' => $price,
                'reminder_enabled' => $data['reminderEnabled'] ?? true,
                'modules' => $data['modules'] ?? [],
            ]));

            return $this->created($this->present($sub));
        });
    }

    /** PATCH /admin/subscriptions/{id}/modules (Admin dashboard contract batch 1, A5). */
    public function updateModules(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['modules' => 'required|array', 'modules.*' => 'string']);
            $sub = AsabSubscription::findOrFail($id);
            DB::transaction(fn () => $sub->update(['modules' => $data['modules']]));

            return $this->ok($this->present($sub->fresh()));
        });
    }

    public function renew(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $sub = AsabSubscription::findOrFail($id);
            $fresh = $this->subs->renew($sub, (int) $request->input('months', 12));

            return $this->ok($this->present($fresh));
        });
    }

    public function changePlan(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            // Doc §1.5c: accept English plan keys too, mapping them to the stored
            // Arabic values before persisting (non-breaking — Arabic still accepted).
            $data = $request->validate(['plan' => 'required|in:silver,gold,platinum,فضي,ذهبي,بلاتيني']);
            $plan = ['silver' => 'فضي', 'gold' => 'ذهبي', 'platinum' => 'بلاتيني'][$data['plan']] ?? $data['plan'];
            $sub = AsabSubscription::findOrFail($id);
            $price = ['فضي' => 100000, 'ذهبي' => 175000, 'بلاتيني' => 250000][$plan] ?? $sub->monthly_price;
            DB::transaction(fn () => $sub->update(['plan' => $plan, 'monthly_price' => $price]));

            return $this->ok($this->present($sub->fresh()));
        });
    }

    public function toggleAutoReminder(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['enabled' => 'required|boolean']);
            $sub = AsabSubscription::findOrFail($id);
            $sub->update(['reminder_enabled' => $data['enabled']]);

            return $this->ok($this->present($sub->fresh()));
        });
    }

    public function suspend(string $id): JsonResponse
    {
        return $this->setStatus($id, 'expired');
    }

    public function activate(string $id): JsonResponse
    {
        return $this->setStatus($id, 'active');
    }

    private function setStatus(string $id, string $status): JsonResponse
    {
        return $this->run(function () use ($id, $status) {
            $sub = AsabSubscription::findOrFail($id);
            $sub->update(['status' => $status]);

            return $this->ok($this->present($sub->fresh()));
        });
    }

    private function present(AsabSubscription $s): array
    {
        return $this->subs->present($s, $this->subs->restaurantsByBrand([$s]));
    }
}
