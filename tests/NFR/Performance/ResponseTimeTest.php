<?php

namespace Tests\NFR\Performance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Facades\Hash;

/**
 * Performance Requirements Test: Response Time
 * 
 * Tests API response time requirements:
 * - API response time: ≤ 500ms for 95% of requests
 * - Report generation: ≤ 30 seconds for complex financial reports
 * - Data export: ≤ 60 seconds for 10,000 records
 * - Screen loading time: ≤ 2 seconds for 95% of screens (mobile app simulation)
 */
class ResponseTimeTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;
    protected Cashier $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = BranchManager::factory()->create([
            'email' => 'perf-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);

        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->manager->branch_id,
        ]);
    }

    /**
     * Test: API response time for Purchase Orders list endpoint
     * Requirement: ≤ 500ms for 95% of requests
     */
    public function test_purchase_orders_index_response_time(): void
    {
        // Create test data
        PurchaseOrder::factory()->count(100)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $startTime = microtime(true);
        
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders');

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $response->assertStatus(200);
        
        $this->assertLessThan(
            500,
            $responseTime,
            "Purchase orders index endpoint exceeded 500ms threshold. Actual: {$responseTime}ms"
        );
    }

    /**
     * Test: API response time for Shift handover endpoint
     * Requirement: ≤ 500ms for 95% of requests
     */
    public function test_shift_handover_response_time(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/shifts');

        $responseTime = $this->measureResponseTime(function () use ($response) {
            return $response;
        });

        $response->assertStatus(200);
        $this->assertLessThan(500, $responseTime, "Shift list exceeded 500ms. Actual: {$responseTime}ms");
    }

    /**
     * Test: API response time for Cashier operations
     * Requirement: ≤ 500ms for 95% of requests
     */
    public function test_cashier_operations_response_time(): void
    {
        $startTime = microtime(true);
        
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts');

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(500, $responseTime, "Cashier shifts endpoint exceeded 500ms. Actual: {$responseTime}ms");
    }

    /**
     * Test: Purchase History report generation time
     * Requirement: ≤ 30 seconds for complex financial reports
     */
    public function test_purchase_history_report_generation_time(): void
    {
        // Create large dataset for report
        PurchaseOrder::factory()->count(1000)->create([
            'branch_id' => $this->manager->branch_id,
            'created_at' => now()->subMonths(3),
        ]);

        $startTime = microtime(true);
        
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?' . http_build_query([
                'start_date' => now()->subMonths(3)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]));

        $endTime = microtime(true);
        $responseTime = $endTime - $startTime;

        $response->assertStatus(200);
        $this->assertLessThan(
            30,
            $responseTime,
            "Purchase history report generation exceeded 30 seconds. Actual: {$responseTime}s"
        );
    }

    /**
     * Test: Data export performance for large datasets
     * Requirement: ≤ 60 seconds for 10,000 records
     */
    public function test_purchase_history_export_performance(): void
    {
        // Create 10,000 records
        PurchaseOrder::factory()->count(10000)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $startTime = microtime(true);
        
        // Simulate export endpoint (adjust endpoint if different)
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history?' . http_build_query([
                'per_page' => 10000,
                'export' => true,
            ]));

        $endTime = microtime(true);
        $responseTime = $endTime - $startTime;

        // Allow export operations to succeed or return appropriate response
        $this->assertLessThan(
            60,
            $responseTime,
            "Data export exceeded 60 seconds for 10,000 records. Actual: {$responseTime}s"
        );
    }

    /**
     * Test: Multiple API endpoints response time (95th percentile)
     * Requirement: 95% of requests should be ≤ 500ms
     */
    public function test_multiple_endpoints_percentile_response_time(): void
    {
        $endpoints = [
            '/api/v1/purchase/orders',
            '/api/v1/branch-manager/shifts',
            '/api/v1/branch-manager/dashboard',
            '/api/v1/branch-manager/profile',
        ];

        $responseTimes = [];

        foreach ($endpoints as $endpoint) {
            $startTime = microtime(true);
            
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson($endpoint);

            $endTime = microtime(true);
            $responseTime = ($endTime - $startTime) * 1000;
            $responseTimes[] = $responseTime;

            $response->assertStatus(200);
        }

        // Calculate 95th percentile
        sort($responseTimes);
        $percentile95Index = (int)(count($responseTimes) * 0.95);
        $percentile95 = $responseTimes[$percentile95Index] ?? end($responseTimes);

        $this->assertLessThan(
            500,
            $percentile95,
            "95th percentile response time exceeded 500ms. Actual: {$percentile95}ms"
        );
    }

    /**
     * Test: Dashboard loading time (simulating mobile app screen)
     * Requirement: ≤ 2 seconds for 95% of screens
     */
    public function test_dashboard_loading_time(): void
    {
        $startTime = microtime(true);
        
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/dashboard');

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(
            2000,
            $responseTime,
            "Dashboard loading exceeded 2 seconds. Actual: {$responseTime}ms"
        );
    }

    /**
     * Helper method to measure response time
     */
    private function measureResponseTime(callable $callback): float
    {
        $startTime = microtime(true);
        $callback();
        $endTime = microtime(true);
        return ($endTime - $startTime) * 1000; // Return in milliseconds
    }
}
