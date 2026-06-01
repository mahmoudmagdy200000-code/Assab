<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\Plan;
use Modules\Admin\Models\SupportTicket;
use Modules\Admin\Services\PlanLimitService;
use Modules\Admin\Services\SubscriptionService;

/**
 * Company subscription & plan management (COMPANY_DASHBOARD_API_SPEC.md §5.1.2).
 */
class SubscriptionController extends AsabController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanLimitService $limits,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $sub = $this->subscriptions->current($companyId);

            return $this->ok([
                'subscription' => $this->presentSub($sub),
                'plan' => $this->presentPlan($sub->plan, $sub->plan_id),
                'usage' => $this->limits->quotas($companyId),
                'nextInvoice' => [
                    'issueDate' => optional($sub->current_period_end)->toIso8601String(),
                    'amountHalalas' => $this->subscriptions->priceFor($sub->plan, $sub->billing_cycle),
                    'currency' => 'SAR',
                ],
            ]);
        });
    }

    public function plans(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $currentPlanId = optional(CompanySubscription::withoutGlobalScopes()
                ->where('company_id', $request->user()->company_id)->first())?->plan_id;

            $plans = Plan::where('status', 'active')->orderBy('sort_order')->with('features')->get()
                ->map(fn (Plan $p) => $this->presentPlan($p, $currentPlanId))->all();

            return $this->listResponse($plans);
        });
    }

    public function upgrade(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'targetPlanCode' => 'required|in:professional,enterprise',
                'billingCycle' => 'required|in:monthly,annual',
                'paymentMethodId' => 'sometimes|string',
                'applyImmediately' => 'sometimes|boolean',
            ]);
            $r = $this->subscriptions->upgrade(
                $request->user()->company_id, $data['targetPlanCode'], $data['billingCycle'],
                $request->user(), $data['applyImmediately'] ?? true,
            );

            return $this->ok($this->presentChangeResult($r));
        });
    }

    public function downgrade(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'targetPlanCode' => 'required|in:basic,professional',
                'effectiveAt' => 'sometimes|in:immediately,period_end',
            ]);
            $r = $this->subscriptions->downgrade(
                $request->user()->company_id, $data['targetPlanCode'], $request->user(), $data['effectiveAt'] ?? 'period_end',
            );

            return $this->ok($this->presentChangeResult($r));
        });
    }

    public function cancel(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'reason' => 'required|in:too_expensive,missing_features,switching_provider,shutting_down,other',
                'feedback' => 'sometimes|string|max:1000',
                'cancelAtPeriodEnd' => 'sometimes|boolean',
            ]);
            $sub = $this->subscriptions->cancel(
                $request->user()->company_id, $data['reason'], $data['feedback'] ?? null,
                $request->user(), $data['cancelAtPeriodEnd'] ?? true,
            );

            return $this->ok($this->presentSub($sub));
        });
    }

    public function reactivate(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->presentSub($this->subscriptions->reactivate($request->user()->company_id, $request->user()))
        ));
    }

    public function contactSales(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'companySize' => 'sometimes|string|max:80',
                'expectedBranches' => 'sometimes|integer|min:0',
                'message' => 'sometimes|string|max:2000',
            ]);
            SupportTicket::create([
                'public_id' => 'TCK-'.str_pad((string) (SupportTicket::withoutGlobalScopes()->count() + 1), 3, '0', STR_PAD_LEFT),
                'company_id' => $request->user()->company_id, 'opened_by_id' => $request->user()->id,
                'category' => 'subscription', 'subject' => 'طلب ترقية لخطة مؤسسي (Enterprise)',
                'body' => $data['message'] ?? 'طلب تواصل مع المبيعات', 'priority' => 'high', 'status' => 'open',
            ]);

            return $this->ok(['accepted' => true], 202);
        });
    }

    public function billingCycle(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['billingCycle' => 'required|in:annual,monthly']);
            $sub = $this->subscriptions->changeCycle($request->user()->company_id, $data['billingCycle'], $request->user());

            return $this->ok($this->presentSub($sub));
        });
    }

    private function presentSub(CompanySubscription $s): array
    {
        return [
            'id' => $s->id, 'companyId' => $s->company_id, 'planId' => $s->plan_id, 'status' => $s->status,
            'billingCycle' => $s->billing_cycle, 'currentPeriodStart' => optional($s->current_period_start)->toIso8601String(),
            'currentPeriodEnd' => optional($s->current_period_end)->toIso8601String(), 'trialEndsAt' => optional($s->trial_ends_at)->toIso8601String(),
            'cancelAtPeriodEnd' => (bool) $s->cancel_at_period_end, 'cancelledAt' => optional($s->cancelled_at)->toIso8601String(),
            'daysRemaining' => $s->days_remaining, 'autoRenew' => (bool) $s->auto_renew, 'contractNumber' => $s->contract_number,
        ];
    }

    private function presentPlan(Plan $p, ?string $currentPlanId): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en,
            'priceMonthlyHalalas' => $p->price_monthly, 'priceAnnualHalalas' => $p->price_annual,
            'annualDiscountPct' => $p->annual_discount_pct,
            'features' => $p->features->map(fn ($f) => ['labelAr' => $f->label_ar, 'labelEn' => $f->label_en, 'isHighlighted' => (bool) $f->is_highlighted])->all(),
            'maxBranches' => $p->max_branches, 'maxUsers' => $p->max_users, 'maxBrands' => $p->max_brands,
            'storageGb' => $p->storage_gb, 'isCurrent' => $p->id === $currentPlanId,
        ];
    }

    private function presentChangeResult(array $r): array
    {
        return [
            'subscription' => $this->presentSub($r['subscription']),
            'change' => ['id' => $r['change']->id, 'changeType' => $r['change']->change_type, 'prorationAmount' => $r['change']->proration_amount],
            'prorationInvoice' => $r['prorationInvoice'] ? ['id' => $r['prorationInvoice']->id, 'publicId' => $r['prorationInvoice']->public_id, 'totalHalalas' => $r['prorationInvoice']->total] : null,
        ];
    }
}
