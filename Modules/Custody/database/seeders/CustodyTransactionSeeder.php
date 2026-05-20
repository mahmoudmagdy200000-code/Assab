<?php

namespace Modules\Custody\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Models\Expense;

class CustodyTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branchManagers = BranchManager::all();

        if ($branchManagers->isEmpty()) {
            $this->command->warn('⚠️  No branch managers found. Please seed branch managers first.');

            return;
        }

        $this->command->info('🌱 Seeding Custody Transactions...');

        foreach ($branchManagers as $branchManager) {
            $this->createTransactionsForManager($branchManager);
        }

        $this->command->info('✅ Custody Transactions seeded successfully!');
    }

    private function createTransactionsForManager(BranchManager $branchManager): void
    {
        $transactionTypes = [
            'Cash Transfer',
            'Cash Handover',
            'Bank Transfer',
            'Expenses Deduction',
        ];

        // Get approved custody requests for this manager
        $approvedRequests = CustodyRequest::where('branch_manager_id', $branchManager->id)
            ->where('status', 'Approved')
            ->get();

        // Get expenses for this manager
        $expenses = Expense::where('branch_manager_id', $branchManager->id)
            ->where('payment_method', 'custody')
            ->get();

        // Create 15-25 transactions per manager
        $transactionCount = rand(15, 25);

        for ($i = 0; $i < $transactionCount; $i++) {
            $transactionType = $transactionTypes[array_rand($transactionTypes)];
            $isCashIn = in_array($transactionType, ['Cash Transfer', 'Cash Handover', 'Bank Transfer']);

            $transactionDate = Carbon::now()->subDays(rand(0, 30))
                ->subHours(rand(0, 23))
                ->subMinutes(rand(0, 59));

            $data = [
                'branch_manager_id' => $branchManager->id,
                'branch_id' => $branchManager->branch_id,
                'type' => $transactionType,
                'is_cash_in' => $isCashIn,
                'transaction_date' => $transactionDate,
            ];

            // Set amount and related entities based on type
            switch ($transactionType) {
                case 'Cash Transfer':
                    $data['amount'] = round(rand(500, 5000) + (rand(0, 99) / 100), 2);
                    break;

                case 'Cash Handover':
                case 'Bank Transfer':
                    // Link to approved request if available
                    if ($approvedRequests->isNotEmpty() && rand(0, 1)) {
                        $request = $approvedRequests->random();
                        $data['amount'] = $request->requested_amount;
                        $data['related_custody_request_id'] = $request->id;
                        $data['handover_method'] = $request->preferred_receipt_method;
                        $data['handover_date'] = $transactionDate;
                    } else {
                        $data['amount'] = round(rand(1000, 8000) + (rand(0, 99) / 100), 2);
                        $data['handover_method'] = rand(0, 1) ? 'Cash Handover' : 'Bank Transfer';
                        $data['handover_date'] = $transactionDate;
                    }
                    break;

                case 'Expenses Deduction':
                    // Link to expense if available
                    if ($expenses->isNotEmpty() && rand(0, 1)) {
                        $expense = $expenses->random();
                        $data['amount'] = $expense->total_amount;
                        $data['related_expense_id'] = $expense->id;
                    } else {
                        $data['amount'] = round(rand(100, 2000) + (rand(0, 99) / 100), 2);
                    }
                    break;
            }

            CustodyTransaction::create($data);
        }

        $this->command->info("   ✓ Created {$transactionCount} transactions for {$branchManager->name}");
    }
}
