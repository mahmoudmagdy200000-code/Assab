<?php

namespace Tests\Feature\Subscription;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Plan;
use Tests\TestCase;

/**
 * BE-11 / SUB-01: Single authoritative subscription store and discontinuation of auto-billing invoices.
 *
 * Requirements:
 * 1. Both monthly and annual company provisioning create an active subscription so GET /company/me/subscription returns 200.
 * 2. No BillingInvoice is created upon provisioning (annual or monthly).
 * 3. Self-service upgrade does not generate proration BillingInvoice.
 * 4. Company provisioning rolls back completely on missing/invalid plan.
 */
class AuthoritativeSubscriptionSourceTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $platformAdmin;
    private Plan $basicPlan;
    private Plan $proPlan;
    private Plan $enterprisePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = AsabUser::create([
            'name' => 'Platform Admin',
            'email' => 'admin@platform.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->platformAdmin->id,
            'role_key' => 'admin',
            'scope' => 'all',
        ]);

        $this->basicPlan = Plan::create([
            'code' => 'basic',
            'name_ar' => 'باقة أساسية',
            'name_en' => 'Basic Plan',
            'price_monthly' => 29900,
            'price_annual' => 299000,
            'max_branches' => 2,
            'max_users' => 5,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->proPlan = Plan::create([
            'code' => 'professional',
            'name_ar' => 'باقة متقدمة',
            'name_en' => 'Professional Plan',
            'price_monthly' => 59900,
            'price_annual' => 599000,
            'max_branches' => 10,
            'max_users' => 25,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 2,
        ]);

        $this->enterprisePlan = Plan::create([
            'code' => 'enterprise',
            'name_ar' => 'باقة مؤسسات',
            'name_en' => 'Enterprise Plan',
            'price_monthly' => 129900,
            'price_annual' => 1299000,
            'max_branches' => 100,
            'max_users' => 500,
            'modules_included' => [],
            'status' => 'active',
            'sort_order' => 3,
        ]);
    }

    public function test_monthly_company_provisioning_creates_active_subscription_and_zero_invoices(): void
    {
        $payload = [
            'name' => 'Monthly Gourmet Co',
            'plan' => 'Basic',
            'billingCycle' => 'monthly',
            'contactEmail' => 'admin@monthly-gourmet.test',
            'contactName' => 'Owner Monthly',
        ];

        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/companies', $payload);

        $res->assertCreated();
        $companyId = $res->json('id');
        $this->assertNotNull($companyId);

        // Rule: No billing invoice on creation
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());

        // Rule: Company admin can immediately query GET /company/me/subscription and get 200
        $adminUser = AsabUser::where('email', 'admin@monthly-gourmet.test')->firstOrFail();
        $subRes = $this->actingAs($adminUser, 'sanctum')
            ->getJson('/api/v1/company/me/subscription');

        $subRes->assertOk();
        $this->assertSame('active', $subRes->json('subscription.status'));
        $this->assertSame('basic', $subRes->json('plan.code'));
        $this->assertSame('monthly', $subRes->json('subscription.billingCycle'));
    }

    public function test_annual_company_provisioning_creates_active_subscription_with_zero_billing_invoices(): void
    {
        $payload = [
            'name' => 'Annual Gourmet Co',
            'plan' => 'Professional',
            'billingCycle' => 'annual',
            'contactEmail' => 'admin@annual-gourmet.test',
            'contactName' => 'Owner Annual',
        ];

        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/companies', $payload);

        $res->assertCreated();
        $companyId = $res->json('id');
        $this->assertNotNull($companyId);

        // Rule: No billing invoice on annual creation (discontinue auto-invoicing)
        $this->assertSame(0, BillingInvoice::where('company_id', $companyId)->count());

        // Rule: Subscription exists and has annual cycle
        $adminUser = AsabUser::where('email', 'admin@annual-gourmet.test')->firstOrFail();
        $subRes = $this->actingAs($adminUser, 'sanctum')
            ->getJson('/api/v1/company/me/subscription');

        $subRes->assertOk();
        $this->assertSame('active', $subRes->json('subscription.status'));
        $this->assertSame('professional', $subRes->json('plan.code'));
        $this->assertSame('annual', $subRes->json('subscription.billingCycle'));
    }

    public function test_subscription_upgrade_does_not_create_proration_billing_invoice(): void
    {
        $company = AsabCompany::create([
            'name' => 'Upgrade Test Co',
            'plan' => 'Basic',
            'status' => 'active',
        ]);
        $sub = CompanySubscription::create([
            'company_id' => $company->id,
            'plan_id' => $this->basicPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
            'start_date' => now()->subDays(10),
            'auto_renew' => true,
        ]);
        $adminUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Upgrade Admin',
            'email' => 'admin@upgrade-test.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $adminUser->id,
            'role_key' => 'company-admin',
            'status' => 'active',
        ]);

        $res = $this->actingAs($adminUser, 'sanctum')->postJson('/api/v1/company/me/subscription/upgrade', [
            'targetPlanCode' => 'professional',
            'billingCycle' => 'annual',
            'applyImmediately' => true,
        ]);

        $res->assertOk();
        $this->assertSame($this->proPlan->id, $res->json('subscription.planId'));
        $this->assertNull($res->json('prorationInvoice'));

        // Rule: Invoices table must remain empty
        $this->assertSame(0, BillingInvoice::where('company_id', $company->id)->count());
    }

    public function test_provisioning_with_missing_catalog_plan_rolls_back_entire_transaction(): void
    {
        // Delete enterprise plan so it does not exist in catalog
        $this->enterprisePlan->delete();

        $payload = [
            'name' => 'Doomed Enterprise Co',
            'plan' => 'Enterprise',
            'billingCycle' => 'annual',
            'contactEmail' => 'admin@doomed-enterprise.test',
        ];

        // Should fail due to missing plan in catalog
        $res = $this->actingAs($this->platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/companies', $payload);

        $res->assertStatus(422);

        // Entire transaction must be rolled back: company and user must NOT exist
        $this->assertDatabaseMissing('asab_companies', ['name' => 'Doomed Enterprise Co']);
        $this->assertDatabaseMissing('asab_users', ['email' => 'admin@doomed-enterprise.test']);
    }
}
