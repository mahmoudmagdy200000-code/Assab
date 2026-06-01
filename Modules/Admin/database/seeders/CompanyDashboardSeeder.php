<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BillingAddress;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\BillingInvoiceLine;
use Modules\Admin\Models\CompanyModule;
use Modules\Admin\Models\CompanyPreferences;
use Modules\Admin\Models\CompanySettings;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\PaymentMethod;
use Modules\Admin\Models\Plan;
use Modules\Admin\Models\PlanFeature;
use Modules\Admin\Models\SupportChannel;

/**
 * Seeds the Company Dashboard surface (COMPANY_DASHBOARD_API_SPEC.md):
 * plan catalog, support channels, and the demo company's subscription,
 * settings, modules, billing and a company-admin login. Password: "password".
 */
class CompanyDashboardSeeder extends Seeder
{
    private const ALL_MODULES = ['sales', 'expenses', 'purchases', 'inventory', 'assets', 'shifts', 'waste', 'emp', 'cash'];

    public function run(): void
    {
        $this->seedPlans();
        $this->seedSupportChannels();
        $this->seedDemoCompany();
    }

    private function seedPlans(): void
    {
        $plans = [
            [
                'code' => 'basic', 'name_ar' => 'أساسي', 'name_en' => 'Basic',
                'price_monthly' => 19900, 'price_annual' => 199000, 'max_branches' => 5, 'max_users' => 15,
                'max_brands' => 2, 'max_restaurants' => 5, 'storage_gb' => 2,
                'modules_included' => ['sales', 'expenses', 'purchases', 'inventory'], 'sort_order' => 1,
                'features' => ['5 فروع', '15 مستخدم', '2 جيجابايت تخزين', '4 وحدات أساسية'],
            ],
            [
                'code' => 'professional', 'name_ar' => 'احترافي', 'name_en' => 'Professional',
                'price_monthly' => 40000, 'price_annual' => 480000, 'max_branches' => 20, 'max_users' => 50,
                'max_brands' => null, 'max_restaurants' => null, 'storage_gb' => 10,
                'modules_included' => self::ALL_MODULES, 'has_advanced_reports' => true, 'sort_order' => 2,
                'features' => ['20 فرعاً', '50 مستخدم', '10 جيجابايت تخزين', 'كل الوحدات', 'تقارير متقدمة'],
            ],
            [
                'code' => 'enterprise', 'name_ar' => 'مؤسسي', 'name_en' => 'Enterprise',
                'price_monthly' => null, 'price_annual' => null, 'max_branches' => null, 'max_users' => null,
                'max_brands' => null, 'max_restaurants' => null, 'storage_gb' => null,
                'modules_included' => self::ALL_MODULES, 'has_advanced_reports' => true, 'has_account_manager' => true,
                'has_sla' => true, 'sla_uptime_pct' => 999, 'has_open_api' => true, 'sort_order' => 3,
                'features' => ['فروع غير محدودة', 'مستخدمون بلا حد', 'تخزين مخصص', 'كل الوحدات', 'SLA 99.9%', 'Open API', 'مدير حساب مخصص'],
            ],
        ];

        foreach ($plans as $p) {
            $features = $p['features'];
            unset($p['features']);
            $plan = Plan::updateOrCreate(['code' => $p['code']], $p);
            foreach ($features as $i => $label) {
                PlanFeature::updateOrCreate(
                    ['plan_id' => $plan->id, 'label_ar' => $label],
                    ['label_en' => $label, 'sort_order' => $i + 1, 'is_highlighted' => $i === 0],
                );
            }
        }
    }

    private function seedSupportChannels(): void
    {
        $channels = [
            ['key' => 'chat', 'label_ar' => 'الدردشة المباشرة', 'label_en' => 'Live Chat', 'value' => 'chat', 'hours_ar' => 'يومياً 8ص - 12م', 'icon' => '💬', 'sort_order' => 1],
            ['key' => 'phone', 'label_ar' => 'الهاتف', 'label_en' => 'Phone', 'value' => '800 123 4567', 'hours_ar' => 'الأحد-الخميس 9ص-6م', 'icon' => '📞', 'sort_order' => 2],
            ['key' => 'email', 'label_ar' => 'البريد الإلكتروني', 'label_en' => 'Email', 'value' => 'support@asab.sa', 'hours_ar' => 'رد خلال 24 ساعة', 'icon' => '✉️', 'sort_order' => 3],
        ];
        foreach ($channels as $c) {
            SupportChannel::updateOrCreate(['key' => $c['key']], $c);
        }
    }

