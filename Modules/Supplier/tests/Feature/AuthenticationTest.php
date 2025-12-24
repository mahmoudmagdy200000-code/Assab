<?php

namespace Modules\Supplier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test first login
     */
    public function test_first_login_requires_password_reset(): void
    {
        $supplier = Supplier::factory()->create([
            'is_first_login' => true,
            'password' => bcrypt('default_password'),
        ]);

        $response = $this->postJson('/api/v1/supplier/auth/first-login', [
            'identifier' => $supplier->email,
            'password' => 'default_password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'supplier',
                    'token',
                    'requires_password_reset',
                ],
            ]);
    }

    /**
     * Test regular login
     */
    public function test_supplier_can_login(): void
    {
        $supplier = Supplier::factory()->create([
            'is_first_login' => false,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/supplier/auth/login', [
            'identifier' => $supplier->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'supplier',
                    'token',
                ],
            ]);
    }

    /**
     * Test password reset flow
     */
    public function test_password_reset_flow(): void
    {
        $supplier = Supplier::factory()->create();

        // Send OTP
        $response = $this->postJson('/api/v1/supplier/auth/password/reset/send-otp', [
            'identifier' => $supplier->email,
            'type' => 'email',
        ]);

        $response->assertStatus(200);

        // Note: OTP verification and reset would require mocking OTP service
    }
}

