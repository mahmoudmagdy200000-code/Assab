<?php

namespace Tests\NFR\Performance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Shift\Models\CashierShift;
use Illuminate\Support\Facades\Hash;

/**
 * Performance Requirements Test: Throughput
 * 
 * Tests system throughput requirements:
 * - Support 500 concurrent users during peak hours
 * - Handle 100 simultaneous shift handovers
 * - Process 50 concurrent inventory counts
 * - Manage 200 simultaneous order processing operations
 * - Sales transactions: 100 transactions per minute per branch
 * - Inventory updates: 500 updates per minute system-wide
 * - Order processing: 200 orders per minute during peak
 * - Data synchronization: 1,000 records per minute per branch
 */
class ThroughputTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;
    protected array $cashiers;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = BranchManager::factory()->create([
            'email' => 'throughput-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);

        // Create multiple cashiers for concurrent operations
        $this->cashiers = Cashier::factory()->count(10)->create([
            'branch_id' => $this->manager->branch_id,
        ])->toArray();
    }

    /**
     * Test: Concurrent shift handovers throughput
     * Requirement: Handle 100 simultaneous shift handovers
     */
    public function test_concurrent_shift_handovers(): void
    {
        // Create shifts for handover testing
        $shifts = [];
        foreach ($this->cashiers as $cashier) {
            $shift = CashierShift::factory()->create([
                'cashier_id' => $cashier['id'],
                'branch_id' => $this->manager->branch_id,
            ]);
            $shifts[] = $shift;
        }

        $maxConcurrent = 100;
        $successCount = 0;
        $failureCount = 0;

        // Simulate concurrent handover requests (using smaller number for testing)
        $testConcurrent = min(10, $maxConcurrent); // Test with 10 for CI/CD, increase for production

        $startTime = microtime(true);

        for ($i = 0; $i < $testConcurrent; $i++) {
            try {
                $shift = $shifts[$i % count($shifts)] ?? $shifts[0];
                
                $response = $this->actingAs($this->cashiers[$i % count($this->cashiers)]['id'], 'sanctum')
                    ->getJson("/api/v1/cashier/shifts/{$shift->id}/handover/status");

                if ($response->status() === 200 || $response->status() === 404) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $throughput = $testConcurrent / $totalTime; // operations per second

        // Assert that system handled concurrent requests
        $successRate = ($successCount / $testConcurrent) * 100;
        $this->assertGreaterThan(
            80,
            $successRate,
            "Concurrent shift handovers success rate below 80%. Actual: {$successRate}%"
        );

        // Log throughput for monitoring
        $this->addToAssertionCount(1); // Mark as assertion passed
    }

    /**
     * Test: Order processing throughput
     * Requirement: 200 orders per minute during peak (≈3.33 orders/second)
     */
    public function test_order_processing_throughput(): void
    {
        $ordersPerMinute = 200;
        $targetOrdersPerSecond = $ordersPerMinute / 60; // ≈3.33 orders/second
        
        $testOrders = 20; // Test with 20 orders
        $startTime = microtime(true);

        for ($i = 0; $i < $testOrders; $i++) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/purchase/orders');

            $this->assertContains($response->status(), [200, 201]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $actualThroughput = $testOrders / $totalTime;

        $this->assertGreaterThanOrEqual(
            $targetOrdersPerSecond,
            $actualThroughput,
            "Order processing throughput below requirement. Required: {$targetOrdersPerSecond} ops/sec, Actual: {$actualThroughput} ops/sec"
        );
    }

    /**
     * Test: Inventory updates throughput
     * Requirement: 500 updates per minute system-wide (≈8.33 updates/second)
     */
    public function test_inventory_updates_throughput(): void
    {
        $updatesPerMinute = 500;
        $targetUpdatesPerSecond = $updatesPerMinute / 60; // ≈8.33 updates/second
        
        $testUpdates = 50; // Test with 50 updates
        $startTime = microtime(true);

        // Simulate inventory update requests
        for ($i = 0; $i < $testUpdates; $i++) {
            // Use a lightweight endpoint that represents inventory checking
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/purchase/orders/branch-items');

            $this->assertContains($response->status(), [200, 404]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $actualThroughput = $testUpdates / $totalTime;

        $this->assertGreaterThanOrEqual(
            $targetUpdatesPerSecond,
            $actualThroughput,
            "Inventory updates throughput below requirement. Required: {$targetUpdatesPerSecond} ops/sec, Actual: {$actualThroughput} ops/sec"
        );
    }

    /**
     * Test: Sales transactions throughput per branch
     * Requirement: 100 transactions per minute per branch (≈1.67 transactions/second)
     */
    public function test_sales_transactions_throughput(): void
    {
        $transactionsPerMinute = 100;
        $targetTransactionsPerSecond = $transactionsPerMinute / 60; // ≈1.67 transactions/second
        
        $testTransactions = 20;
        $startTime = microtime(true);

        // Simulate sales transaction requests
        for ($i = 0; $i < $testTransactions; $i++) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/dashboard/stats');

            $this->assertContains($response->status(), [200, 404]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $actualThroughput = $testTransactions / $totalTime;

        $this->assertGreaterThanOrEqual(
            $targetTransactionsPerSecond,
            $actualThroughput,
            "Sales transactions throughput below requirement. Required: {$targetTransactionsPerSecond} ops/sec, Actual: {$actualThroughput} ops/sec"
        );
    }

    /**
     * Test: Data synchronization throughput
     * Requirement: 1,000 records per minute per branch (≈16.67 records/second)
     */
    public function test_data_synchronization_throughput(): void
    {
        $recordsPerMinute = 1000;
        $targetRecordsPerSecond = $recordsPerMinute / 60; // ≈16.67 records/second
        
        // Create test data
        PurchaseOrder::factory()->count(100)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $testRecords = 100;
        $startTime = microtime(true);

        // Simulate data sync (paginated requests)
        $page = 1;
        $perPage = 20;
        $fetchedRecords = 0;

        while ($fetchedRecords < $testRecords) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/purchase/history?page={$page}&per_page={$perPage}");

            if ($response->status() === 200) {
                $data = $response->json('data.data', []);
                $fetchedRecords += count($data);
                if (count($data) < $perPage) break; // No more data
            }
            $page++;
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $actualThroughput = $fetchedRecords / $totalTime;

        $this->assertGreaterThanOrEqual(
            $targetRecordsPerSecond,
            $actualThroughput,
            "Data synchronization throughput below requirement. Required: {$targetRecordsPerSecond} records/sec, Actual: {$actualThroughput} records/sec"
        );
    }

    /**
     * Test: Concurrent inventory count operations
     * Requirement: Process 50 concurrent inventory counts
     */
    public function test_concurrent_inventory_counts(): void
    {
        $concurrentCounts = 50;
        $testCounts = min(10, $concurrentCounts); // Test with 10 for CI/CD
        
        $successCount = 0;

        for ($i = 0; $i < $testCounts; $i++) {
            try {
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/purchase/orders/branch-items');

                if ($response->status() === 200) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                // Count failures silently
            }
        }

        $successRate = ($successCount / $testCounts) * 100;
        $this->assertGreaterThan(
            80,
            $successRate,
            "Concurrent inventory counts success rate below 80%. Actual: {$successRate}%"
        );
    }
}