    private function seedDemoCompany(): void
    {
        $company = AsabCompany::where('contact_email', 'group@asab.sa')->first();
        if (! $company) {
            return; // AsabDemoSeeder not run yet
        }
        $pro = Plan::where('code', 'professional')->first();

        $sub = CompanySubscription::updateOrCreate(
            ['company_id' => $company->id],
            [
                'plan_id' => $pro->id, 'status' => 'active', 'billing_cycle' => 'annual',
                'current_period_start' => now()->subDays(278), 'current_period_end' => now()->addDays(87),
                'start_date' => now()->subYear(), 'days_remaining' => 87, 'auto_renew' => true,
                'contract_number' => 'CT-2025-0042',
            ],
        );

        CompanySettings::updateOrCreate(['company_id' => $company->id], [
            'legal_name' => 'مجموعة التاج للمطاعم', 'display_name' => 'مجموعة التاج', 'logo_emoji' => '👑',
            'primary_city' => 'الرياض', 'cr_number' => '1010234567', 'tax_id' => '300012345600003',
            'email' => 'group@asab.sa', 'phone' => '0551234567', 'vat_percentage' => 15, 'updated_at' => now(),
        ]);

        CompanyPreferences::updateOrCreate(['company_id' => $company->id], [
            'notify_on_approval' => true, 'notify_on_sub_expiring' => true,
            'pos_integrations' => ['foodics'], 'delivery_app_integrations' => ['hungerstation', 'jahez'],
        ]);

        foreach (self::ALL_MODULES as $m) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $m],
                ['is_active' => true, 'is_in_plan' => true],
            );
        }

        BillingAddress::updateOrCreate(
            ['company_id' => $company->id, 'is_default' => true],
            [
                'legal_name' => 'مجموعة التاج للمطاعم', 'tax_id' => '300012345600003', 'cr_number' => '1010234567',
                'address_line1' => 'طريق الملك فهد', 'city' => 'الرياض', 'region' => 'منطقة الرياض',
                'postal_code' => '12211', 'country' => 'SA', 'contact_email' => 'group@asab.sa',
            ],
        );

        $pm = PaymentMethod::updateOrCreate(
            ['company_id' => $company->id, 'last4' => '4521'],
            ['type' => 'mada', 'brand' => 'mada', 'exp_month' => 9, 'exp_year' => 2027,
                'holder_name' => 'TAJ GROUP', 'provider_token' => 'tok_demo_4521', 'provider_name' => 'moyasar',
                'is_default' => true, 'status' => 'active'],
        );
        $sub->update(['default_payment_method_id' => $pm->id]);

        // Two sample invoices: one paid, one open.
        $this->seedInvoice($company->id, $sub->id, $pm->id, 'INV-2025-011', 'paid', now()->subDays(278));
        $this->seedInvoice($company->id, $sub->id, $pm->id, 'INV-2026-001', 'open', now()->subDays(2));

        // Company-admin login.
        $admin = AsabUser::updateOrCreate(
            ['email' => 'company-admin@asab.sa'],
            ['company_id' => $company->id, 'name' => 'أدمن الشركة', 'avatar' => 'أ',
                'password' => 'password', 'status' => 'active', 'default_page' => 'ca-dashboard'],
        );
        AsabUserRole::updateOrCreate(
            ['user_id' => $admin->id, 'role_key' => 'company-admin'],
            ['scope' => 'all', 'brand_ids' => [], 'restaurant_ids' => [], 'branch_ids' => [], 'module_keys' => self::ALL_MODULES],
        );
        CompanyUser::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $admin->id],
            ['role_key' => 'company-admin', 'status' => 'active', 'accepted_at' => now()],
        );

        // Register the other demo role users as company_users members.
        AsabUser::where('company_id', $company->id)->where('email', '!=', 'company-admin@asab.sa')->get()
            ->each(function (AsabUser $u) use ($company) {
                CompanyUser::updateOrCreate(
                    ['company_id' => $company->id, 'user_id' => $u->id],
                    ['role_key' => $u->primaryRole() ?? 'branch', 'status' => 'active', 'accepted_at' => now()],
                );
            });
    }

    private function seedInvoice(string $companyId, string $subId, string $pmId, string $publicId, string $status, $issued): void
    {
        $subtotal = 480000;
        $vat = (int) round($subtotal * 0.15);
        $total = $subtotal + $vat;
        $paid = $status === 'paid' ? $total : 0;

        $inv = BillingInvoice::updateOrCreate(
            ['public_id' => $publicId],
            [
                'company_id' => $companyId, 'subscription_id' => $subId, 'issue_date' => $issued,
                'due_date' => (clone $issued)->addDays(14), 'period_start' => $issued,
                'period_end' => (clone $issued)->addYear(), 'subtotal' => $subtotal, 'vat_rate' => 15,
                'vat_amount' => $vat, 'discount' => 0, 'total' => $total, 'amount_paid' => $paid,
                'amount_due' => $total - $paid, 'status' => $status,
                'payment_method_id' => $status === 'paid' ? $pmId : null,
                'paid_at' => $status === 'paid' ? $issued : null, 'currency' => 'SAR',
            ],
        );
        BillingInvoiceLine::updateOrCreate(
            ['invoice_id' => $inv->id, 'line_type' => 'subscription'],
            ['description' => 'اشتراك سنوي - خطة احترافي', 'quantity' => 1, 'unit_price' => $subtotal, 'amount' => $subtotal, 'sort_order' => 1],
        );
    }
}
