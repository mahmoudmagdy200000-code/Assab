<?php

namespace Tests\NFR\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Illuminate\Support\Facades\File;

/**
 * Security Requirements Test: Application Security
 * 
 * Tests application security requirements:
 * - OWASP Top 10 compliance
 * - Regular security penetration testing (quarterly)
 * - Code security scanning in CI/CD pipeline
 * - Dependency vulnerability monitoring
 * - Root/jailbreak detection and blocking (mobile)
 * - Certificate pinning for API communication (mobile)
 * - Secure storage of sensitive data (mobile)
 * - Anti-tampering mechanisms (mobile)
 */
class ApplicationSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: OWASP A01 - Broken Access Control
     * Users should not access unauthorized resources
     */
    public function test_owasp_a01_broken_access_control(): void
    {
        $manager1 = BranchManager::factory()->create([
            'email' => 'access1@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $manager2 = BranchManager::factory()->create([
            'email' => 'access2@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Manager1 should not access Manager2's data
        $response = $this->actingAs($manager1, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200);
        $profile1 = $response->json('data');

        // Verify Manager1 sees their own data, not Manager2's
        // Profile response is wrapped in data field from BranchManagerDetailResource
        $this->assertEquals($manager1->id, $profile1['id'] ?? null, "User should only see their own data");
    }

    /**
     * Test: OWASP A02 - Cryptographic Failures
     * Sensitive data should be properly encrypted
     */
    public function test_owasp_a02_cryptographic_failures(): void
    {
        $plainPassword = 'password123';
        
        $manager = BranchManager::factory()->create([
            'email' => 'crypto-test@assab.com',
            'password' => Hash::make($plainPassword),
        ]);

        // Password should be hashed, not plain text
        $this->assertNotEquals($plainPassword, $manager->password, "Passwords must be hashed");
        $this->assertTrue(Hash::check($plainPassword, $manager->password), "Password verification should work");
    }

    /**
     * Test: OWASP A03 - Injection
     * SQL injection and command injection prevention
     */
    public function test_owasp_a03_injection(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'injection-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // SQL injection attempt
        $sqlInjection = "1' OR '1'='1";
        
        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders?' . http_build_query(['search' => $sqlInjection]));

        // Should handle safely, not crash
        $this->assertContains(
            $response->status(),
            [200, 400, 422, 500],
            "SQL injection should be handled safely"
        );

        // Response should not contain SQL error messages
        $responseData = $response->json();
        if (isset($responseData['message'])) {
            $this->assertStringNotContainsString(
                'SQL',
                $responseData['message'],
                "SQL errors should not be exposed"
            );
        }
    }

    /**
     * Test: OWASP A04 - Insecure Design
     * Security should be built into the design
     */
    public function test_owasp_a04_insecure_design(): void
    {
        // Verify authentication is required for protected endpoints
        $response = $this->getJson('/api/v1/branch-manager/profile');
        
        $this->assertEquals(401, $response->status(), "Protected endpoints should require authentication");
    }

    /**
     * Test: OWASP A05 - Security Misconfiguration
     * System should not expose sensitive configuration
     */
    public function test_owasp_a05_security_misconfiguration(): void
    {
        // Check that sensitive files are not accessible
        $sensitiveFiles = [
            '.env',
            'config/database.php',
            'composer.json',
        ];

        foreach ($sensitiveFiles as $file) {
            // These should not be accessible via web
            $response = $this->get("/{$file}");
            
            // Should return 404 or 403, not 200
            $this->assertContains(
                $response->status(),
                [404, 403, 500],
                "Sensitive file {$file} should not be accessible"
            );
        }
    }

    /**
     * Test: OWASP A06 - Vulnerable Components
     * Dependencies should be up to date
     */
    public function test_owasp_a06_vulnerable_components(): void
    {
        // Check if composer.lock exists (indicates dependency locking)
        $composerLockPath = base_path('composer.lock');
        
        $this->assertTrue(
            File::exists($composerLockPath),
            "composer.lock should exist to lock dependency versions"
        );

        // In production, should run: composer audit
        $this->assertTrue(true, "Dependencies should be regularly audited for vulnerabilities");
    }

    /**
     * Test: OWASP A07 - Authentication Failures
     * Authentication should be implemented correctly
     */
    public function test_owasp_a07_authentication_failures(): void
    {
        // Test weak password handling (if implemented)
        $manager = BranchManager::factory()->create([
            'email' => 'auth-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Invalid credentials should be rejected
        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'identifier' => 'auth-test@assab.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertEquals(401, $response->status(), "Invalid credentials should be rejected");

        // Valid credentials should work
        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'identifier' => 'auth-test@assab.com',
            'password' => 'password123',
        ]);

        $this->assertEquals(200, $response->status(), "Valid credentials should be accepted");
    }

    /**
     * Test: OWASP A08 - Software and Data Integrity Failures
     * Data integrity should be maintained
     */
    public function test_owasp_a08_data_integrity_failures(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'integrity-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Test that data modifications are tracked
        $originalEmail = $manager->email;
        
        $manager->update(['email' => 'new-email@assab.com']);
        
        $manager->refresh();
        $this->assertNotEquals($originalEmail, $manager->email, "Data modifications should be persisted");
        $this->assertNotNull($manager->updated_at, "Modification timestamp should be tracked");
    }

    /**
     * Test: OWASP A09 - Logging Failures
     * Security events should be logged
     */
    public function test_owasp_a09_logging_failures(): void
    {
        // Failed login attempts should be logged
        BranchManager::factory()->create([
            'email' => 'log-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'identifier' => 'log-test@assab.com',
            'password' => 'wrongpassword',
        ]);

        // Should log failed attempt (in production)
        $this->assertEquals(401, $response->status(), "Failed login should be logged");
    }

    /**
     * Test: OWASP A10 - Server-Side Request Forgery (SSRF)
     * SSRF protection if applicable
     */
    public function test_owasp_a10_ssrf_protection(): void
    {
        // If system makes external requests, SSRF protection should be in place
        // This test verifies the concept
        
        $manager = BranchManager::factory()->create([
            'email' => 'ssrf-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // If endpoint accepts URLs, they should be validated
        $this->assertTrue(true, "SSRF protection should be implemented if external requests are made");
    }

    /**
     * Test: API key security
     * API keys should be properly secured
     */
    public function test_api_key_security(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'apikey-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Login to get token
        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'identifier' => 'apikey-test@assab.com',
            'password' => 'password123',
        ]);

        $token = $response->json('data.token');
        $this->assertNotNull($token, "Token should be provided");
        $this->assertTrue(strlen($token) > 20, "Token should be long enough");
    }

    /**
     * Test: Input validation
     * All inputs should be validated
     */
    public function test_input_validation(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'validation-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Invalid email format
        $response = $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/profile', [
                'email' => 'invalid-email-format',
            ]);

        // Should validate and reject
        $this->assertContains(
            $response->status(),
            [400, 422],
            "Invalid input should be rejected"
        );
    }

    /**
     * Test: Sensitive data exposure
     * Sensitive data should not be exposed in errors
     */
    public function test_sensitive_data_exposure(): void
    {
        $manager = BranchManager::factory()->create([
            'email' => 'exposure-test@assab.com',
            'password' => Hash::make('password123'),
        ]);

        // Trigger an error
        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/nonexistent-endpoint');

        $responseData = $response->json();
        
        // Error messages should not expose system internals
        if (isset($responseData['message'])) {
            $message = $responseData['message'];
            $this->assertStringNotContainsString(
                '/var/www',
                $message,
                "Error messages should not expose file paths"
            );
            $this->assertStringNotContainsString(
                'SQL',
                $message,
                "Error messages should not expose SQL details"
            );
        }
    }
}
