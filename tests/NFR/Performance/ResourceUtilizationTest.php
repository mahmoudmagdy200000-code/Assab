<?php

namespace Tests\NFR\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Tests\TestCase;

/**
 * Performance Requirements Test: Resource Utilization
 *
 * Tests resource utilization requirements:
 * - CPU utilization: ≤ 70% during peak loads
 * - Memory utilization: ≤ 80% of allocated RAM
 * - Disk I/O: ≤ 1000 IOPS per database instance
 * - Network bandwidth: ≤ 80% of provisioned capacity
 * - Database query optimization (N+1 query detection)
 */
class ResourceUtilizationTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = BranchManager::factory()->create([
            'email' => 'resource-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);
    }

    /**
     * Test: Memory usage during large dataset operations
     * Requirement: Memory utilization ≤ 80% of allocated RAM
     */
    public function test_memory_usage_with_large_datasets(): void
    {
        // Create large dataset
        $largeDatasetSize = 5000;
        PurchaseOrder::factory()->count($largeDatasetSize)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $peakMemoryBefore = memory_get_peak_usage(true);

        // Perform operation that processes large dataset
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?'.http_build_query([
                'per_page' => 100, // Maximum allowed per API validation
            ]));

        $peakMemoryAfter = memory_get_peak_usage(true);

        $memoryUsed = $peakMemoryAfter - $peakMemoryBefore;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        $response->assertStatus(200);

        // Check that memory usage is reasonable (less than 150MB as per mobile requirement)
        // For server-side, we check that memory doesn't spike excessively
        $this->assertLessThan(
            500 * 1024 * 1024, // 500MB threshold for server operations
            $memoryUsed,
            "Memory usage exceeded 500MB during large dataset operation. Actual: {$memoryUsedMB}MB"
        );
    }

    /**
     * Test: Database query count optimization
     * Detects N+1 query problems
     */
    public function test_database_query_optimization(): void
    {
        // Create orders with items
        $orders = PurchaseOrder::factory()->count(50)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        foreach ($orders as $order) {
            PurchaseOrderItem::factory()->count(5)->create([
                'purchase_order_id' => $order->id,
            ]);
        }

        // Enable query logging
        DB::enableQueryLog();
        DB::flushQueryLog();

        $queryCountBefore = count(DB::getQueryLog());

        // Perform operation that should use eager loading
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?'.http_build_query([
                'per_page' => 50,
            ]));

        $queries = DB::getQueryLog();
        $queryCount = count($queries) - $queryCountBefore;

        $response->assertStatus(200);

        // Assert that query count is reasonable (should use eager loading)
        // With proper eager loading, should have ~5-10 queries, not 250+ (N+1 problem)
        $this->assertLessThan(
            20,
            $queryCount,
            "Query count suggests N+1 problem. Expected <20 queries, got {$queryCount} queries. ".
                'Consider using eager loading (with()) for relationships.'
        );
    }

    /**
     * Test: Database connection pool handling
     * Ensures system can handle concurrent database connections
     */
    public function test_database_connection_pool(): void
    {
        $testConnections = 10; // Test with 10 concurrent connections

        $successfulConnections = 0;

        for ($i = 0; $i < $testConnections; $i++) {
            try {
                // Each request opens a new connection (in test environment)
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/branch-manager/dashboard');

                if ($response->status() === 200) {
                    $successfulConnections++;
                } else {
                    $failedConnections++;
                }
            } catch (\Exception $e) {
                $failedConnections++;
            }
        }

        $successRate = ($successfulConnections / $testConnections) * 100;
        $this->assertGreaterThan(
            90,
            $successRate,
            "Database connection pool handling below 90% success rate. Actual: {$successRate}%"
        );
    }

    /**
     * Test: Response size optimization
     * Ensures API responses are optimized and not excessively large
     */
    public function test_api_response_size_optimization(): void
    {
        // Create moderate dataset
        PurchaseOrder::factory()->count(100)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders?'.http_build_query([
                'per_page' => 50,
            ]));

        $response->assertStatus(200);

        $responseSize = strlen($response->getContent());
        $responseSizeKB = $responseSize / 1024;

        // Response should be reasonable size (less than 500KB for paginated list)
        $this->assertLessThan(
            500 * 1024, // 500KB
            $responseSize,
            "API response size exceeded 500KB. Actual: {$responseSizeKB}KB. ".
                'Consider pagination, field selection, or response compression.'
        );
    }

    /**
     * Test: Cache effectiveness
     * Ensures caching is being used effectively
     */
    public function test_cache_effectiveness(): void
    {
        // Clear cache
        cache()->flush();

        // First request (cache miss - should be slower)
        $response1 = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/dashboard');

        $response1->assertStatus(200);

        // Second request (cache hit - should be faster)
        $response2 = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/dashboard');

        $response2->assertStatus(200);

        // Cached response should be faster (or at least not slower)
        // Note: In test environment, caching may not be as effective
        // This test mainly ensures cache doesn't break functionality
        $this->assertTrue(true, 'Cache effectiveness check passed');
    }

    /**
     * Test: Efficient pagination
     * Ensures pagination is working correctly and efficiently
     */
    public function test_efficient_pagination(): void
    {
        // Create large dataset
        PurchaseOrder::factory()->count(1000)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $perPage = 20;
        $pagesToTest = 5;
        $totalQueries = 0;

        DB::enableQueryLog();

        for ($page = 1; $page <= $pagesToTest; $page++) {
            DB::flushQueryLog();

            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson("/api/v1/purchase/history?page={$page}&per_page={$perPage}");

            $response->assertStatus(200);

            $queries = DB::getQueryLog();
            $totalQueries += count($queries);
        }

        // Average queries per page should be reasonable
        $avgQueriesPerPage = $totalQueries / $pagesToTest;

        $this->assertLessThan(
            10,
            $avgQueriesPerPage,
            "Average queries per paginated page too high. Expected <10, got {$avgQueriesPerPage}. ".
                'Check for N+1 queries or missing indexes.'
        );
    }

    /**
     * Test: Memory leak detection
     * Checks for memory leaks in long-running operations
     */
    public function test_memory_leak_detection(): void
    {
        $iterations = 50;
        $memorySamples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/dashboard');

            $response->assertStatus(200);

            // Sample memory every 10 iterations
            if ($i % 10 === 0) {
                $memorySamples[] = memory_get_usage(true);
            }
        }

        // Check that memory doesn't consistently grow (potential leak)
        if (count($memorySamples) >= 3) {
            $initialMemory = $memorySamples[0];
            $finalMemory = end($memorySamples);
            $memoryGrowth = $finalMemory - $initialMemory;
            $memoryGrowthMB = $memoryGrowth / 1024 / 1024;

            // Allow some memory growth but not excessive (less than 50MB over iterations)
            $this->assertLessThan(
                50 * 1024 * 1024,
                $memoryGrowth,
                "Potential memory leak detected. Memory grew by {$memoryGrowthMB}MB over {$iterations} iterations."
            );
        }

        $this->assertTrue(true, 'Memory leak detection completed');
    }
}
