<?php

namespace Modules\Custody\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\BranchManagers\Models\BranchManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FixPersonalLedgerBalanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * This seeder adds cash-in transactions to make all branch managers' balances positive.
     */
    public function run(): void
    {
        $branchManagers = BranchManager::all();

        if ($branchManagers->isEmpty()) {
            $this->command->warn('⚠️  No branch managers found. Please seed branch managers first.');
            return;
        }

        $this->command->info('🔧 Fixing Personal Ledger Balances...');

        foreach ($branchManagers as $branchManager) {
            $this->fixBalanceForManager($branchManager);
        }

        $this->command->info('✅ Personal Ledger Balances fixed successfully!');
    }

    /**
     * Calculate current balance and add cash-in transactions if balance is negative
     */
    private function fixBalanceForManager(BranchManager $branchManager): void
    {
        // Get all transactions for this manager
        $transactions = PersonalLedgerTransaction::where('branch_manager_id', $branchManager->id)->get();

        $totalCashIn = $transactions->where('is_cash_in', true)->sum('amount');
        $totalCashOut = $transactions->where('is_cash_in', false)->sum('amount');
        $currentBalance = $totalCashIn - $totalCashOut;

        $this->command->info("   📊 {$branchManager->name}: Current Balance = " . number_format($currentBalance, 2));

        // If balance is negative or zero, add cash-in transactions to make it positive
        if ($currentBalance <= 0) {
            // Calculate how much we need to add (make balance at least 10000 positive)
            $amountNeeded = abs($currentBalance) + 10000;
            
            // Split into 2-3 transactions for realism
            $numberOfTransactions = rand(2, 3);
            $amountPerTransaction = round($amountNeeded / $numberOfTransactions, 2);
            
            // Add small random variation to each transaction
            $variation = $amountPerTransaction * 0.1; // 10% variation

            $cashierNames = ['Ahmed Ali', 'Mohamed Hassan', 'Sara Ibrahim', 'Fatima Mohamed', 'Omar Khaled'];

            for ($i = 0; $i < $numberOfTransactions; $i++) {
                // Add variation to make it more realistic
                $amount = $amountPerTransaction + (rand(-1000, 1000) / 100);
                $amount = max(1000, $amount); // Minimum 1000

                $transactionDate = Carbon::now()
                    ->subDays(rand(0, 5))
                    ->subHours(rand(0, 23))
                    ->subMinutes(rand(0, 59));

                PersonalLedgerTransaction::create([
                    'branch_manager_id' => $branchManager->id,
                    'transaction_type' => 'Total Sales',
                    'amount' => round($amount, 2),
                    'is_cash_in' => true,
                    'cashier_name' => $cashierNames[array_rand($cashierNames)],
                    'transaction_date' => $transactionDate,
                ]);
            }

            // Recalculate balance
            $newTransactions = PersonalLedgerTransaction::where('branch_manager_id', $branchManager->id)->get();
            $newTotalCashIn = $newTransactions->where('is_cash_in', true)->sum('amount');
            $newTotalCashOut = $newTransactions->where('is_cash_in', false)->sum('amount');
            $newBalance = $newTotalCashIn - $newTotalCashOut;

            $this->command->info("   ✅ {$branchManager->name}: Added {$numberOfTransactions} transactions, New Balance = " . number_format($newBalance, 2));
        } else {
            $this->command->info("   ✓ {$branchManager->name}: Balance is already positive, no changes needed");
        }
    }
}
