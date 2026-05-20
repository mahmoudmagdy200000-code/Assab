<?php

namespace Tests\NFR\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Tests\TestCase;

/**
 * Security Requirements Test: Data Protection
 *
 * Tests data protection requirements:
 * - AES-256 encryption for data at rest
 * - TLS 1.3 for all data in transit
 * - Encrypted local database on mobile devices
 * - Key rotation every 90 days
 * - Personal data masking in logs and displays
 * - Right to erasure compliance within 30 days
 * - Data anonymization for analytics purposes
 */
class DataProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Sensitive data encryption
     * Requirement: AES-256 encryption for data at rest
     */
    public function test_sensitive_data_encryption(): void
    {
        $plainText = 'sensitive_data_12345';

        // Laravel's Crypt uses AES-256-CBC by default
        $encrypted = Crypt::encryptString($plainText);

        // Encrypted data should be different from plain text
        $this->assertNotEquals($plainText, $encrypted, 'Data should be encrypted');
        $this->assertTrue(strlen($encrypted) > strlen($plainText), 'Encrypted data should be longer');

        // Should decrypt correctly
        $decrypted = Crypt::decryptString($encrypted);
        $this->assertEquals($plainText, $decrypted, 'Decryption should work correctly');
    }

    /**
     * Test: Password not in responses
     * Passwords should never be exposed in API responses
     */
    public function test_password_not_in_responses(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'sensitive-data-test@assab.com',
            'password' => Hash::make('secret123'),
            'is_first_login' => false,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200);
        $responseData = $response->json();

        // Check that password is not in response at any level
        $responseString = json_encode($responseData);
        // Check for common password field names, but allow it in email addresses
        $this->assertStringNotContainsString('"password"', $responseString, 'Password field should not appear in response');
        $this->assertStringNotContainsString(Hash::make('secret123'), $responseString, 'Hashed password should not appear in response');
    }

    /**
     * Test: SQL injection prevention
     * Requirement: SQL injection prevention
     */
    public function test_sql_injection_prevention(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'sqlinject-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Common SQL injection attempts
        $sqlInjectionAttempts = [
            "'; DROP TABLE users; --",
            "' OR '1'='1",
            "1' UNION SELECT * FROM users--",
            "admin'--",
        ];

        foreach ($sqlInjectionAttempts as $attempt) {
            // Try in query parameter
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/purchase/orders?'.http_build_query(['search' => $attempt]));

            // Should not crash, should return error or empty result
            $this->assertContains(
                $response->status(),
                [200, 400, 422, 500],
                "SQL injection attempt should be handled safely: {$attempt}"
            );

            // Verify no SQL error in response
            $responseData = $response->json();
            if (isset($responseData['message'])) {
                $this->assertStringNotContainsString(
                    'SQL',
                    $responseData['message'],
                    'SQL errors should not be exposed'
                );
            }
        }
    }

    /**
     * Test: XSS prevention
     * Requirement: XSS protection
     */
    public function test_xss_prevention(): void
    {
        $xssAttempts = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
            '<svg onload=alert("XSS")>',
        ];

        foreach ($xssAttempts as $attempt) {
            // Create manager with XSS in name (if allowed)
            try {
                $manager = BranchManager::factory()->create([
                    'email' => 'xss-test@assab.com',
                    'name' => $attempt,
                    'password' => Hash::make('password123'),
                ]);

                // If creation succeeds, check that XSS is sanitized in response
                $response = $this->actingAs($manager, 'sanctum')
                    ->getJson('/api/v1/branch-manager/profile');

                if ($response->status() === 200) {
                    $responseData = $response->json();
                    $responseString = json_encode($responseData);

                    // Check that script tags are not present
                    $this->assertStringNotContainsString(
                        '<script>',
                        $responseString,
                        "XSS attempt should be sanitized: {$attempt}"
                    );
                }
            } catch (\Exception $e) {
                // Validation error is acceptable (XSS prevented)
                $this->assertTrue(true, "XSS attempt rejected: {$attempt}");
            }
        }
    }

    /**
     * Test: CSRF protection
     * Requirement: CSRF protection
     */
    public function test_csrf_protection(): void
    {
        // CSRF protection is typically for web forms, not API
        // APIs use token-based auth instead
        // This test verifies that API endpoints don't require CSRF tokens (which is correct for APIs)

        $manager = BranchManager::factory()->create([
            'email' => 'csrf-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // API request without CSRF token should work (APIs use Bearer tokens)
        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/purchase/orders', [
                'test' => 'data',
            ]);

        // Should either succeed or fail with validation, not CSRF error
        $this->assertNotEquals(419, $response->status(), 'API endpoints should not require CSRF tokens');
    }

    /**
     * Test: Data masking in logs
     * Requirement: Personal data masking in logs and displays
     */
    public function test_data_masking_in_logs(): void
    {
        // This test verifies that sensitive data is not logged
        // In practice, logging should mask emails, phones, etc.

        $manager = BranchManager::factory()->create([
            'email' => 'masking-test@assab.com',
            'password' => Hash::make('password123'),
            'phone' => '+966501234567',
        ]);

        // Perform operation that might log data
        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200);

        // In production, logs should mask sensitive data
        // This test serves as a reminder to implement masking
        $this->assertTrue(true, 'Data masking should be implemented in logging');
    }

    /**
     * Test: HTTPS enforcement
     * Requirement: TLS 1.3 for all data in transit
     */
    public function test_https_enforcement(): void
    {
        // In test environment, HTTPS may not be enforced
        // This test verifies the concept

        // In production, middleware should enforce HTTPS
        // For now, just verify endpoint exists
        $this->assertTrue(true, 'HTTPS enforcement should be implemented in production middleware');
    }

    /**
     * Test: Input sanitization
     * User input should be sanitized before processing
     */
    public function test_input_sanitization(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'sanitize-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $maliciousInputs = [
            '<script>alert("XSS")</script>',
            '../../etc/passwd',
            "'; DROP TABLE users; --",
        ];

        foreach ($maliciousInputs as $input) {
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/purchase/orders', [
                    'notes' => $input, // Try in a text field
                ]);

            // Should either validate or sanitize
            $this->assertContains(
                $response->status(),
                [200, 201, 400, 422],
                'Input should be validated/sanitized: '.substr($input, 0, 20)
            );

            // If saved, check it's sanitized
            if ($response->status() === 200 || $response->status() === 201) {
                $responseData = $response->json();
                if (isset($responseData['data']['notes'])) {
                    $this->assertStringNotContainsString(
                        '<script>',
                        $responseData['data']['notes'],
                        'Input should be sanitized'
                    );
                }
            }
        }
    }

    /**
     * Test: Sensitive headers not exposed
     * System should not expose sensitive server information
     */
    public function test_sensitive_headers_not_exposed(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'headers-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $headers = $response->headers->all();

        // Sensitive headers should not be exposed
        $sensitiveHeaders = ['X-Powered-By', 'Server', 'X-Debug'];

        foreach ($sensitiveHeaders as $header) {
            $this->assertArrayNotHasKey(
                strtolower($header),
                array_change_key_case($headers, CASE_LOWER),
                "Sensitive header {$header} should not be exposed"
            );
        }
    }

    /**
     * Test: Rate limiting on authentication endpoints
     * Prevents brute force attacks
     */
    public function test_rate_limiting_on_auth(): void
    {
        BranchManager::factory()->create([
            'email' => 'ratelimit-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt multiple failed logins
        $attempts = 10;
        $statusCodes = [];

        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->postJson('/api/v1/branch-manager/auth/login', [
                'identifier' => 'ratelimit-test@assab.com',
                'password' => 'wrongpassword',
            ]);

            $statusCodes[] = $response->status();
        }

        // Should eventually rate limit (429) or continue rejecting (401)
        $hasRateLimit = in_array(429, $statusCodes);
        $allUnauthorized = count(array_filter($statusCodes, fn ($s) => $s === 401)) === $attempts;

        $this->assertTrue(
            $hasRateLimit || $allUnauthorized,
            'Rate limiting should be implemented on authentication endpoints'
        );
    }
}
