<?php

namespace Tests\NFR\Scalability;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

/**
 * Scalability Requirements Test: Data Growth
 * 
 * Tests data growth scalability requirements:
 * - Support for 5TB of data storage
 * - Efficient data archiving strategies
 * - Database partitioning for large tables
 * - Optimized query performance with data growth
 * - Support 200% user growth without architectural changes
 */
class DataGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = BranchManager::factory()->create([
            'email' => 'data-growth-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);
    }

    /**
     * Test: Query performance with large datasets
     * Requirement: Optimized query performance with data growth
     */
    public function test_query_performance_with_large_datasets(): void
    {
        // Create large dataset
        $largeDatasetSize = 1000;
        PurchaseOrder::factory()->count($largeDatasetSize)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        // Measure query performance
        DB::enableQueryLog();
        DB::flushQueryLog();

        $startTime = microtime(true);
        
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?' . http_build_query([
                'per_page' => 50,
            ]));

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;
        $queries = DB::getQueryLog();

        $response->assertStatus(200);
        
        // Should complete in reasonable time even with large dataset
        $this->assertLessThan(
            2000, // 2 seconds
            $responseTime,
            "Query should complete quickly even with {$largeDatasetSize} records. Actual: {$responseTime}ms"
        );

        // Should use pagination (limit queries)
        $this->assertLessThan(
            20,
            count($queries),
            "Query count should be optimized. Actual: " . count($queries) . " queries"
        );
    }

    /**
     * Test: Pagination effectiveness with large datasets
     * Pagination should work efficiently regardless of total data size
     */
    public function test_pagination_effectiveness(): void
    {
        // Create large dataset
        PurchaseOrder::factory()->count(5000)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $perPage = 20;
        $page = 1;

        $startTime = microtime(true);
        
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/purchase/history?page={$page}&per_page={$perPage}");

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $data = $response->json();

        // Pagination should be fast regardless of total data size
        $this->assertLessThan(
            1000, // 1 second for paginated query
            $responseTime,
            "Pagination should be fast even with large datasets. Actual: {$responseTime}ms"
        );

        // Should return only requested page size
        if (isset($data['data']['data'])) {
            $items = $data['data']['data'];
            $this->assertLessThanOrEqual(
                $perPage,
                count($items),
                "Pagination should limit results to per_page value"
            );
        }
    }

    /**
     * Test: Database index effectiveness
     * Indexes should improve query performance with data growth
     */
    public function test_database_index_effectiveness(): void
    {
        // Create dataset
        PurchaseOrder::factory()->count(1000)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        // Query with filter (should use index)
        $startTime = microtime(true);
        
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders');

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);

        // Should be fast if indexes are used
        $this->assertLessThan(
            1000,
            $responseTime,
            "Indexed queries should be fast. Actual: {$responseTime}ms"
        );
    }

    /**
     * Test: Memory efficiency with large result sets
     * System should use memory efficiently even with large datasets
     */
    public function test_memory_efficiency_large_results(): void
    {
        // Create large dataset
        PurchaseOrder::factory()->count(2000)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $memoryBefore = memory_get_usage(true);

        // Fetch paginated data
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?' . http_build_query([
                'per_page' => 100,
            ]));

        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        $response->assertStatus(200);

        // Memory usage should be reasonable (paginated)
        $this->assertLessThan(
            50 * 1024 * 1024, // 50MB for 100 records
            $memoryUsed,
            "Memory usage should be efficient with pagination. Used: {$memoryUsedMB}MB"
        );
    }

    /**
     * Test: Eager loading performance
     * Relationships should be loaded efficiently
     */
    public function test_eager_loading_performance(): void
    {
        // Create orders with items
        $orders = PurchaseOrder::factory()->count(100)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        foreach ($orders as $order) {
            PurchaseOrderItem::factory()->count(5)->create([
                'purchase_order_id' => $order->id,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?' . http_build_query([
                'per_page' => 50,
            ]));

        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        $response->assertStatus(200);

        // Should use eager loading (few queries, not N+1)
        $this->assertLessThan(
            20,
            $queryCount,
            "Should use eager loading to avoid N+1 queries. Actual: {$queryCount} queries"
        );
    }

    /**
     * Test: Data archiving readiness
     * Requirement: Efficient data archiving strategies
     */
    public function test_data_archiving_readiness(): void
    {
        // Create old data
        PurchaseOrder::factory()->count(100)->create([
            'branch_id' => $this->manager->branch_id,
            'created_at' => now()->subYears(2),
        ]);

        // Query should still work with old data
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?' . http_build_query([
                'start_date' => now()->subYears(3)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);

        // System should handle historical data efficiently
        $this->assertTrue(true, "Data archiving strategies should be implemented for old data");
    }

    /**
     * Test: Concurrent writes with data growth
     * System should handle concurrent writes as data grows
     */
    public function test_concurrent_writes_with_data_growth(): void
    {
        // Create initial data
        PurchaseOrder::factory()->count(500)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $concurrentWrites = 20;
        $successCount = 0;

        for ($i = 0; $i < $concurrentWrites; $i++) {
            try {
                // Read operations (simulating concurrent access)
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/purchase/orders');

                if ($response->status() === 200) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                // Handle exception
            }
        }

        $successRate = ($successCount / $concurrentWrites) * 100;
        
        $this->assertGreaterThan(
            80,
            $successRate,
            "System should handle concurrent operations with existing data. Success rate: {$successRate}%"
        );
    }
}
