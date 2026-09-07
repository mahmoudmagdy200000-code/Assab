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

        // Best-effort delivery: a mail-transport outage must not fail company creation.
        try {
            $user->notify(new CompanyAdminWelcomeNotification($company->name, $oneTimePassword));
        } catch (\Throwable $e) {
            Log::warning('Company-admin welcome email failed: '.$e->getMessage());
        }

        return $user;
    }

    /**
     * Provision an annual subscription + its first invoice and emit invoice.created.
     * Best-effort: if the plan catalog isn't present the company is still created.
     */
    public function createAnnualInvoice(AsabCompany $company, string $planLabel): ?BillingInvoice
    {
        try {
            $plan = Plan::where('code', strtolower($planLabel))->first();
            if (! $plan) {
                Log::warning("Annual invoice skipped — no plan matching '{$planLabel}'");

                return null;
            }

            $sub = CompanySubscription::firstOrCreate(
                ['company_id' => $company->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'billing_cycle' => 'annual',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addYear(),
                    'start_date' => now(),
                    'auto_renew' => true,
                ],
            );

            $subtotal = (int) ($plan->price_annual ?? 0);
            $vat = (int) round($subtotal * 0.15);
            $invoice = BillingInvoice::create([
                'company_id' => $company->id,
                'subscription_id' => $sub->id,
                'public_id' => $this->subscriptions->nextInvoiceId(),
                'issue_date' => now(),
                'due_date' => now()->addDays(14),
                'period_start' => now(),
                'period_end' => now()->addYear(),
                'subtotal' => $subtotal,
                'vat_rate' => 15,
                'vat_amount' => $vat,
                'discount' => 0,
                'total' => $subtotal + $vat,
                'amount_paid' => 0,
                'amount_due' => $subtotal + $vat,
                'status' => 'open',
                'currency' => 'SAR',
            ]);
            BillingInvoiceLine::create([
                'invoice_id' => $invoice->id,
                'description' => 'اشتراك سنوي — '.($plan->name_ar ?? $planLabel),
                'quantity' => 1,
                'unit_price' => $subtotal,
                'amount' => $subtotal,
                'line_type' => 'subscription',
                'sort_order' => 1,
            ]);

            $this->rt->invoiceCreated($invoice);

            return $invoice;
        } catch (\Throwable $e) {
            Log::warning('Annual invoice provisioning failed: '.$e->getMessage());

            return null;
        }
    }
}
