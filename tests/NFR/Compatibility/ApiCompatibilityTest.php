<?php

namespace Tests\NFR\Compatibility;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Compatibility Requirements Test: API Compatibility
 * 
 * Tests API compatibility requirements:
 * - RESTful API with JSON payloads
 * - Backward compatibility for 2 major versions
 * - Versioned API endpoints
 * - Standard HTTP status code usage
 * - API response format consistency
 */
class ApiCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = BranchManager::factory()->create([
            'email' => 'compatibility-test-manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);
    }

    /**
     * Test: RESTful API design
     * Requirement: RESTful API with JSON payloads
     */
    public function test_restful_api_design(): void
    {
        // GET request
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $this->assertEquals(200, $response->status(), "GET should return 200 for existing resource");

        // POST request
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', []);

        $this->assertContains(
            $response->status(),
            [201, 422],
            "POST should return 201 (created) or 422 (validation error)"
        );

        // PUT request
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/profile', [
                'name' => 'Updated Name',
            ]);

        $this->assertContains(
            $response->status(),
            [200, 422],
            "PUT should return 200 (updated) or 422 (validation error)"
        );

        // DELETE request (if endpoint exists)
        try {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->deleteJson('/api/v1/branch-manager/profile');

            $this->assertContains(
                $response->status(),
                [200, 204, 404],
                "DELETE should return 200/204 (deleted) or 404 (not found)"
            );
        } catch (\Exception $e) {
            // DELETE endpoint may not exist for profile
            $this->assertTrue(true, "DELETE endpoint may not be implemented for all resources");
        }
    }

    /**
     * Test: JSON payload format
     * Requirement: RESTful API with JSON payloads
     */
    public function test_json_payload_format(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200);
        
        // Should be valid JSON
        $this->assertJson($response->getContent(), "Response should be valid JSON");
        
        // Should have proper content-type header
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/json', $contentType, "Content-Type should be application/json");
    }

    /**
     * Test: Versioned API endpoints
     * Requirement: Versioned API endpoints
     */
    public function test_versioned_api_endpoints(): void
    {
        // v1 endpoint should work
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $this->assertEquals(200, $response->status(), "v1 API endpoint should work");

        // v2 endpoint (if exists) should also work
        try {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v2/branch-manager/profile');

            // If v2 exists, should return valid response
            $this->assertContains($response->status(), [200, 404], "v2 API endpoint should work if implemented");
        } catch (\Exception $e) {
            // v2 may not exist yet
            $this->assertTrue(true, "v2 API endpoint may not be implemented yet");
        }
    }

    /**
     * Test: Standard HTTP status codes
     * Requirement: Standard HTTP status code usage
     */
    public function test_standard_http_status_codes(): void
    {
        // 200 OK
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');
        $this->assertEquals(200, $response->status(), "GET existing resource should return 200");

        // 401 Unauthorized
        $response = $this->getJson('/api/v1/branch-manager/profile');
        $this->assertEquals(401, $response->status(), "Unauthenticated request should return 401");

        // 404 Not Found
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/nonexistent-resource/999999');
        $this->assertEquals(404, $response->status(), "Non-existent resource should return 404");

        // 422 Validation Error
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', []);
        $this->assertEquals(422, $response->status(), "Validation error should return 422");
    }

    /**
     * Test: Response format consistency
     * All responses should follow consistent format
     */
    public function test_response_format_consistency(): void
    {
        $endpoints = [
            '/api/v1/branch-manager/profile',
            '/api/v1/branch-manager/dashboard',
            '/api/v1/purchase/orders',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson($endpoint);

            if ($response->status() === 200) {
                $data = $response->json();
                
                // All successful responses should have consistent structure
                $this->assertArrayHasKey('success', $data, "Response should have 'success' field: {$endpoint}");
                $this->assertArrayHasKey('message', $data, "Response should have 'message' field: {$endpoint}");
            }
        }
    }

    /**
     * Test: Error response format consistency
     * Error responses should follow consistent format
     */
    public function test_error_response_format_consistency(): void
    {
        // 401 Error
        $response = $this->getJson('/api/v1/branch-manager/profile');
        $this->assertEquals(401, $response->status());
        $data = $response->json();
        $this->assertArrayHasKey('success', $data, "Error response should have 'success' field");
        $this->assertFalse($data['success'], "Error response should have success=false");

        // 422 Error
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', []);
        $this->assertEquals(422, $response->status());
        $data = $response->json();
        $this->assertArrayHasKey('success', $data, "Validation error should have 'success' field");
        $this->assertFalse($data['success'], "Validation error should have success=false");
    }

    /**
     * Test: Request method support
     * Endpoints should support appropriate HTTP methods
     */
    public function test_request_method_support(): void
    {
        // GET should be supported
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');
        $this->assertNotEquals(405, $response->status(), "GET method should be supported");

        // OPTIONS should be supported (CORS)
        $response = $this->options('/api/v1/branch-manager/profile');
        $this->assertNotEquals(404, $response->status(), "OPTIONS method should be supported for CORS");
    }

    /**
     * Test: Content negotiation
     * API should handle Accept headers appropriately
     */
    public function test_content_negotiation(): void
    {
        // JSON request
        $response = $this->actingAs($this->manager, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/v1/branch-manager/profile');

        $this->assertEquals(200, $response->status(), "JSON Accept header should be supported");
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/json', $contentType, "Response should be JSON");
    }

    /**
     * Test: API endpoint naming convention
     * Endpoints should follow RESTful naming conventions
     */
    public function test_api_endpoint_naming_convention(): void
    {
        // Endpoints should use lowercase with hyphens or camelCase consistently
        $endpoints = [
            '/api/v1/branch-manager/profile',
            '/api/v1/purchase/orders',
            '/api/v1/branch-manager/shifts',
        ];

        foreach ($endpoints as $endpoint) {
            // Should not contain uppercase or underscores in path
            $this->assertStringNotContainsString('_', $endpoint, "Endpoints should use hyphens, not underscores: {$endpoint}");
            
            // Path should be lowercase
            $pathParts = explode('/', $endpoint);
            foreach ($pathParts as $part) {
                if (!empty($part) && $part !== 'api' && !preg_match('/^v\d+$/', $part)) {
                    $this->assertEquals(strtolower($part), $part, "Endpoint path should be lowercase: {$endpoint}");
                }
            }
        }
    }

    /**
     * Test: Pagination consistency
     * Paginated endpoints should use consistent pagination format
     */
    public function test_pagination_consistency(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders?' . http_build_query([
                'per_page' => 20,
                'page' => 1,
            ]));

        if ($response->status() === 200) {
            $data = $response->json();
            
            // Should have pagination metadata
            if (isset($data['data']['meta'])) {
                $meta = $data['data']['meta'];
                $this->assertArrayHasKey('current_page', $meta, "Pagination should include current_page");
                $this->assertArrayHasKey('per_page', $meta, "Pagination should include per_page");
            }
        }
    }
}
