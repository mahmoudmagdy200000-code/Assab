<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
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

            return $this->listResponse($subs->map(fn ($s) => $this->present($s))->all());
        });
    }

    public function restaurants(): JsonResponse
    {
        return $this->run(function () {
            $subs = AsabSubscription::query()->whereNotNull('restaurant_id')->orderBy('expires_at')->get();
            $restaurants = \Modules\Admin\Models\AsabRestaurant::whereIn('id', $subs->pluck('restaurant_id')->filter())->get()->keyBy('id');
            $brands = \Modules\Admin\Models\AsabBrand::whereIn('id', $subs->pluck('brand_id')->filter())->get()->keyBy('id');

            return $this->listResponse($subs->map(function ($s) use ($restaurants, $brands) {
                $base = $this->present($s);
                $base['restaurantName'] = optional($restaurants->get($s->restaurant_id))->name;
                $base['brandName'] = optional($brands->get($s->brand_id))->name;

                return $base;
            })->all());
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
            $data = $request->validate(['plan' => 'required|in:فضي,ذهبي,بلاتيني']);
            $sub = AsabSubscription::findOrFail($id);
            $price = ['فضي' => 100000, 'ذهبي' => 175000, 'بلاتيني' => 250000][$data['plan']] ?? $sub->monthly_price;
            DB::transaction(fn () => $sub->update(['plan' => $data['plan'], 'monthly_price' => $price]));

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
        return $this->subs->present($s);
    }
}
