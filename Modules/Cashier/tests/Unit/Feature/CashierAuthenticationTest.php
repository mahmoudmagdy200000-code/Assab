<?php

namespace Modules\Cashier\Tests\Unit\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cashier\Models\Cashier;
use Illuminate\Support\Facades\Hash;

class CashierAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cashier_can_login_with_valid_credentials()
    {
        $cashier = Cashier::factory()->active()->create([
            'email' => 'cashier@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/cashier/auth/login', [
            'email' => 'cashier@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'cashier',
                    'token',
                    'token_type',
                ]
            ]);
    }

    /** @test */
    public function cashier_cannot_login_with_invalid_credentials()
    {
        $cashier = Cashier::factory()->active()->create([
            'email' => 'cashier@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/cashier/auth/login', [
            'email' => 'cashier@assab.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    }

    /** @test */
    public function pending_cashier_cannot_login()
    {
        $cashier = Cashier::factory()->pending()->create([
            'email' => 'cashier@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/cashier/auth/login', [
            'email' => 'cashier@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function deactivated_cashier_cannot_login()
    {
        $cashier = Cashier::factory()->deactivated()->create([
            'email' => 'cashier@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/cashier/auth/login', [
            'email' => 'cashier@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function cashier_can_logout()
    {
        $cashier = Cashier::factory()->active()->create();

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/cashier/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
    }
}
