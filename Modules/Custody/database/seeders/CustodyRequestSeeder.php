<?php

namespace Modules\Custody\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyRequestTimeline;
use Modules\BranchManagers\Models\BranchManager;
use Carbon\Carbon;

class CustodyRequestSeeder extends Seeder
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

        $this->command->info('🌱 Seeding Custody Requests...');

        foreach ($branchManagers as $branchManager) {
            $this->createRequestsForManager($branchManager);
        }

        $this->command->info('✅ Custody Requests seeded successfully!');
    }

    private function createRequestsForManager(BranchManager $branchManager): void
    {
        $statuses = ['Pending', 'Approved', 'Rejected', 'Completed'];
        $receiptMethods = ['Cash Handover', 'Bank Transfer'];

        $purposes = [
            'Restock inventory for holiday season',
            'Monthly inventory restock',
            'Urgent equipment repair',
            'Office supplies purchase',
            'Marketing campaign expenses',
            'Branch maintenance and repairs',
            'Staff training program',
            'Technology upgrade',
            'Emergency funds',
            'Seasonal inventory preparation'
        ];

        // Create 5-10 requests per manager
        $requestCount = rand(5, 10);

        for ($i = 0; $i < $requestCount; $i++) {
            $status = $statuses[array_rand($statuses)];
            $requestedAmount = rand(1000, 10000) + (rand(0, 99) / 100);
            $createdAt = Carbon::now()->subDays(rand(0, 60))
                ->subHours(rand(0, 23))
                ->subMinutes(rand(0, 59));

            $request = CustodyRequest::create([
                'branch_manager_id' => $branchManager->id,
                'branch_id' => $branchManager->branch_id,
                'requested_amount' => round($requestedAmount, 2),
                'purpose' => $purposes[array_rand($purposes)],
                'preferred_receipt_method' => $receiptMethods[array_rand($receiptMethods)],
                'additional_notes' => rand(0, 1) ? 'Urgent request - please process quickly' : null,
                'status' => $status,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Create timeline entries based on status
            $this->createTimelineForRequest($request, $branchManager, $status, $createdAt);

            // Set approval/rejection dates if applicable
            if ($status === 'Approved') {
                $request->update([
                    'approved_by' => $branchManager->id,
                    'approved_at' => $createdAt->copy()->addHours(rand(1, 24)),
                    'viewed_at' => $createdAt->copy()->addMinutes(rand(5, 60)),
                ]);
            } elseif ($status === 'Rejected') {
                $request->update([
                    'rejected_by' => $branchManager->id,
                    'rejected_at' => $createdAt->copy()->addHours(rand(1, 48)),
                    'rejection_reason' => 'Insufficient justification or budget constraints',
                    'viewed_at' => $createdAt->copy()->addMinutes(rand(5, 60)),
                ]);
            } elseif ($status === 'Pending') {
                // 50% chance it was viewed
                if (rand(0, 1)) {
                    $request->update([
                        'viewed_at' => $createdAt->copy()->addMinutes(rand(5, 120)),
                    ]);
                }
            }
        }

        $this->command->info("   ✓ Created {$requestCount} requests for {$branchManager->name}");
    }

    private function createTimelineForRequest(
        CustodyRequest $request,
        BranchManager $branchManager,
        string $status,
        Carbon $createdAt
    ): void {
        // Submit Case - always exists
        CustodyRequestTimeline::create([
            'custody_request_id' => $request->id,
            'stage' => 'Submit Case',
            'status' => 'Submitted',
            'actor_id' => $branchManager->id,
            'actor_type' => 'branch_manager',
            'actor_name' => $branchManager->name,
            'actor_profile_image' => $branchManager->image,
            'action_date' => $createdAt,
        ]);

        // View Case - if viewed
        if ($request->viewed_at) {
            CustodyRequestTimeline::create([
                'custody_request_id' => $request->id,
                'stage' => 'View Case',
                'status' => 'Viewed',
                'actor_id' => $branchManager->id,
                'actor_type' => 'branch_manager',
                'actor_name' => $branchManager->name,
                'actor_profile_image' => $branchManager->image,
                'action_date' => $request->viewed_at,
            ]);
        }

        // Approve or Reject Case
        if ($status === 'Approved' && $request->approved_at) {
            CustodyRequestTimeline::create([
                'custody_request_id' => $request->id,
                'stage' => 'Approve Case',
                'status' => 'Approved',
                'actor_id' => $branchManager->id,
                'actor_type' => 'branch_manager',
                'actor_name' => $branchManager->name,
                'actor_profile_image' => $branchManager->image,
                'action_date' => $request->approved_at,
            ]);
        } elseif ($status === 'Rejected' && $request->rejected_at) {
            CustodyRequestTimeline::create([
                'custody_request_id' => $request->id,
                'stage' => 'Reject Case',
                'status' => 'Rejected',
                'actor_id' => $branchManager->id,
                'actor_type' => 'branch_manager',
                'actor_name' => $branchManager->name,
                'actor_profile_image' => $branchManager->image,
                'action_date' => $request->rejected_at,
            ]);
        }
    }
}
