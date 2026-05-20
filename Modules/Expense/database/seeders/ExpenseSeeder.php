<?php

namespace Modules\Expense\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Category;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseAttachment;
use Modules\Expense\Models\ExpenseItem;
use Modules\Expense\Models\ExpenseLine;
use Modules\Expense\Models\ExpenseTimeline;
use Modules\Expense\Models\GroupedInvoice;
use Modules\Expense\Models\InvoiceDetail;
use Modules\Expense\Models\PreApprovalRequest;
use Modules\Expense\Models\QuickCashExpense;
use Modules\Expense\Models\QuickCashItem;
use Modules\Expense\Models\Supplier;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $branchManagers = BranchManager::all();

        if ($branchManagers->isEmpty()) {
            $this->command->warn('No branch managers found. Please seed branch managers first.');

            return;
        }

        // Fallback in case scopes don't exist
        $purchaseCategories = method_exists(Category::class, 'purchase')
            ? Category::purchase()->active()->get()
            : Category::where('type', 'purchase')->where('is_active', true)->get();

        $expenseCategories = method_exists(Category::class, 'expense')
            ? Category::expense()->active()->get()
            : Category::where('type', 'expense')->where('is_active', true)->get();

        $suppliers = Supplier::where('is_active', true)->get();

        foreach ($branchManagers as $branchManager) {
            // Create Quick Cash Expenses
            $this->createQuickCashExpenses($branchManager, 3);

            // Create Single Invoice Expenses
            $this->createSingleInvoiceExpenses($branchManager, $suppliers, $purchaseCategories, $expenseCategories, 4);

            // Create Grouped Invoice Expenses
            $this->createGroupedInvoiceExpenses($branchManager, $suppliers, $purchaseCategories, $expenseCategories, 2);

            // Create Pre-Approval Requests
            $this->createPreApprovalExpenses($branchManager, 2);
        }
    }

    private function createQuickCashExpenses($branchManager, $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $status = fake()->randomElement(['draft', 'pending', 'approved', 'rejected']);

            $expense = Expense::factory()
                ->quickCash()
                ->for($branchManager)
                ->{$status}()
                ->create();

            $quickCash = QuickCashExpense::factory()
                ->for($expense)
                ->create();

            // Create quick cash items
            $itemsCount = fake()->numberBetween(2, 5);
            $totalAmount = 0;

            for ($j = 0; $j < $itemsCount; $j++) {
                $item = QuickCashItem::factory()
                    ->for($quickCash)
                    ->create();

                $totalAmount += $item->amount;
            }

            // Update expense amounts
            $this->updateExpenseAmounts($expense, $totalAmount, $quickCash->has_vat);

            // Create timeline
            $this->createTimeline($expense, $branchManager);

            // Create attachments (no invoice_detail for quick cash)
            ExpenseAttachment::factory()->count(rand(1, 3))->create([
                'expense_id' => $expense->id,
                'invoice_detail_id' => null,
            ]);
        }
    }

    private function createSingleInvoiceExpenses($branchManager, $suppliers, $purchaseCategories, $expenseCategories, $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $status = fake()->randomElement(['draft', 'pending', 'approved', 'rejected']);
            $supplier = $suppliers->random();

            $expense = Expense::factory()
                ->singleInvoice()
                ->for($branchManager)
                ->{$status}()
                ->create();

            // create invoice detail linked to expense and supplier
            $invoiceDetail = InvoiceDetail::factory()
                ->for($expense)
                ->create([
                    'supplier_id' => $supplier->id,
                    // other defaults can be set in factory
                ]);

            // Create expense items (purchases)
            $itemsCount = fake()->numberBetween(2, 6);
            $totalAmount = 0;

            for ($j = 0; $j < $itemsCount; $j++) {
                $category = $purchaseCategories->random();
                $item = ExpenseItem::factory()->create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoiceDetail->id,
                    'category_id' => $category->id,
                ]);

                $totalAmount += $item->total_amount;
            }

            // Create expense lines (other expenses)
            $linesCount = fake()->numberBetween(1, 3);

            for ($j = 0; $j < $linesCount; $j++) {
                $category = $expenseCategories->random();
                $line = ExpenseLine::factory()->create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoiceDetail->id,
                    'category_id' => $category->id,
                ]);

                $totalAmount += $line->price;
            }

            // Update expense amounts
            $this->updateExpenseAmounts($expense, $totalAmount, $invoiceDetail->is_tax_invoice);

            // Create timeline
            $this->createTimeline($expense, $branchManager);

            // Attachments for the invoice
            ExpenseAttachment::factory()->count(rand(1, 3))->create([
                'expense_id' => $expense->id,
                'invoice_detail_id' => $invoiceDetail->id,
            ]);
        }
    }

    private function createGroupedInvoiceExpenses($branchManager, $suppliers, $purchaseCategories, $expenseCategories, $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $status = fake()->randomElement(['draft', 'pending', 'approved', 'rejected']);

            $expense = Expense::factory()
                ->groupedInvoice()
                ->for($branchManager)
                ->{$status}()
                ->create();

            $groupedInvoice = GroupedInvoice::factory()
                ->for($expense)
                ->create();

            // Create multiple invoice details
            $invoicesCount = fake()->numberBetween(2, 4);
            $totalAmount = 0;

            for ($j = 0; $j < $invoicesCount; $j++) {
                $supplier = $suppliers->random();

                $invoiceDetail = InvoiceDetail::factory()->create([
                    'expense_id' => $expense->id,
                    'grouped_invoice_id' => $groupedInvoice->id,
                    'supplier_id' => $supplier->id,
                    'payment_type' => null,
                    'paid_amount' => 0,
                    'due_date' => null,
                ]);

                // Create items for each invoice
                $itemsCount = fake()->numberBetween(2, 4);

                for ($k = 0; $k < $itemsCount; $k++) {
                    $category = $purchaseCategories->random();
                    $item = ExpenseItem::factory()->create([
                        'expense_id' => $expense->id,
                        'invoice_detail_id' => $invoiceDetail->id,
                        'category_id' => $category->id,
                    ]);

                    $totalAmount += $item->total_amount;
                }

                // Create expense lines
                $linesCount = fake()->numberBetween(1, 2);

                for ($k = 0; $k < $linesCount; $k++) {
                    $category = $expenseCategories->random();
                    $line = ExpenseLine::factory()->create([
                        'expense_id' => $expense->id,
                        'invoice_detail_id' => $invoiceDetail->id,
                        'category_id' => $category->id,
                    ]);

                    $totalAmount += $line->price;
                }

                // Create attachments for each invoice
                ExpenseAttachment::factory()->count(rand(1, 2))->create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoiceDetail->id,
                ]);
            }

            // Update expense amounts (assume tax invoice)
            $this->updateExpenseAmounts($expense, $totalAmount, true);

            // Create timeline
            $this->createTimeline($expense, $branchManager);
        }
    }

    private function createPreApprovalExpenses($branchManager, $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $status = fake()->randomElement(['draft', 'pending', 'approved', 'rejected']);

            $expense = Expense::factory()
                ->preApproval()
                ->for($branchManager)
                ->{$status}()
                ->create();

            $preApproval = PreApprovalRequest::factory()
                ->for($expense)
                ->create();

            // Update expense amounts based on estimated amount
            $this->updateExpenseAmounts($expense, $preApproval->estimated_amount, false);

            // Create timeline
            $this->createTimeline($expense, $branchManager);

            // Create attachments (supporting documents)
            ExpenseAttachment::factory()->count(rand(1, 3))->create([
                'expense_id' => $expense->id,
                'invoice_detail_id' => null,
            ]);
        }
    }

    private function updateExpenseAmounts($expense, $totalAmount, $hasVat): void
    {
        if ($hasVat) {
            // totalAmount is gross (includes VAT) -> net = total / 1.15
            $netAmount = round($totalAmount / 1.15, 2);
            $vatAmount = round($totalAmount - $netAmount, 2);
        } else {
            $netAmount = round($totalAmount, 2);
            $vatAmount = 0;
        }

        $expense->update([
            'total_amount' => $totalAmount,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
        ]);
    }

    private function createTimeline($expense, $branchManager): void
    {
        // Created timeline
        ExpenseTimeline::factory()->created()->create([
            'expense_id' => $expense->id,
            'performed_by' => $branchManager->id,
            'performed_by_type' => 'branch_manager',
            'status' => $expense->status,
        ]);

        // Add more timeline based on status
        if ($expense->status === 'pending') {
            ExpenseTimeline::factory()->submitted()->create([
                'expense_id' => $expense->id,
                'performed_by' => $branchManager->id,
                'performed_by_type' => 'branch_manager',
                'status' => 'pending',
            ]);
        }

        if ($expense->status === 'approved') {
            ExpenseTimeline::factory()->submitted()->create([
                'expense_id' => $expense->id,
                'performed_by' => $branchManager->id,
                'performed_by_type' => 'branch_manager',
                'status' => 'pending',
            ]);

            ExpenseTimeline::factory()->approved()->create([
                'expense_id' => $expense->id,
                'performed_by' => $expense->approved_by ?: $branchManager->id,
                'performed_by_type' => 'brand_owner',
                'status' => 'approved',
            ]);
        }

        if ($expense->status === 'rejected') {
            ExpenseTimeline::factory()->submitted()->create([
                'expense_id' => $expense->id,
                'performed_by' => $branchManager->id,
                'performed_by_type' => 'branch_manager',
                'status' => 'pending',
            ]);

            ExpenseTimeline::factory()->rejected()->create([
                'expense_id' => $expense->id,
                'performed_by' => $expense->rejected_by ?: $branchManager->id,
                'performed_by_type' => 'brand_owner',
                'status' => 'rejected',
            ]);
        }
    }
}
