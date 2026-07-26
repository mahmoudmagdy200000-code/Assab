<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Admin\Database\Seeders\AsabBrandPackageSeeder;
use Modules\Admin\Database\Seeders\AsabRolePermissionSeeder;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\SupplierItem as AsabSupplierItem;
use Modules\Admin\Services\BranchHierarchyLinker;
use Modules\Admin\Services\CashierProvisioningService;
use Modules\Admin\Services\ExpenseTaxonomyBridgeService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Admin\Services\ShiftScheduleBridgeService;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Database\Seeders\CategorySeeder;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\InvoiceDetail;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift as MobileShift;
use Modules\Shift\Models\ShiftSalesBreakdown;

/**
 * ONE realistic, fully-LINKED two-worlds demo (dashboard + mobile).
 *
 * `php artisan migrate:fresh --seed` runs this. It builds a Saudi restaurant
 * group across both worlds and wires the links by driving the REAL services /
 * events (provisioners, catalog + expense + shift bridges, BranchHierarchyLinker)
 * so the seeded data behaves exactly like runtime — a mobile shift close or
 * expense actually lands in the responsible scoped accountant's inbox, and every
 * dashboard account logs into the mobile app with the same password.
 *
 * Every login password is «password».
 */
class FullDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private AsabCompany $company;

    /** @var AsabBrand[] */
    private array $brands = [];

    /** @var AsabRestaurant[] */
    private array $restaurants = [];

    /** @var Branch[] */
    private array $branches = [];

    /** @var AsabUser[] keyed by role label */
    private array $staff = [];

    /** @var AsabSupplier[] */
    private array $suppliers = [];

    /** @var Aggregator[] */
    private array $aggregators = [];

    public function run(): void
    {
        // Foundational reference data (roles/permissions, packages, category tree).
        $this->call([AsabRolePermissionSeeder::class, AsabBrandPackageSeeder::class, CategorySeeder::class]);

        $this->seedAggregators();
        $this->seedTenant();
        $this->seedDashboardUsers();
        $this->seedSuppliers();
        $this->seedCatalog();
        $this->seedShiftSchedule();
        $this->seedPeopleAndOperations();

        $this->command?->info('✅ FullDemoSeeder done. Logins (password: "'.self::PASSWORD.'"):');
        foreach ($this->staff as $label => $user) {
            $this->command?->line("   • {$label}: {$user->email}");
        }
        $this->command?->line('   • branch managers: manager1@nakhat.sa … (mobile app)');
    }

    // ── delivery aggregators (Jahez/Keeta/Ninja/HungerStation) ────────────────
    private function seedAggregators(): void
    {
        foreach ([['جاهز', 'JAHEZ'], ['كيتا', 'KEETA'], ['نينجا', 'NINJA'], ['هنقرستيشن', 'HUNGER']] as [$name, $code]) {
            $this->aggregators[] = Aggregator::factory()->create(['name' => $name, 'code' => $code]);
        }
    }

    // ── company → brands → restaurants → tagged branches ──────────────────────
    private function seedTenant(): void
    {
        $this->company = AsabCompany::updateOrCreate(
            ['contact_email' => 'group@nakhat.sa'],
            [
                'name' => 'مجموعة نكهات نجد القابضة', 'logo' => '🍽️', 'contact_name' => 'إدارة المجموعة',
                'contact_phone' => '0551000000', 'city' => 'الرياض', 'plan' => 'Enterprise', 'status' => 'active',
                'max_branches' => 50, 'max_users' => 200, 'monthly_revenue' => 6800000,
                'start_date' => now()->subYear(), 'next_billing' => now()->addMonth(),
                'modules' => ['مبيعات', 'مصروفات', 'مشتريات', 'مخزون', 'أصول', 'ورديات'],
                'admin_email' => 'admin@nakhat.sa',
            ],
        );

        $blueprint = [
            'برجر بيت' => ['abbr' => 'ب', 'color' => '#DC2626', 'restaurants' => [
                'برجر بيت — العليا' => ['فرع العليا', 'فرع التحلية'],
                'برجر بيت — النخيل' => ['فرع النخيل مول', 'فرع الملقا'],
                'برجر بيت — قرطبة' => ['فرع قرطبة'],
            ]],
            'شاورما الأصيل' => ['abbr' => 'ش', 'color' => '#16A34A', 'restaurants' => [
                'شاورما الأصيل — الروضة' => ['فرع الروضة', 'فرع النسيم'],
                'شاورما الأصيل — العزيزية' => ['فرع العزيزية', 'فرع الشفا'],
            ]],
        ];

        foreach ($blueprint as $brandName => $meta) {
            $brand = AsabBrand::create([
                'company_id' => $this->company->id, 'name' => $brandName, 'abbr' => $meta['abbr'], 'color' => $meta['color'],
                'owner' => 'إدارة المجموعة', 'owner_email' => 'group@nakhat.sa', 'plan' => 'بلاتيني', 'sub_status' => 'active',
                'expires' => now()->addMonths(9), 'days_left' => 270,
                'modules' => ['مبيعات', 'مصروفات', 'مشتريات', 'مخزون', 'أصول', 'ورديات'], 'status' => 'active',
            ]);
            $this->brands[$brandName] = $brand;

            foreach ($meta['restaurants'] as $restaurantName => $branchNames) {
                $restaurant = AsabRestaurant::create([
                    'brand_id' => $brand->id, 'company_id' => $this->company->id,
                    'name' => $restaurantName, 'city' => 'الرياض', 'accountant_count' => 1, 'status' => 'active',
                ]);
                $this->restaurants[] = $restaurant;

                AsabSubscription::create([
                    'company_id' => $this->company->id, 'brand_id' => $brand->id, 'restaurant_id' => $restaurant->id,
                    'plan' => 'بلاتيني', 'status' => 'active', 'expires_at' => now()->addMonths(9),
                    'days_left' => 270, 'monthly_price' => 250000, 'auto_renew' => true, 'reminder_enabled' => true,
                ]);

                foreach ($branchNames as $branchName) {
                    // The shared branches row carries the ASAB hierarchy tags —
                    // this is what routes a mobile submission to the scoped accountant.
                    $this->branches[] = Branch::create([
                        'name' => $brandName.' — '.$branchName, 'location' => 'الرياض — '.$branchName,
                        'city' => 'الرياض', 'status' => 'active', 'is_active' => true,
                        'asab_company_id' => $this->company->id, 'asab_brand_id' => $brand->id, 'asab_restaurant_id' => $restaurant->id,
                    ]);
                }
            }
        }
    }

    // ── dashboard users: admin, head, per-brand accountants, procurement, owner ─
    private function seedDashboardUsers(): void
    {
        $head = $this->makeUser('رئيس الحسابات', 'خالد العمري', 'head@nakhat.sa', 'head', $this->company->id, 'all');
        $this->staff['head'] = $head;

        // Platform admin — no company.
        $this->staff['admin'] = $this->makeUser('أمين النظام', 'أمين النظام', 'admin@nakhat.sa', 'admin', null, 'all');

        // One accountant per brand, each reporting to the head — this is the link
        // the user asked about: a brand-scoped accountant sees ONLY their brand's
        // branches' operations.
        $labels = ['برجر بيت' => 'accountant.burger@nakhat.sa', 'شاورما الأصيل' => 'accountant.shawarma@nakhat.sa'];
        foreach ($labels as $brandName => $email) {
            $brand = $this->brands[$brandName];
            $acc = $this->makeUser('محاسب '.$brandName, 'محاسب '.$brandName, $email, 'accountant', $this->company->id, 'brand', [$brand->id]);
            $acc->forceFill(['reports_to_id' => $head->id])->save();
            $this->staff['accountant:'.$brandName] = $acc;
        }

        $this->staff['procurement'] = $this->makeUser('مدير المشتريات', 'فهد القحطاني', 'procurement@nakhat.sa', 'procurement', $this->company->id, 'all');
        $this->staff['brand-owner'] = $this->makeUser('مالك العلامة', 'ناصر التميمي', 'owner@nakhat.sa', 'brand-owner', $this->company->id, 'all');
    }

    /** @param  string[]  $brandIds */
    private function makeUser(string $label, string $name, string $email, string $role, ?string $companyId, string $scope, array $brandIds = []): AsabUser
    {
        $user = AsabUser::updateOrCreate(['email' => $email], [
            'company_id' => $companyId, 'name' => $name, 'avatar' => mb_substr($name, 0, 1),
            'password' => self::PASSWORD, 'status' => 'active',
        ]);

        AsabUserRole::updateOrCreate(['user_id' => $user->id, 'role_key' => $role], [
            'scope' => $scope,
            'brand_ids' => $brandIds,
            'restaurant_ids' => [],
            'branch_ids' => [],
            'module_keys' => ['sales', 'expenses', 'purchases', 'inventory', 'shifts', 'assets'],
        ]);

        return $user;
    }

    // ── suppliers (internal + external), provisioned into the mobile world ─────
    private function seedSuppliers(): void
    {
        $bridge = app(ProcurementCatalogBridgeService::class);
        $rows = [
            ['مؤسسة اللحوم الطازجة', 'supplier.meat@nakhat.sa', '0553420001', false, 'لحوم'],
            ['مخبز نجد للمخبوزات', 'supplier.bakery@nakhat.sa', '0553420002', false, 'مخبوزات'],
            ['خضار السوق المركزي', 'supplier.veg@nakhat.sa', '0553420003', true, 'خضار'],
            ['مورد المشروبات الوطنية', 'supplier.drinks@nakhat.sa', '0553420004', true, 'مشروبات'],
        ];
        foreach ($rows as [$name, $email, $phone, $external, $category]) {
            $sup = AsabSupplier::create([
                'company_id' => $this->company->id, 'name' => $name, 'category' => $category,
                'contact_name' => $name, 'contact_phone' => $phone, 'contact_email' => $email,
                'payment_terms' => 'صافي 30 يوم', 'status' => 'active', 'is_external' => $external,
            ]);
            $bridge->provisionSupplier($sup);   // → legacy suppliers row + identity link
            $this->suppliers[] = $sup;
        }
    }

    // ── catalog: procurement items (→ mobile items/branch_item/supplier_items)
    //    and the sales/raw taxonomy (→ mobile expense categories) ──────────────
    private function seedCatalog(): void
    {
        $bridge = app(ProcurementCatalogBridgeService::class);
        $taxonomy = app(ExpenseTaxonomyBridgeService::class);

        $items = [
            ['لحم بقري مفروم', 'كجم', 'لحوم', 3500, 0],
            ['صدور دجاج', 'كجم', 'لحوم', 2800, 0],
            ['خبز برجر', 'باكيت', 'مخبوزات', 1200, 1],
            ['خبز شاورما', 'باكيت', 'مخبوزات', 900, 1],
            ['طماطم طازجة', 'كجم', 'خضار', 600, 2],
            ['بطاطس مجمّدة', 'كيس', 'خضار', 1800, 2],
            ['جبنة شيدر', 'كجم', 'ألبان', 4200, 0],
            ['مشروبات غازية', 'كرتون', 'مشروبات', 3000, 3],
            ['زيت قلي', 'صفيحة', 'زيوت', 5500, 2],
            ['صلصات وتتبيلات', 'كرتون', 'بقالة', 2400, 2],
        ];
        foreach ($items as [$name, $unit, $category, $priceHalalas, $supIdx]) {
            $item = AsabSupplierItem::create([
                'company_id' => $this->company->id, 'supplier_id' => $this->suppliers[$supIdx]->id,
                'name' => $name, 'unit' => $unit, 'category' => $category, 'price' => $priceHalalas, 'status' => 'active',
            ]);
            $bridge->syncItem($item);   // → mobile items + branch_item (all branches) + priced supplier_items
        }

        // Sales-items + raw-materials catalog per brand, projecting each «التصنيف»
        // into the mobile expense taxonomy (categories) so the app pickers fill.
        $sales = ['وجبة برجر كلاسيك', 'وجبة دجاج مقرمش', 'شاورما عربي', 'شاورما دجاج', 'بطاطس مقلية', 'مشروب غازي'];
        $raw = ['لحوم', 'دواجن', 'خضار', 'مخبوزات', 'مشروبات', 'مواد تغليف'];
        foreach ($this->brands as $brand) {
            foreach ($sales as $s) {
                InventoryCatalogItem::create([
                    'brand_id' => $brand->id, 'name' => $s, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
                    'category' => 'الوجبات', 'unit' => 'وجبة', 'unit_price' => rand(1500, 4500),
                ]);
                $taxonomy->syncCategoryFor('sales-items', 'الوجبات');
            }
            foreach ($raw as $r) {
                InventoryCatalogItem::create([
                    'brand_id' => $brand->id, 'name' => $r, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
                    'category' => $r, 'unit' => 'كجم', 'unit_price' => rand(500, 5000),
                ]);
                $taxonomy->syncCategoryFor('raw-materials', $r);
            }
        }
    }

    // ── shift config per brand → seed the mobile shifts template per branch ────
    private function seedShiftSchedule(): void
    {
        $regen = app(ShiftScheduleBridgeService::class);
        foreach ($this->brands as $brand) {
            BrandShiftConfig::updateOrCreate(
                ['brand_id' => $brand->id],
                ['num_shifts' => 2, 'duration_hours' => 8, 'first_shift_start' => '08:00'],
            );
            $regen->regenerateForBrand($brand->id);   // → mobile `shifts` rows for every branch of the brand
        }
    }

    // ── per branch: manager + cashiers (linked), then operational history ──────
    private function seedPeopleAndOperations(): void
    {
        $cashierProvisioner = app(CashierProvisioningService::class);
        $linker = app(BranchHierarchyLinker::class);
        $empNo = 1000;

        foreach ($this->branches as $i => $branch) {
            $linker->ensure($branch); // belt-and-suspenders (branches are already tagged)

            // Mobile branch manager (the expense bridge reads its branch_id).
            $manager = BranchManager::factory()->create([
                'branch_id' => $branch->id, 'name' => 'مدير '.$branch->name,
                'email' => 'manager'.($i + 1).'@nakhat.sa', 'phone' => '05540'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'password' => self::PASSWORD, 'is_first_login' => false,
            ]);

            // Two cashiers per branch: an ASAB employee mirrored into a mobile
            // login, linked by legacy_cashier_id so their shift closes bridge to
            // the dashboard and reach the brand's accountant.
            $cashierIds = [];
            foreach ([1, 2] as $n) {
                $empNo++;
                $employee = Employee::create([
                    'company_id' => $this->company->id, 'branch_id' => $branch->id,
                    'emp_number' => 'EMP-'.$empNo, 'name' => 'كاشير '.$n.' — '.$branch->name,
                    'role' => 'cashier', 'phone' => '05541'.str_pad((string) $empNo, 5, '0', STR_PAD_LEFT),
                    'monthly_salary' => 450000, 'status' => 'active',
                ]);
                $email = 'cashier'.$empNo.'@nakhat.sa';
                $result = $cashierProvisioner->provision($branch->id, $this->company->id, $employee->name, $email, $employee->phone, $employee->id);
                if (! empty($result['cashierId'])) {
                    $employee->forceFill(['legacy_cashier_id' => $result['cashierId']])->save();
                    $cashierIds[] = $result['cashierId'];
                }
            }

            // Operational history on every other branch (realistic: not every
            // branch has closed today) — the stride spans BOTH brands so each
            // brand-scoped accountant gets a populated inbox. Only where a
            // cashier actually linked across the two worlds.
            if ($i % 2 === 0 && $cashierIds !== []) {
                $this->seedShiftCloses($branch, $cashierIds);
                $this->seedExpenses($branch, $manager);
                $this->seedPurchaseOrder($branch, $manager);
            }

            // A branch-level asset on every branch; a couple of brand-level
            // «awaiting assignment» assets are added once (below).
            $this->seedBranchAsset($branch, $i);
        }

        $this->seedBrandLevelAssets();
    }

    /** Complete a mobile CashierShift → fires ShiftEndedEvent → SHF op in the accountant inbox. */
    private function seedShiftCloses(Branch $branch, array $cashierIds): void
    {
        $shift = MobileShift::where('branch_id', $branch->id)->orderBy('start_time')->first();
        if ($shift === null) {
            return;
        }

        foreach ($cashierIds as $cashierId) {
            $sales = rand(600, 1800) * 10;          // SAR
            $card = (int) round($sales * 0.35);
            $apps = (int) round($sales * 0.25);
            $cash = $sales - $card - $apps;

            $cs = CashierShift::create([
                'cashier_id' => $cashierId, 'shift_id' => $shift->id, 'shift_date' => today(),
                'status' => ShiftStatus::IN_PROGRESS, 'opening_balance' => 100.00,
            ]);
            // Per-aggregator breakdown (bridge sums these into the dashboard sheet).
            $split = [(int) round($apps * 0.5), (int) round($apps * 0.3), $apps - (int) round($apps * 0.5) - (int) round($apps * 0.3)];
            foreach ([$this->aggregators[0], $this->aggregators[1], $this->aggregators[2]] as $k => $agg) {
                if ($split[$k] > 0) {
                    ShiftSalesBreakdown::create(['cashier_shift_id' => $cs->id, 'aggregator_id' => $agg->id, 'amount' => $split[$k]]);
                }
            }
            // Completing the shift is what the CashierShiftObserver bridges.
            $cs->update([
                'status' => ShiftStatus::COMPLETED, 'total_sales' => $sales, 'net_sales' => $sales,
                'cash_collected' => $cash, 'card_payments' => $card, 'actual_end_time' => now(),
            ]);
        }
    }

    /** A grouped-invoice mobile expense → fires ExpenseSubmittedEvent → EXP op in the accountant inbox. */
    private function seedExpenses(Branch $branch, BranchManager $manager): void
    {
        foreach (['grouped_invoice', 'single_invoice'] as $type) {
            $expense = Expense::factory()->create([
                'branch_manager_id' => $manager->id, 'expense_type' => $type, 'status' => 'pending',
                'total_amount' => 0, 'net_amount' => 0, 'vat_amount' => 0,
            ]);
            $total = 0;
            $net = 0;
            $vat = 0;
            foreach (range(1, $type === 'grouped_invoice' ? 2 : 1) as $n) {
                $netAmt = rand(200, 900);
                $vatAmt = round($netAmt * 0.15, 2);
                InvoiceDetail::factory()->create([
                    'expense_id' => $expense->id, 'invoice_number' => 'INV-'.strtoupper(Str::random(5)),
                    'tax_supplier_name' => $this->suppliers[array_rand($this->suppliers)]->name, 'issue_date' => now()->subDays($n),
                    'tax_net_amount' => $netAmt, 'tax_vat_amount' => $vatAmt, 'tax_total_amount' => $netAmt + $vatAmt,
                ]);
                $total += $netAmt + $vatAmt;
                $net += $netAmt;
                $vat += $vatAmt;
            }
            $expense->update(['total_amount' => $total, 'net_amount' => $net, 'vat_amount' => $vat, 'submitted_at' => now()]);
            event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->fresh()));
        }
    }

    private function seedPurchaseOrder(Branch $branch, BranchManager $manager): void
    {
        PurchaseOrder::factory()->count(2)->create(['branch_id' => $branch->id]);
    }

    private function seedBranchAsset(Branch $branch, int $i): void
    {
        Asset::create([
            'company_id' => $this->company->id, 'public_id' => 'FA-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'ثلاجة عرض — '.$branch->name, 'category' => 'kitchen', 'branch_id' => $branch->id,
            'cost' => 850000, 'book_value' => 780000, 'useful_life_months' => 60, 'status' => 'active',
            'case_type' => 'acc_register', 'custodian' => 'إدارة الفرع', 'purchased_at' => now()->subMonths(8),
        ]);
    }

    /** Brand-level uploads land branch_id null → the «بانتظار التخصيص» pool. */
    private function seedBrandLevelAssets(): void
    {
        foreach (['فرن غاز صناعي', 'مكيف مركزي'] as $k => $name) {
            Asset::create([
                'company_id' => $this->company->id, 'public_id' => 'FA-9'.str_pad((string) ($k + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name, 'category' => 'kitchen', 'branch_id' => null,
                'cost' => 1500000, 'book_value' => 1500000, 'useful_life_months' => 120, 'status' => 'pending_branch',
                'case_type' => 'brand_upload', 'purchased_at' => now()->subMonth(),
            ]);
        }
    }
}
