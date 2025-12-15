<?php

namespace Modules\Custody\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\BranchManagers\Models\BranchManager;
use Carbon\Carbon;

class PersonalLedgerTransactionSeeder extends Seeder
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

        $this->command->info('🌱 Seeding Personal Ledger Transactions...');

        foreach ($branchManagers as $branchManager) {
            // Create transactions for the last 30 days
            $this->createTransactionsForManager($branchManager);
        }

        $this->command->info('✅ Personal Ledger Transactions seeded successfully!');
    }

    private function createTransactionsForManager(BranchManager $branchManager): void
    {
        $transactionTypes = [
            'Total Sales',
            'Handover to Brand Owner',
            'Transfer to Custody'
        ];

        $cashierNames = ['Ahmed Ali', 'Mohamed Hassan', 'Sara Ibrahim', 'Fatima Mohamed', 'Omar Khaled'];
        $brandOwnerNames = ['Brand Owner 1', 'Brand Owner 2', 'Sarah Johnson', 'Mohamed Ibrahim'];

        // Create 20-30 transactions per manager
        $transactionCount = rand(20, 30);

        for ($i = 0; $i < $transactionCount; $i++) {
            $transactionType = $transactionTypes[array_rand($transactionTypes)];
            $isCashIn = $transactionType === 'Total Sales';
            $amount = $isCashIn
                ? rand(500, 5000) + (rand(0, 99) / 100)  // Cash in: 500-5000
                : rand(200, 3000) + (rand(0, 99) / 100); // Cash out: 200-3000

            $transactionDate = Carbon::now()->subDays(rand(0, 30))
                ->subHours(rand(0, 23))
                ->subMinutes(rand(0, 59));

            $data = [
                'branch_manager_id' => $branchManager->id,
                'transaction_type' => $transactionType,
                'amount' => round($amount, 2),
                'is_cash_in' => $isCashIn,
                'transaction_date' => $transactionDate,
            ];

            // Add specific fields based on transaction type
            if ($transactionType === 'Total Sales') {
                $data['cashier_name'] = $cashierNames[array_rand($cashierNames)];
            } elseif ($transactionType === 'Handover to Brand Owner') {
                $data['brand_owner_name'] = $brandOwnerNames[array_rand($brandOwnerNames)];
            }

            PersonalLedgerTransaction::create($data);
        }

        $this->command->info("   ✓ Created {$transactionCount} transactions for {$branchManager->name}");
    }
}
