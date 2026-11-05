<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

class MobileAuthPhoneNormalizationAndDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_stored_as_plus_9665_can_login_with_all_phone_formats()
    {
        $manager = BranchManager::factory()->create([
            'email' => 'bm.phone.test@fixture.invalid',
            'phone' => '+966512345678',
            'password' => 'SecretPass123!',
            'is_first_login' => false,
            'is_active' => true,
        ]);

        $formats = [
            '512345678',
            '0512345678',
            '966512345678',
            '00966512345678',
            '+966512345678',
            '051 234-5678',
            '+966 51 234 5678',
        ];

        foreach ($formats as $phoneInput) {
            $response = $this->postJson('/api/v1/branch-manager/auth/login', [
                'identifier' => $phoneInput,
                'password' => 'SecretPass123!',
            ]);

            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.user.id', $manager->id);
        }
    }

    public function test_cashier_stored_as_plus_9665_can_login_with_all_phone_formats()
    {
        $cashier = Cashier::factory()->create([
            'email' => 'cashier.phone.test@fixture.invalid',
            'phone' => '+966598765432',
            'password' => 'SecretPass123!',
            'status' => 'active',
        ]);

        $formats = [
            '598765432',
            '0598765432',
            '966598765432',
            '00966598765432',
            '+966598765432',
            '059 876-5432',
            '+966 59 876 5432',
        ];

        foreach ($formats as $phoneInput) {
            $response = $this->postJson('/api/v1/cashier/auth/login', [
                'identifier' => $phoneInput,
                'password' => 'SecretPass123!',
            ]);

            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.user.id', $cashier->id);
        }
    }

    public function test_branch_manager_phone_ambiguity_fails_closed_with_invalid_credentials()
    {
        $branch1 = \Modules\Branch\Models\Branch::factory()->create();
        $branch2 = \Modules\Branch\Models\Branch::factory()->create();

        // Two managers in different branches with different stored phone variants that resolve to same canonical
        BranchManager::factory()->create([
            'branch_id' => $branch1->id,
            'email' => 'bm.collision.a@fixture.invalid',
            'phone' => '+966511111111',
            'password' => 'SecretPass123!',
            'is_first_login' => false,
            'is_active' => true,
        ]);

        BranchManager::factory()->create([
            'branch_id' => $branch2->id,
            'email' => 'bm.collision.b@fixture.invalid',
            'phone' => '0511111111',
            'password' => 'SecretPass123!',
            'is_first_login' => false,
            'is_active' => true,
        ]);

        // When logging in with local subscriber format '5111111111', both match whereIn candidates
        $response = $this->postJson('/api/v1/branch-manager/auth/login', [
            'identifier' => '5111111111',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_cashier_phone_ambiguity_fails_closed_with_invalid_credentials()
    {
        $branch1 = \Modules\Branch\Models\Branch::factory()->create();
        $branch2 = \Modules\Branch\Models\Branch::factory()->create();

        Cashier::factory()->create([
            'branch_id' => $branch1->id,
            'email' => 'cashier.collision.a@fixture.invalid',
            'phone' => '+966522222222',
            'password' => 'SecretPass123!',
            'status' => 'active',
        ]);

        Cashier::factory()->create([
            'branch_id' => $branch2->id,
            'email' => 'cashier.collision.b@fixture.invalid',
            'phone' => '0522222222',
            'password' => 'SecretPass123!',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => '5222222222',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials or inactive account.');
    }

    public function test_failed_login_logs_sanitized_reason_without_identifiers_or_passwords()
    {
        $loggedLines = [];
        Log::listen(function ($event) use (&$loggedLines) {
            if ($event instanceof \Illuminate\Log\Events\MessageLogged) {
                $loggedLines[] = [
                    'level' => $event->level,
                    'message' => $event->message,
                    'context' => $event->context,
                ];
            }
        });

        // 1. Unknown user
        $response1 = $this->withServerVariables(['REMOTE_ADDR' => '102.42.42.97'])
            ->withHeaders(['X-Request-Id' => 'req-test-uuid-001'])
            ->postJson('/api/v1/branch-manager/auth/login', [
                'identifier' => 'nonexistent@fixture.invalid',
                'password' => 'SecretPass123!',
            ]);
        $response1->assertStatus(401);

        // 2. Bad password
        $manager = BranchManager::factory()->create([
            'email' => 'bm.badpass@fixture.invalid',
            'password' => 'CorrectPass123!',
            'is_first_login' => false,
            'is_active' => true,
        ]);

        $response2 = $this->withServerVariables(['REMOTE_ADDR' => '102.42.42.97'])
            ->withHeaders(['X-Request-Id' => 'req-test-uuid-002'])
            ->postJson('/api/v1/branch-manager/auth/login', [
                'identifier' => 'bm.badpass@fixture.invalid',
                'password' => 'WrongPass999!',
            ]);
        $response2->assertStatus(401);

        // 3. Succeeded login
        $response3 = $this->withHeaders(['X-Request-Id' => 'req-test-uuid-003'])
            ->postJson('/api/v1/branch-manager/auth/login', [
                'identifier' => 'bm.badpass@fixture.invalid',
                'password' => 'CorrectPass123!',
            ]);
        $response3->assertStatus(200);

        // Assert all logged lines
        $authLines = array_values(array_filter($loggedLines, function ($entry) {
            return str_contains($entry['message'] ?? '', 'mobile_auth');
        }));

        $this->assertNotEmpty($authLines);

        foreach ($authLines as $entry) {
            $context = $entry['context'] ?? [];
            $json = json_encode($context);

            // Never log identifier or password
            $this->assertStringNotContainsString('nonexistent@fixture.invalid', $json);
            $this->assertStringNotContainsString('bm.badpass@fixture.invalid', $json);
            $this->assertStringNotContainsString('SecretPass123!', $json);
            $this->assertStringNotContainsString('WrongPass999!', $json);
            $this->assertStringNotContainsString('CorrectPass123!', $json);

            // Context should contain sanitized fields
            if ($entry['message'] === 'mobile_auth_failed') {
                $this->assertArrayHasKey('surface', $context);
                $this->assertArrayHasKey('reason', $context);
                $this->assertArrayHasKey('request_id', $context);
                $this->assertArrayHasKey('ip_prefix', $context);
                $this->assertArrayHasKey('timestamp', $context);
                $this->assertEquals('102.42.42.0/24', $context['ip_prefix']);
            } elseif ($entry['message'] === 'mobile_auth_succeeded') {
                $this->assertArrayHasKey('surface', $context);
                $this->assertArrayHasKey('request_id', $context);
            }
        }
    }
}
