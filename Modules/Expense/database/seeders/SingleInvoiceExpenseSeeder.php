<?php

namespace Modules\Expense\Database\Seeders;


use Illuminate\Database\Seeder;
use Modules\Expense\Services\SingleInvoiceExpenseService;
use Faker\Factory as Faker;
use Modules\Expense\Models\Supplier;
use Modules\Expense\Models\Category;
use Illuminate\Support\Facades\Auth;
use Modules\BranchManagers\Models\BranchManager;

class SingleInvoiceExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $service = app(SingleInvoiceExpenseService::class);

        // Get any branch manager user
        $branchManager = BranchManager::first();
        if (!$branchManager) {
            $this->command->warn('⚠️ لا يوجد مستخدمين في جدول users. أنشئ واحد أولاً.');
            return;
        }

        // Simulate login for auth()->id() usage inside service
        Auth::login($branchManager);

        // Get required IDs
        $suppliers = Supplier::pluck('id')->toArray();
        $categories = Category::pluck('id')->toArray();

        if (empty($suppliers) || empty($categories)) {
            $this->command->warn('⚠️ يجب أن يوجد Suppliers و Categories أولاً.');
            return;
        }

        $this->command->info('🚀 جاري إنشاء 2000 Single Invoice Expense ...');

        for ($i = 0; $i < 2000; $i++) {
            $supplierId = $faker->randomElement($suppliers);
            $isTaxInvoice = $faker->boolean(40); // 40% منها فواتير ضريبية

            // Generate items
            $items = [];
            $numItems = rand(1, 4);
            for ($j = 0; $j < $numItems; $j++) {
                $items[] = [
                    'category_id' => $faker->randomElement($categories),
                    'name' => ucfirst($faker->words(2, true)),
                    'quantity' => $faker->randomFloat(2, 1, 10),
                    'unit_price' => $faker->randomFloat(2, 50, 1500),
                ];
            }

            // Generate expense lines
            $expenses = [];
            if ($faker->boolean(30)) { // 30% فيها مصروفات إضافية
                $numExpenses = rand(1, 3);
                for ($k = 0; $k < $numExpenses; $k++) {
                    $expenses[] = [
                        'category_id' => $faker->randomElement($categories),
                        'name' => ucfirst($faker->word()),
                        'price' => $faker->randomFloat(2, 100, 500),
                    ];
                }
            }

            // إعداد بيانات الفاتورة
            $data = [
                'supplier_id' => $supplierId,
                'invoice_number' => strtoupper($faker->bothify('INV-####-??')),
                'total_amount' => 0, // سيتم حسابها داخل الخدمة
                'issue_date' => $faker->date(),
                'is_tax_invoice' => $isTaxInvoice,
                'tax_id' => $isTaxInvoice ? $faker->numerify('TAX###') : null,
                'tax_invoice_details' => $isTaxInvoice ? [
                    'supplier_name' => $faker->company,
                    'net_amount' => $faker->randomFloat(2, 500, 4000),
                    'vat_amount' => $faker->randomFloat(2, 50, 800),
                    'total_amount' => $faker->randomFloat(2, 550, 4800),
                ] : null,
                'items' => $items,
                'expenses' => $expenses,
                'payment_type' => $faker->randomElement(['full', 'partial', 'deferred']),
                'payment_method' => $faker->randomElement(['cash', 'supplier', 'custody']),
                'paid_amount' => $faker->randomFloat(2, 100, 3000),
                'due_date' => $faker->dateTimeBetween('now', '+2 months')->format('Y-m-d'),
                'is_draft' => $faker->boolean(20), // 20% فقط تكون draft
            ];

            try {
                $service->createSingleInvoice($data);
            } catch (\Exception $e) {
                $this->command->warn("⚠️ خطأ عند إنشاء الفاتورة رقم {$i}: {$e->getMessage()}");
            }
        }

        $this->command->info('✅ تم إنشاء 2000 Single Invoice Expense بنجاح!');
    }
}
