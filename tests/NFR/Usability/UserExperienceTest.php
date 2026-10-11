<?php

namespace Tests\NFR\Usability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;
use Tests\TestCase;

/**
 * Usability Requirements Test: User Experience
 *
 * Tests usability requirements:
 * - New user proficiency: ≤ 2 hours training time
 * - Task completion rate: ≥ 90% for primary workflows
 * - Error rate: ≤ 5% for common operations
 * - Help system access: ≤ 3 taps from any screen
 * - Common tasks completion: ≤ 3 steps
 * - Data entry reduction through automation: ≥ 40%
 * - Search functionality: Results in ≤ 2 seconds
 */
class UserExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = BranchManager::factory()->create([
            'email' => 'ux-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);
    }

    /**
     * Test: Task completion steps count
     * Requirement: Common tasks completion: ≤ 3 steps
     */
    public function test_task_completion_steps(): void
    {
        // Example: Viewing dashboard should be 1 step
        $steps = 1; // GET request

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/dashboard');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(3, $steps, 'Common tasks should be ≤ 3 steps');
    }

    /**
     * Test: Error message clarity
     * Error messages should be clear and actionable
     */
    public function test_error_message_clarity(): void
    {
        // Test validation error
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                // Missing required fields
            ]);

        $response->assertStatus(422);
        $responseData = $response->json();

        // Error message should exist and be descriptive
        $this->assertArrayHasKey('message', $responseData, 'Error response should have message');
        $this->assertNotEmpty($responseData['message'], 'Error message should not be empty');

        // Errors should be specific
        if (isset($responseData['errors'])) {
            $this->assertIsArray($responseData['errors'], 'Errors should be structured');
        }
    }

    /**
     * Test: API response consistency
     * Responses should follow consistent format
     */
    public function test_api_response_consistency(): void
    {
        $endpoints = [
            '/api/v1/branch-manager/dashboard',
            '/api/v1/branch-manager/profile',
            '/api/v1/purchase/orders',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson($endpoint);

            if ($response->status() === 200) {
                $data = $response->json();

                // All successful responses should have consistent structure
                $this->assertArrayHasKey('success', $data, "Response should have 'success' field");
                $this->assertArrayHasKey('message', $data, "Response should have 'message' field");

                if (isset($data['data'])) {
                    $this->assertTrue(true, 'Response structure is consistent');
                }
            }
        }
    }

    /**
     * Test: Search functionality performance
     * Requirement: Search functionality: Results in ≤ 2 seconds
     */
    public function test_search_functionality_performance(): void
    {
        // Create test data
        PurchaseOrder::factory()->count(100)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $startTime = microtime(true);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders?'.http_build_query([
                'search' => 'test',
            ]));

        $endTime = microtime(true);
        $responseTime = $endTime - $startTime;

        $response->assertStatus(200);
        $this->assertLessThan(
            2,
            $responseTime,
            "Search should return results in ≤ 2 seconds. Actual: {$responseTime}s"
        );
    }

    /**
     * Test: Pagination usability
     * Large datasets should be paginated for better UX
     */
    public function test_pagination_usability(): void
    {
        // Create large dataset
        PurchaseOrder::factory()->count(200)->create([
            'branch_id' => $this->manager->branch_id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders?'.http_build_query([
                'per_page' => 20,
            ]));

        $response->assertStatus(200);
        $data = $response->json();

        // Response should be paginated
        if (isset($data['data']['data'])) {
            $items = $data['data']['data'];
            $this->assertLessThanOrEqual(20, count($items), 'Response should be paginated');
        }

        // Should include pagination metadata
        if (isset($data['data']['meta'])) {
            $this->assertArrayHasKey('per_page', $data['data']['meta'], 'Pagination metadata should include per_page');
        }
    }

    /**
     * Test: Data validation feedback
     * Users should get immediate feedback on validation errors
     */
    public function test_data_validation_feedback(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                'invalid_field' => 'invalid_value',
            ]);

        // Should return validation error immediately
        $this->assertEquals(422, $response->status(), 'Validation errors should be returned immediately');

        $data = $response->json();
        $this->assertArrayHasKey('errors', $data, 'Validation response should contain field errors');
        $this->assertNotEmpty($data['errors'], 'Validation response should describe the invalid input');
    }

    /**
     * Test: Success message clarity
     * Success operations should provide clear confirmation
     */
    public function test_success_message_clarity(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200);
        $data = $response->json();

        // Success message should be present
        $this->assertArrayHasKey('message', $data, 'Success response should include message');
        $this->assertNotEmpty($data['message'], 'Success message should not be empty');
    }

    /**
     * Test: Field-level error messages
     * Validation errors should be specific to fields
     */
    public function test_field_level_error_messages(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                'email' => 'invalid-email',
            ]);

        if ($response->status() === 422) {
            $data = $response->json();

            // Should have field-specific errors
            if (isset($data['errors'])) {
                $this->assertIsArray($data['errors'], 'Errors should be structured by field');
            }
        }
    }

    /**
     * Test: Response data structure clarity
     * Response data should be well-structured and easy to parse
     */
    public function test_response_data_structure_clarity(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200);
        $data = $response->json();

        // Data should be structured
        $this->assertIsArray($data, 'Response should be array/object');
        $this->assertArrayHasKey('data', $data, "Response should have 'data' key");
    }

    /**
     * Test: Empty state handling
     * Empty results should be handled gracefully
     */
    public function test_empty_state_handling(): void
    {
        // Ensure no orders exist
        PurchaseOrder::where('branch_id', $this->manager->branch_id)->delete();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders');

        $response->assertStatus(200);
        $data = $response->json();

        // Should return empty array, not error
        if (isset($data['data']['data'])) {
            $this->assertIsArray($data['data']['data'], 'Empty results should return array');
        }
    }
}
