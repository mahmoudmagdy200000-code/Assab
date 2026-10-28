<?php

namespace Modules\Admin\Services;

use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\BillingInvoiceLine;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Plan;
use Modules\Admin\Notifications\CompanyAdminWelcomeNotification;

/**
 * Provisioning side-effects for admin "Add Company" (FE completion request §1.4):
 * the company-admin account + welcome email, and the optional annual invoice.
 * Kept out of the controller so the HTTP layer stays thin (SRP).
 */
class CompanyProvisioningService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    /**
     * Create the company-admin user + role and email a one-time password.
     * Returns the created user (or null if no contact email was supplied).
     */
    public function createAdminUser(AsabCompany $company, string $email, ?string $name, ?string $phone): AsabUser
    {
        $oneTimePassword = TemporaryPassword::generate();

        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => $name ?: $company->name,
            'email' => $email,
            'phone' => $phone,
            'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
            'status' => 'active',
            'default_page' => '/company/me/dashboard',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);

        CompanyUser::firstOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['role_key' => 'company-admin', 'status' => 'active']
        );

        // Best-effort delivery: a mail-transport outage must not fail company creation.
        try {
            $user->notify(new CompanyAdminWelcomeNotification($company->name, $oneTimePassword));
        } catch (\Throwable $e) {
            Log::warning('Company-admin welcome email failed: '.$e->getMessage());
        }

        return $user;
    }

    /**
     * Provision an authoritative subscription for the company (SUB-01 / BE-11).
     *
     * Creates the active subscription record without auto-generating any BillingInvoice.
     * Fails loudly inside the surrounding DB::transaction if the plan catalog entry is missing.
     */
    public function provisionSubscription(AsabCompany $company, string $planLabel, string $billingCycle = 'monthly'): CompanySubscription
    {
        $plan = Plan::where('code', strtolower($planLabel))->first();
        if (! $plan) {
            throw new \InvalidArgumentException("No catalog plan matching '{$planLabel}'");
        }

        $isAnnual = strtolower($billingCycle) === 'annual';
        $sub = CompanySubscription::firstOrCreate(
            ['company_id' => $company->id],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => $isAnnual ? 'annual' : 'monthly',
                'current_period_start' => now(),
                'current_period_end' => $isAnnual ? now()->addYear() : now()->addMonth(),
                'start_date' => now(),
                'auto_renew' => true,
            ],
        );

        // Also sync AsabSubscription so both stores remain authoritative and consistent
        \Modules\Admin\Models\AsabSubscription::firstOrCreate(
            ['company_id' => $company->id],
            [
                'plan' => $plan->name_ar ?? $planLabel,
                'status' => 'active',
                'expires_at' => $isAnnual ? now()->addYear() : now()->addMonth(),
                'days_left' => $isAnnual ? 365 : 30,
                'monthly_price' => $plan->price_monthly ?? 0,
                'reminder_enabled' => true,
                'modules' => $company->modules ?? [],
            ],
        );

        return $sub;
    }

    /**
     * Legacy backward compatibility method: delegates to provisionSubscription with zero invoices.
     */
    public function createAnnualInvoice(AsabCompany $company, string $planLabel): ?BillingInvoice
    {
        $this->provisionSubscription($company, $planLabel, 'annual');

        return null;
    }
}
