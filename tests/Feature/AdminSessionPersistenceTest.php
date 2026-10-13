<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Tests\TestCase;

/**
 * Slice 01 — Additive Admin Session Persistence Tests.
 *
 * Validates non-operational additive schema, Eloquent model, UUID semantics,
 * D09 legacy alias stability, and unique constraints under SQLite.
 */
class AdminSessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sessions_table_is_created_by_module_migration(): void
    {
        $this->assertTrue(Schema::hasTable('admin_sessions'), 'admin_sessions table must be created by module migration');

        $expectedColumns = [
            'id',
            'user_id',
            'company_id',
            'device_name',
            'ip_address',
            'user_agent',
            'is_remember_me',
            'generation',
            'current_access_token_id',
            'current_refresh_token_id',
            'legacy_access_token_id',
            'last_active_at',
            'expires_at',
            'revoked_at',
            'created_at',
            'updated_at',
        ];

        $this->assertTrue(
            Schema::hasColumns('admin_sessions', $expectedColumns),
            'admin_sessions must define all expected columns from the blueprint DDL'
        );
    }

    public function test_uuid_user_and_nullable_company_permit_platform_admin_session_persistence(): void
    {
        $user = AsabUser::create([
            'name' => 'Platform Superadmin',
            'email' => 'superadmin@assab.test',
            'password' => 'secret123',
            'status' => 'active',
            'company_id' => null,
        ]);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'company_id' => null,
            'device_name' => 'Desktop Chrome',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Test Browser',
            'expires_at' => now()->addHours(24),
        ]);

        $this->assertNotEmpty($session->id);
        $this->assertTrue(Str::isUuid($session->id), 'AdminSession primary key must be a valid UUID');
        $this->assertNull($session->company_id, 'Platform admin session must allow NULL company_id');
        $this->assertSame($user->id, $session->user->id);
        $this->assertNull($session->company);
    }

    public function test_admin_session_persists_with_linked_company_uuid(): void
    {
        $company = AsabCompany::create([
            'name' => 'Test Company LLC',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $user = AsabUser::create([
            'name' => 'Company Admin User',
            'email' => 'company.admin@assab.test',
            'password' => 'secret123',
            'status' => 'active',
            'company_id' => $company->id,
        ]);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'device_name' => 'Admin Dashboard Web',
            'expires_at' => now()->addHours(12),
        ]);

        $this->assertSame($user->id, $session->user->id);
        $this->assertNotNull($session->company);
        $this->assertSame($company->id, $session->company->id);
    }

    public function test_generation_starts_at_zero_and_dates_round_trip(): void
    {
        $user = AsabUser::create([
            'name' => 'User Timing Test',
            'email' => 'timing@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $expiresAt = Carbon::create(2026, 10, 13, 14, 0, 0);
        $lastActiveAt = Carbon::create(2026, 10, 12, 14, 0, 0);
        $revokedAt = Carbon::create(2026, 10, 12, 18, 30, 0);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'expires_at' => $expiresAt,
            'last_active_at' => $lastActiveAt,
            'revoked_at' => $revokedAt,
        ]);

        $fresh = $session->fresh();

        $this->assertSame(0, $fresh->generation, 'Default rotation generation must be 0');
        $this->assertFalse($fresh->is_remember_me, 'Default is_remember_me must be false');
        $this->assertSame($expiresAt->toDateTimeString(), $fresh->expires_at->toDateTimeString());
        $this->assertSame($lastActiveAt->toDateTimeString(), $fresh->last_active_at->toDateTimeString());
        $this->assertSame($revokedAt->toDateTimeString(), $fresh->revoked_at->toDateTimeString());
        $this->assertTrue($fresh->isRevoked());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_expiration_boundary_and_revocation_lifecycle(): void
    {
        $user = AsabUser::create([
            'name' => 'Boundary User',
            'email' => 'boundary@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $baseTime = Carbon::create(2026, 10, 12, 12, 0, 0);
        $expiresAt = Carbon::create(2026, 10, 13, 12, 0, 0);

        Carbon::setTestNow($baseTime);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);

        // 1. Before expiration (now < expires_at)
        Carbon::setTestNow($expiresAt->copy()->subSecond());
        $this->assertTrue($session->isActive(), 'Session must be active before expires_at');
        $this->assertFalse($session->isExpired(), 'Session must not be expired before expires_at');
        $this->assertFalse($session->isRevoked());

        // 2. Exactly at expiration boundary (now == expires_at)
        // Baseline requirement: active only when revoked_at IS NULL AND now < expires_at
        Carbon::setTestNow($expiresAt);
        $this->assertFalse($session->isActive(), 'Session must NOT be active when now == expires_at (strictly now < expires_at)');
        $this->assertTrue($session->isExpired(), 'Session is expired when now == expires_at');
        $this->assertFalse($session->isRevoked());

        // 3. After expiration (now > expires_at)
        Carbon::setTestNow($expiresAt->copy()->addSecond());
        $this->assertFalse($session->isActive(), 'Session must NOT be active after expires_at');
        $this->assertTrue($session->isExpired(), 'Session is expired after expires_at');
        $this->assertFalse($session->isRevoked());

        // 4. Revoked session before expiry
        Carbon::setTestNow($baseTime);
        $session->update(['revoked_at' => $baseTime]);
        $this->assertTrue($session->isRevoked());
        $this->assertFalse($session->isActive(), 'Revoked session must NOT be active even if now < expires_at');
    }

    public function test_legacy_access_token_id_alias_can_be_null_or_uniquely_assigned(): void
    {
        $user = AsabUser::create([
            'name' => 'User Alias Nullable',
            'email' => 'alias_null@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        // Multiple sessions can have null legacy_access_token_id
        $session1 = AdminSession::create([
            'user_id' => $user->id,
            'legacy_access_token_id' => null,
            'expires_at' => now()->addHours(24),
        ]);

        $session2 = AdminSession::create([
            'user_id' => $user->id,
            'legacy_access_token_id' => null,
            'expires_at' => now()->addHours(24),
        ]);

        $this->assertNull($session1->legacy_access_token_id);
        $this->assertNull($session2->legacy_access_token_id);

        // A unique numeric alias can be assigned
        $sessionWithAlias = AdminSession::create([
            'user_id' => $user->id,
            'legacy_access_token_id' => 1001,
            'expires_at' => now()->addHours(24),
        ]);

        $this->assertSame(1001, $sessionWithAlias->fresh()->legacy_access_token_id);
    }

    public function test_legacy_access_token_id_persists_unchanged_when_current_token_pointers_and_generation_change(): void
    {
        $user = AsabUser::create([
            'name' => 'User Rotation Simulation',
            'email' => 'rotation_sim@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'current_access_token_id' => 1001,
            'current_refresh_token_id' => 1002,
            'legacy_access_token_id' => 1001,
            'generation' => 0,
            'expires_at' => now()->addHours(24),
        ]);

        // Schema-level rotation simulation: pointers and generation advance
        $session->update([
            'current_access_token_id' => 2001,
            'current_refresh_token_id' => 2002,
            'generation' => 1,
        ]);

        $fresh = $session->fresh();

        $this->assertSame(2001, $fresh->current_access_token_id);
        $this->assertSame(2002, $fresh->current_refresh_token_id);
        $this->assertSame(1, $fresh->generation);
        // D09 requirement: legacy_access_token_id remains completely unchanged
        $this->assertSame(1001, $fresh->legacy_access_token_id, 'legacy_access_token_id must remain immutable after pointer updates');
    }

    public function test_duplicate_non_null_numeric_aliases_fail_unique_constraint(): void
    {
        $user = AsabUser::create([
            'name' => 'User Duplicate Alias',
            'email' => 'duplicate_alias@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        AdminSession::create([
            'user_id' => $user->id,
            'legacy_access_token_id' => 5005,
            'expires_at' => now()->addHours(24),
        ]);

        $this->expectException(QueryException::class);

        AdminSession::create([
            'user_id' => $user->id,
            'legacy_access_token_id' => 5005,
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function test_existing_personal_access_tokens_unaffected_by_admin_sessions(): void
    {
        $user = AsabUser::create([
            'name' => 'Sanctum User',
            'email' => 'sanctum@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $token = $user->createToken('standard-device-token');
        $this->assertNotNull($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
            'name' => 'standard-device-token',
            'tokenable_id' => $user->id,
        ]);

        // Forward-compatible isolation: if session_id column exists (Slice 02+), standard token has NULL session_id.
        if (Schema::hasColumn('personal_access_tokens', 'session_id')) {
            $this->assertNull($token->accessToken->fresh()->session_id);
        }
    }
}
