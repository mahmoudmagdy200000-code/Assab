<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;

/**
 * Seeds a demo tenant + one user per role so the frontend can log in immediately.
 * Default password for every account: "password".
 */
class AsabDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = AsabCompany::updateOrCreate(
            ['contact_email' => 'group@asab.sa'],
            [
                'name' => 'مجموعة التاج للمطاعم',
                'logo' => '🍽️',
                'contact_name' => 'إدارة المجموعة',
                'contact_phone' => '0551234567',
                'city' => 'الرياض',
                'plan' => 'Enterprise',
                'status' => 'active',
                'max_branches' => 50,
                'max_users' => 200,
                'monthly_revenue' => 4500000,
                'start_date' => now()->subYear(),
                'next_billing' => now()->addMonth(),
                'modules' => ['مبيعات', 'مصروفات', 'مشتريات', 'مخزون', 'أصول', 'ورديات'],
                'admin_email' => 'admin@asab.sa',
            ],
        );

        $brand = AsabBrand::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'علامة الريم'],
            [
                'abbr' => 'ر', 'color' => '#7C3AED', 'owner' => 'إدارة المجموعة',
                'owner_email' => 'group@asab.sa', 'plan' => 'بلاتيني', 'sub_status' => 'active',
                'expires' => now()->addMonths(8), 'days_left' => 240,
                'modules' => ['مبيعات', 'مصروفات', 'مشتريات', 'مخزون'], 'status' => 'active',
            ],
        );

        $restaurant = AsabRestaurant::updateOrCreate(
            ['brand_id' => $brand->id, 'name' => 'مطعم الريم — العليا'],
            ['company_id' => $company->id, 'city' => 'الرياض', 'accountant_count' => 2, 'status' => 'active'],
        );

        AsabSubscription::updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'company_id' => $company->id, 'brand_id' => $brand->id, 'plan' => 'بلاتيني',
                'status' => 'active', 'expires_at' => now()->addMonths(8), 'days_left' => 240,
                'monthly_price' => 250000, 'auto_renew' => true, 'reminder_enabled' => true,
            ],
        );

        // Link any existing (legacy) branches into the demo hierarchy so the
        // admin branch list + company usage are consistent. Additive only.
        try {
            \Modules\Branch\Models\Branch::query()
                ->whereNull('asab_company_id')
                ->get()
                ->each(function ($branch) use ($company, $brand, $restaurant) {
                    $branch->forceFill([
                        'asab_company_id' => $company->id,
                        'asab_brand_id' => $brand->id,
                        'asab_restaurant_id' => $restaurant->id,
                        'status' => $branch->status ?? 'active',
                    ])->save();
                });
        } catch (\Throwable $e) {
            // Branch module unavailable — skip linking.
        }

        // One user per role. admin has no company (platform-level).
        $users = [
            ['role' => 'admin', 'name' => 'أمين النظام', 'email' => 'admin@asab.sa', 'company' => null, 'page' => 'admin-overview', 'scope' => 'all'],
            ['role' => 'head', 'name' => 'خالد العمري', 'email' => 'head@asab.sa', 'company' => $company->id, 'page' => 'head-dashboard', 'scope' => 'all'],
            ['role' => 'accountant', 'name' => 'أحمد محمد الشهري', 'email' => 'accountant@asab.sa', 'company' => $company->id, 'page' => 'acc-dashboard', 'scope' => 'restaurant'],
            ['role' => 'branch', 'name' => 'سعد الدوسري', 'email' => 'branch@asab.sa', 'company' => $company->id, 'page' => 'branch-overview', 'scope' => 'branch'],
            ['role' => 'procurement', 'name' => 'فهد القحطاني', 'email' => 'procurement@asab.sa', 'company' => $company->id, 'page' => 'proc-overview', 'scope' => 'all'],
            ['role' => 'supplier', 'name' => 'مورد الخيرات', 'email' => 'supplier@asab.sa', 'company' => $company->id, 'page' => 'sup-overview', 'scope' => 'all'],
        ];

        foreach ($users as $u) {
            $user = AsabUser::updateOrCreate(
                ['email' => $u['email']],
                [
                    'company_id' => $u['company'],
                    'name' => $u['name'],
                    'avatar' => mb_substr($u['name'], 0, 1),
                    'password' => 'password',
                    'status' => 'active',
                    'default_page' => $u['page'],
                ],
            );

            AsabUserRole::updateOrCreate(
                ['user_id' => $user->id, 'role_key' => $u['role']],
                [
                    'scope' => $u['scope'],
                    'brand_ids' => $u['scope'] === 'all' ? [] : [$brand->id],
                    'restaurant_ids' => in_array($u['scope'], ['restaurant', 'branch'], true) ? [$restaurant->id] : [],
                    'branch_ids' => [],
                    'module_keys' => ['sales', 'expenses', 'purchases', 'inventory'],
                ],
            );
        }
    }
}
