<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\BillingInvoiceLine;
use Modules\Admin\Models\CompanyModule;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\Plan;
use Modules\Admin\Models\SubscriptionChange;

/**
 * Company subscription lifecycle (COMPANY_DASHBOARD_API_SPEC.md §5.1.2 / §6):
 * upgrade/downgrade/cancel/reactivate/cycle change with proration, change-log,
 * invoice generation and module re-gating. Payment is mocked (no live PSP).
 */
class SubscriptionService
{
    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly NotificationService $notifications,
    ) {}

    public function priceFor(Plan $plan, string $cycle): ?int
    {
        return $cycle === 'annual' ? $plan->price_annual : $plan->price_monthly;
    }

    public function current(string $companyId): CompanySubscription
    {
        $sub = CompanySubscription::withoutGlobalScopes()->with('plan')->where('company_id', $companyId)->first();
        if (! $sub) {
            throw new AsabException('NOT_FOUND', 'No subscription for company', 'لا يوجد اشتراك للشركة', 404);
        }

        return $sub;
    }

    /**
     * @return array{subscription: CompanySubscription, change: SubscriptionChange, prorationInvoice: ?BillingInvoice}
     */
    public function upgrade(string $companyId, string $targetCode, string $cycle, AsabUser $actor, bool $applyImmediately = true): array
    {
        $sub = $this->current($companyId);
        $target = $this->planByCode($targetCode);

        if ($sub->plan_id === $target->id && $sub->billing_cycle === $cycle) {
            throw new AsabException('ALREADY_ON_PLAN', 'Already on this plan', 'أنت على هذه الخطة بالفعل', 409);
        }
        if (in_array($sub->status, ['cancelled', 'expired'], true)) {
            throw new AsabException('INVALID_TRANSITION', 'Cannot change a cancelled subscription', 'لا يمكن تعديل اشتراك ملغى', 409, ['currentStatus' => $sub->status]);
        }

        return $this->applyChange($sub, $target, $cycle, 'upgrade', $actor, $applyImmediately);
    }

    /**
     * @return array{subscription: CompanySubscription, change: SubscriptionChange, prorationInvoice: ?BillingInvoice}
     */
    public function downgrade(string $companyId, string $targetCode, AsabUser $actor, string $effectiveAt = 'period_end'): array
    {
        $sub = $this->current($companyId);
        $target = $this->planByCode($targetCode);

        $violations = $this->limits->downgradeViolations($companyId, $target);
        if ($violations) {
            throw new AsabException('QUOTA_WOULD_EXCEED', 'Current usage exceeds target plan limits', 'الاستهلاك الحالي يتجاوز حدود الخطة المستهدفة', 422, ['violations' => $violations]);
        }

        $immediate = $effectiveAt === 'immediately';

        return $this->applyChange($sub, $target, $sub->billing_cycle, 'downgrade', $actor, $immediate);
    }

    public function changeCycle(string $companyId, string $cycle, AsabUser $actor): CompanySubscription
    {
        $sub = $this->current($companyId);
        if ($sub->billing_cycle === $cycle) {
            return $sub;
        }

        $result = $this->applyChange($sub, $sub->plan, $cycle, 'cycle_change', $actor, true);

        return $result['subscription'];
    }

    public function cancel(string $companyId, string $reason, ?string $feedback, AsabUser $actor, bool $atPeriodEnd = true): CompanySubscription
    {
        $sub = $this->current($companyId);

        return DB::transaction(function () use ($sub, $reason, $feedback, $actor, $atPeriodEnd) {
            $sub->update([
                'cancel_at_period_end' => $atPeriodEnd,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason.($feedback ? ' — '.$feedback : '')),
                'status' => $atPeriodEnd ? $sub->status : 'cancelled',
                'auto_renew' => false,
            ]);
            $this->logChange($sub, 'cancel', $sub->plan_id, $sub->plan_id, $actor);
            $this->notifyAdmins($sub->company_id, 'subscription.cancelled', 'تم إلغاء الاشتراك', 'سيصبح الحساب للقراءة فقط بعد نهاية الفترة الحالية.');

            return $sub->fresh('plan');
        });
    }

    public function reactivate(string $companyId, AsabUser $actor): CompanySubscription
    {
        $sub = $this->current($companyId);
        if ($sub->status === 'expired') {
            throw new AsabException('SUBSCRIPTION_EXPIRED', 'Subscription expired — full re-subscribe required', 'انتهى الاشتراك — يلزم إعادة الاشتراك بالكامل', 409);
        }

        return DB::transaction(function () use ($sub, $actor) {
            $sub->update(['cancel_at_period_end' => false, 'cancelled_at' => null, 'cancellation_reason' => null, 'status' => 'active', 'auto_renew' => true]);
            $this->logChange($sub, 'reactivate', $sub->plan_id, $sub->plan_id, $actor);
            $this->notifyAdmins($sub->company_id, 'subscription.reactivated', 'تم إعادة تفعيل الاشتراك', null);

            return $sub->fresh('plan');
        });
    }

    /**
     * Core transition: writes the subscription, change-log, optional proration
     * invoice, and re-gates company modules.
     *
     * @return array{subscription: CompanySubscription, change: SubscriptionChange, prorationInvoice: ?BillingInvoice}
     */
    private function applyChange(CompanySubscription $sub, Plan $target, string $cycle, string $type, AsabUser $actor, bool $immediate): array
    {
        return DB::transaction(function () use ($sub, $target, $cycle, $type, $actor, $immediate) {
            $fromPlanId = $sub->plan_id;
            $fromCycle = $sub->billing_cycle;

            $proration = $immediate ? $this->prorationAmount($sub, $target, $cycle) : 0;

            if ($immediate) {
                $sub->update(['plan_id' => $target->id, 'billing_cycle' => $cycle]);
                $this->regateModules($sub->company_id, $target);
            } else {
                // Deferred to period end: record intent only.
                $sub->update(['billing_cycle' => $cycle]);
            }

            $change = $this->logChange($sub, $type, $fromPlanId, $target->id, $actor, $proration, $fromCycle, $cycle);

            $invoice = null;
            if ($immediate && $proration > 0) {
                $invoice = $this->prorationInvoice($sub, $target, $proration);
                $change->update(['invoice_id' => $invoice->id]);
            }

            $msg = $type === 'downgrade' ? 'subscription.downgraded' : 'subscription.upgraded';
            $this->notifyAdmins($sub->company_id, $msg, 'تم تحديث خطة الاشتراك', 'الخطة الجديدة: '.$target->name_ar);

            return ['subscription' => $sub->fresh('plan'), 'change' => $change, 'prorationInvoice' => $invoice];
        });
    }

    /** Pro-rated price difference for the remaining days of the current period. */
    private function prorationAmount(CompanySubscription $sub, Plan $target, string $cycle): int
    {
        $newPrice = $this->priceFor($target, $cycle) ?? 0;
        $oldPrice = $this->priceFor($sub->plan, $sub->billing_cycle) ?? 0;
        $periodDays = max(1, $sub->current_period_start->diffInDays($sub->current_period_end));
        $remaining = max(0, now()->diffInDays($sub->current_period_end, false));
        $fraction = min(1, $remaining / $periodDays);

        return (int) round(($newPrice - $oldPrice) * $fraction);
    }

    private function prorationInvoice(CompanySubscription $sub, Plan $target, int $proration): BillingInvoice
    {
        $vat = (int) round($proration * 0.15);
        $inv = BillingInvoice::create([
            'company_id' => $sub->company_id, 'subscription_id' => $sub->id, 'public_id' => $this->nextInvoiceId(),
            'issue_date' => now(), 'due_date' => now()->addDays(14), 'period_start' => now(),
            'period_end' => $sub->current_period_end, 'subtotal' => $proration, 'vat_rate' => 15, 'vat_amount' => $vat,
            'discount' => 0, 'total' => $proration + $vat, 'amount_paid' => 0, 'amount_due' => $proration + $vat,
            'status' => 'open', 'currency' => 'SAR',
        ]);
        BillingInvoiceLine::create([
            'invoice_id' => $inv->id, 'description' => 'فرق ترقية متناسب — '.$target->name_ar,
            'quantity' => 1, 'unit_price' => $proration, 'amount' => $proration, 'line_type' => 'proration', 'sort_order' => 1,
        ]);

        return $inv;
    }

    private function regateModules(string $companyId, Plan $plan): void
    {
        $included = $plan->modules_included ?? [];
        CompanyModule::withoutGlobalScopes()->where('company_id', $companyId)->get()->each(function (CompanyModule $m) use ($included) {
            $inPlan = in_array($m->module_key, $included, true);
            $m->update(['is_in_plan' => $inPlan, 'is_active' => $inPlan ? $m->is_active : false]);
        });
    }

    private function logChange(CompanySubscription $sub, string $type, ?string $fromPlan, ?string $toPlan, AsabUser $actor, int $proration = 0, ?string $fromCycle = null, ?string $toCycle = null): SubscriptionChange
    {
        return SubscriptionChange::create([
            'subscription_id' => $sub->id, 'change_type' => $type, 'from_plan_id' => $fromPlan, 'to_plan_id' => $toPlan,
            'from_billing_cycle' => $fromCycle, 'to_billing_cycle' => $toCycle, 'proration_amount' => $proration ?: null,
            'effective_at' => now(), 'initiated_by_id' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function notifyAdmins(string $companyId, string $type, string $title, ?string $body): void
    {
        $this->notifications->pushToRole($companyId, 'company-admin', $type, $title, $body);
    }

    public function nextInvoiceId(): string
    {
        $year = now()->format('Y');
        $n = BillingInvoice::withoutGlobalScopes()->where('public_id', 'like', "INV-{$year}-%")->count() + 1;

        return 'INV-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    private function planByCode(string $code): Plan
    {
        $plan = Plan::where('code', $code)->first();
        if (! $plan) {
            throw new AsabException('NOT_FOUND', 'Plan not found', 'الخطة غير موجودة', 404, ['code' => $code]);
        }

        return $plan;
    }
}
