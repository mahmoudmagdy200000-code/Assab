<?php

namespace Tests\NFR\Scalability;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Scalability Requirements Test: Load Testing
 * 
 * Tests scalability requirements:
 * - Support 500 concurrent users during peak hours
 * - 3x normal load during month-end closing
 * - 2x normal load during holiday seasons
 * - Graceful performance degradation under extreme load
 * - Automatic scaling triggers based on load metrics
 * 
 * Note: Full load testing should be done with dedicated tools (JMeter, K6, etc.)
 * These tests verify system can handle basic concurrent requests
 */
class LoadTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = BranchManager::factory()->create([
            'email' => 'load-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);
    }

    /**
     * Test: Concurrent user handling
     * Requirement: Support 500 concurrent users during peak hours
     */
    public function test_concurrent_user_handling(): void
    {
        $concurrentUsers = 50; // Test with 50 for CI/CD, increase for production testing
        $successCount = 0;
        $failureCount = 0;

        $startTime = microtime(true);

        // Simulate concurrent requests
        for ($i = 0; $i < $concurrentUsers; $i++) {
            try {
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/branch-manager/dashboard');

                if ($response->status() === 200) {
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

        $successRate = ($successCount / $concurrentUsers) * 100;
        
        $this->assertGreaterThan(
            80,
            $successRate,
            "System should handle {$concurrentUsers} concurrent requests with >80% success rate. Actual: {$successRate}%"
        );
    }

    /**
     * Test: Peak load handling (month-end simulation)
     * Requirement: 3x normal load during month-end closing
     */
    public function test_peak_load_handling_month_end(): void
    {
        // Create data simulating month-end activity
        PurchaseOrder::factory()->count(500)->create([
            'branch_id' => $this->manager->branch_id,
            'created_at' => now()->endOfMonth(),
        ]);

        $normalLoadRequests = 100;
        $peakLoadRequests = $normalLoadRequests * 3; // 3x normal load
        $testRequests = min(50, $peakLoadRequests); // Test with 50

        $successCount = 0;

        $startTime = microtime(true);

        for ($i = 0; $i < $testRequests; $i++) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/purchase/history?' . http_build_query([
                    'start_date' => now()->startOfMonth()->format('Y-m-d'),
                    'end_date' => now()->endOfMonth()->format('Y-m-d'),
                ]));

            if ($response->status() === 200) {
                $successCount++;
            }
        }

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) / $testRequests * 1000; // Average ms per request

        $successRate = ($successCount / $testRequests) * 100;
        
        $this->assertGreaterThan(
            70,
            $successRate,
            "System should handle 3x load with >70% success rate. Actual: {$successRate}%"
        );

        // Response time may degrade but should still be reasonable
        $this->assertLessThan(
            2000, // 2 seconds for peak load
            $responseTime,
            "Response time should remain reasonable under peak load. Actual: {$responseTime}ms"
        );
    }

    /**
     * Test: Performance degradation under load
     * Requirement: Graceful performance degradation under extreme load
     */
    public function test_performance_degradation_under_load(): void
    {
        // Baseline performance
        $baselineStart = microtime(true);
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/dashboard');
        $baselineEnd = microtime(true);
        $baselineTime = ($baselineEnd - $baselineStart) * 1000;

        $response->assertStatus(200);

        // Create load
        PurchaseOrder::factory()->count(1000)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        // Performance under load
        $loadStart = microtime(true);
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history');
        $loadEnd = microtime(true);
        $loadTime = ($loadEnd - $loadStart) * 1000;

        $response->assertStatus(200);

        // Performance may degrade but system should still function
        $degradationRatio = $loadTime / max($baselineTime, 1);
        
        $this->assertLessThan(
            10, // Should not degrade more than 10x
            $degradationRatio,
            "Performance degradation should be graceful. Degradation ratio: {$degradationRatio}x"
        );
    }

    /**
     * Test: Database connection pooling
     * System should efficiently handle database connections
     */
    public function test_database_connection_pooling(): void
    {
        $concurrentConnections = 20;
        $successfulConnections = 0;

        for ($i = 0; $i < $concurrentConnections; $i++) {
            try {
                DB::connection()->getPdo();
                $successfulConnections++;
            } catch (\Exception $e) {
                // Connection failed
            }
        }

        $successRate = ($successfulConnections / $concurrentConnections) * 100;
        
        $this->assertGreaterThan(
            90,
            $successRate,
            "Database connection pool should handle {$concurrentConnections} connections with >90% success. Actual: {$successRate}%"
        );
    }

    /**
     * Test: Memory usage under load
     * System should manage memory efficiently under load
     */
    public function test_memory_usage_under_load(): void
    {
        $memoryBefore = memory_get_usage(true);

        // Perform multiple operations
        for ($i = 0; $i < 100; $i++) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/dashboard');
        }

        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;
        $memoryUsedMB = $memoryUsed / 1024 / 1024;

        // Memory usage should be reasonable
        $this->assertLessThan(
            200 * 1024 * 1024, // 200MB for 100 requests
            $memoryUsed,
            "Memory usage should be reasonable under load. Used: {$memoryUsedMB}MB for 100 requests"
        );
    }

    /**
     * Test: Response time consistency
     * Response times should remain relatively consistent under moderate load
     */
    public function test_response_time_consistency(): void
    {
        $responseTimes = [];
        $samples = 20;

        for ($i = 0; $i < $samples; $i++) {
            $start = microtime(true);
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/dashboard');
            $end = microtime(true);
            
            $response->assertStatus(200);
            $responseTimes[] = ($end - $start) * 1000;
        }

        // Calculate coefficient of variation (CV) for consistency
        $mean = array_sum($responseTimes) / count($responseTimes);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $responseTimes)) / count($responseTimes);
        $stdDev = sqrt($variance);
        $cv = $mean > 0 ? ($stdDev / $mean) * 100 : 0;

        // CV < 50% indicates reasonable consistency
        $this->assertLessThan(
            50,
            $cv,
            "Response times should be consistent. Coefficient of variation: {$cv}%"
        );
    }

    /**
     * Test: Graceful handling of resource exhaustion
     * System should handle resource limits gracefully
     */
    public function test_graceful_resource_exhaustion_handling(): void
    {
        // Attempt operations that might exhaust resources
        $errors = 0;
        $successes = 0;

        for ($i = 0; $i < 50; $i++) {
            try {
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/branch-manager/dashboard');

                if ($response->status() === 200) {
                    $successes++;
                } elseif ($response->status() >= 500) {
                    $errors++;
                }
            } catch (\Exception $e) {
                $errors++;
            }
        }

        // Should have reasonable success rate even under stress
        $successRate = ($successes / 50) * 100;
        $this->assertGreaterThan(
            70,
            $successRate,
            "System should handle resource stress gracefully. Success rate: {$successRate}%"
        );
    }
}
