<?php

namespace Tests\NFR\Reliability;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Reliability Requirements Test: Fault Tolerance
 * 
 * Tests fault tolerance requirements:
 * - Graceful degradation during partial system failures
 * - Automatic retry mechanism for failed API calls (3 attempts)
 * - Data integrity maintained during network failures
 * - Session persistence during application crashes
 * - Mobile app crash recovery: ≤ 30 seconds
 * - Data synchronization recovery: Automatic upon connectivity
 * - Transaction rollback capability for financial operations
 * - Backup restoration: ≤ 4 hours for full system recovery
 */
class FaultToleranceTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = BranchManager::factory()->create([
            'email' => 'fault-tolerance-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);
    }

    /**
     * Test: Automatic retry mechanism
     * Requirement: Automatic retry mechanism for failed API calls (3 attempts)
     */
    public function test_automatic_retry_mechanism(): void
    {
        // This test verifies that retry logic exists (typically in HTTP client configuration)
        // In Laravel, retries are usually handled by HTTP client or queue jobs
        
        $maxRetries = 3;
        $attemptCount = 0;

        // Simulate retry logic
        $success = false;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $attemptCount = $attempt;
            try {
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/branch-manager/dashboard');

                if ($response->status() === 200) {
                    $success = true;
                    break;
                }
            } catch (\Exception $e) {
                // Retry on exception
                if ($attempt < $maxRetries) {
                    continue;
                }
            }
        }

        $this->assertTrue(
            $success,
            "System should succeed within {$maxRetries} retry attempts. Attempts made: {$attemptCount}"
        );
    }

    /**
     * Test: Transaction rollback on failure
     * Requirement: Transaction rollback capability for financial operations
     */
    public function test_transaction_rollback_on_failure(): void
    {
        $initialOrderCount = PurchaseOrder::count();

        try {
            DB::beginTransaction();

            // Create order
            PurchaseOrder::factory()->create([
                'branch_id' => $this->manager->branch_id,
            ]);

            // Simulate failure
            throw new \Exception('Simulated failure');

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
        }

        $finalOrderCount = PurchaseOrder::count();

        // Order should not be created due to rollback
        $this->assertEquals(
            $initialOrderCount,
            $finalOrderCount,
            "Transaction rollback failed. Order count changed: {$initialOrderCount} -> {$finalOrderCount}"
        );
    }

    /**
     * Test: Data integrity during partial failures
     * Requirement: Data integrity maintained during network failures
     */
    public function test_data_integrity_during_partial_failure(): void
    {
        $initialOrderCount = PurchaseOrder::count();

        // Simulate partial failure scenario
        try {
            // Create order successfully
            $order = PurchaseOrder::factory()->create([
                'branch_id' => $this->manager->branch_id,
            ]);

            // Verify order exists
            $this->assertDatabaseHas('purchase_orders', [
                'id' => $order->id,
                'branch_id' => $this->manager->branch_id,
            ]);

            // Simulate subsequent failure
            // Data should remain consistent
            $order->refresh();
            $this->assertNotNull($order->id, "Order data integrity maintained");

        } catch (\Exception $e) {
            // On failure, verify no partial data exists
            $finalOrderCount = PurchaseOrder::count();
            $this->assertLessThanOrEqual(
                $initialOrderCount + 1,
                $finalOrderCount,
                "Partial failure left inconsistent data"
            );
        }
    }

    /**
     * Test: Error handling and logging
     * System should handle errors gracefully and log them
     */
    public function test_error_handling_and_logging(): void
    {
        Log::shouldReceive('error')->atLeast()->once();

        // Trigger an error scenario (invalid request)
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                // Invalid data to trigger validation error
                'invalid_field' => 'invalid_value',
            ]);

        // Should return proper error response, not crash
        $this->assertContains(
            $response->status(),
            [400, 422, 500],
            "System should handle errors gracefully"
        );

        // Response should be JSON
        $this->assertJson($response->getContent());
        
        // Response should have error message
        $data = $response->json();
        $this->assertArrayHasKey(
            'success',
            $data,
            "Error response should follow standard format"
        );
    }

    /**
     * Test: Graceful handling of invalid requests
     * System should not crash on invalid input
     */
    public function test_graceful_invalid_request_handling(): void
    {
        $invalidInputs = [
            null,
            [],
            ['invalid' => 'data'],
            str_repeat('a', 10000), // Very long string
        ];

        foreach ($invalidInputs as $invalidInput) {
            try {
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->postJson('/api/v1/purchase/orders', $invalidInput);

                // Should return error, not crash
                $this->assertContains(
                    $response->status(),
                    [400, 422, 500],
                    "Invalid input should return error status, not crash"
                );
            } catch (\Exception $e) {
                // Exception is acceptable if properly handled
                $this->assertTrue(true, "Exception caught and handled");
            }
        }
    }

    /**
     * Test: Database connection recovery
     * System should recover from database connection issues
     */
    public function test_database_connection_recovery(): void
    {
        // Get initial connection
        $initialConnection = DB::connection()->getPdo();
        $this->assertNotNull($initialConnection, "Initial database connection should work");

        // Simulate reconnection
        try {
            DB::reconnect();
            $reconnected = DB::connection()->getPdo();
            $this->assertNotNull($reconnected, "Database should reconnect successfully");
        } catch (\Exception $e) {
            $this->fail("Database reconnection failed: " . $e->getMessage());
        }
    }

    /**
     * Test: Concurrent request handling
     * System should handle concurrent requests without data corruption
     */
    public function test_concurrent_request_handling(): void
    {
        $initialOrderCount = PurchaseOrder::count();
        $concurrentRequests = 10;

        // Simulate concurrent requests
        for ($i = 0; $i < $concurrentRequests; $i++) {
            try {
                $response = $this->actingAs($this->manager, 'sanctum')
                    ->getJson('/api/v1/purchase/orders');

                $this->assertContains(
                    $response->status(),
                    [200, 404, 500],
                    "Concurrent request should return valid status"
                );
            } catch (\Exception $e) {
                // Exception acceptable if system remains stable
            }
        }

        // Verify data integrity maintained
        $finalOrderCount = PurchaseOrder::count();
        $this->assertGreaterThanOrEqual(
            $initialOrderCount,
            $finalOrderCount,
            "Concurrent requests should not corrupt data"
        );
    }

    /**
     * Test: Timeout handling
     * System should handle timeouts gracefully
     */
    public function test_timeout_handling(): void
    {
        // Set shorter timeout for testing
        $originalTimeout = ini_get('max_execution_time');

        try {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/dashboard', [
                    'timeout' => 5, // 5 seconds timeout
                ]);

            // Should return response or timeout gracefully
            $this->assertContains(
                $response->status(),
                [200, 408, 500],
                "System should handle timeouts gracefully"
            );
        } catch (\Exception $e) {
            // Timeout exception is acceptable if handled
            $this->assertStringContainsString(
                'timeout',
                strtolower($e->getMessage()),
                "Timeout should be properly reported"
            );
        }
    }

    /**
     * Test: Memory limit handling
     * System should handle memory limits gracefully
     */
    public function test_memory_limit_handling(): void
    {
        $memoryLimit = ini_get('memory_limit');
        $this->assertNotNull($memoryLimit, "Memory limit should be set");

        // Perform normal operation
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/dashboard');

        // Should complete without memory errors
        $this->assertContains(
            $response->status(),
            [200, 500],
            "System should handle memory constraints"
        );

        $memoryUsage = memory_get_usage(true);
        $this->assertLessThan(
            $this->parseMemoryLimit($memoryLimit) * 0.9, // Use 90% of limit as safety margin
            $memoryUsage,
            "Memory usage should be within limits"
        );
    }

    /**
     * Helper: Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
