<?php

namespace Modules\BranchManagers\Tests\Unit\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function branch_manager_can_login_with_valid_credentials()
    {
        $manager = BranchManager::factory()->create([
            'email' => 'manager@assab.com',
            'password' => Hash::make('password123'),
            'is_first_login' => false,
        ]);

        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'manager@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'manager',
                    'token',
                    'token_type',
                ],
            ]);
    }

    /** @test */
    public function branch_manager_cannot_login_with_invalid_credentials()
    {
        $manager = BranchManager::factory()->create([
            'email' => 'manager@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'manager@assab.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    }

    /** @test */
    public function first_login_manager_must_reset_password()
    {
        $manager = BranchManager::factory()->firstLogin()->create([
            'email' => 'manager@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'manager@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'first_login' => true,
                ],
            ]);
    }

    /** @test */
    public function inactive_manager_cannot_login()
    {
        $manager = BranchManager::factory()->inactive()->create([
            'email' => 'manager@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'manager@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function suspended_manager_cannot_login()
    {
        $manager = BranchManager::factory()->suspended()->create([
            'email' => 'manager@assab.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'manager@assab.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function branch_manager_can_logout()
    {
        $manager = BranchManager::factory()->create();

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
    }
}
