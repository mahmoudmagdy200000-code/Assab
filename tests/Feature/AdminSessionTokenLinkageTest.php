<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * Slice 02 — Additive Sanctum Token-to-Admin-Session Linkage Tests.
 *
 * Validates additive migration on shared personal_access_tokens table,
 * nullability for legacy mobile tokens, family linkage, and rollback isolation.
 */
class AdminSessionTokenLinkageTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_session_id_column_and_index_to_personal_access_tokens(): void
    {
        $this->assertTrue(
            Schema::hasColumn('personal_access_tokens', 'session_id'),
            'personal_access_tokens must have session_id column'
        );

        // Verify index existence across databases (SQLite, MySQL, MariaDB)
        $indices = collect(Schema::getIndexes('personal_access_tokens'))
            ->pluck('name')
            ->all();

        $this->assertContains(
            'idx_pat_session_id',
            $indices,
            'personal_access_tokens must have idx_pat_session_id index'
        );
    }

    public function test_standard_legacy_tokens_keep_session_id_null_by_default(): void
    {
        $user = AsabUser::create([
            'name' => 'Mobile Cashier',
            'email' => 'cashier@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $newAccessToken = $user->createToken('cashier-pos-device');

        $this->assertNotNull($newAccessToken->plainTextToken);
        $this->assertNull(
            $newAccessToken->accessToken->session_id,
            'Legacy mobile tokens must keep session_id as NULL'
        );

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $newAccessToken->accessToken->id,
            'name' => 'cashier-pos-device',
            'session_id' => null,
        ]);
    }

    public function test_token_can_be_explicitly_linked_to_admin_session_and_queried(): void
    {
        $user = AsabUser::create([
            'name' => 'Admin User',
            'email' => 'admin@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'device_name' => 'Admin Desktop',
            'expires_at' => now()->addHours(24),
        ]);

        $tokenResult = $user->createToken('admin-access-token');
        $token = $tokenResult->accessToken;

        // In test scope: explicitly link token to session family
        $token->forceFill(['session_id' => $session->id])->save();

        // Query by session_id
        $queriedToken = PersonalAccessToken::where('session_id', $session->id)->first();
        $this->assertNotNull($queriedToken);
        $this->assertSame($token->id, $queriedToken->id);

        // Query via AdminSession relationship
        $this->assertTrue($session->tokens->contains($token));
        $this->assertSame(1, $session->tokens()->count());
        $this->assertSame($token->id, $session->tokens->first()->id);
    }

    public function test_multiple_tokens_can_be_associated_with_same_family_without_affecting_unbound_tokens(): void
    {
        $user = AsabUser::create([
            'name' => 'Multi-token Admin',
            'email' => 'multitoken@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $session = AdminSession::create([
            'user_id' => $user->id,
            'expires_at' => now()->addHours(24),
        ]);

        // Token 1: Access token in family
        $accessToken = $user->createToken('admin-family-access')->accessToken;
        $accessToken->forceFill(['session_id' => $session->id])->save();

        // Token 2: Refresh token in family
        $refreshToken = $user->createToken('admin-family-refresh')->accessToken;
        $refreshToken->forceFill(['session_id' => $session->id])->save();

        // Token 3: Unrelated mobile token
        $unboundToken = $user->createToken('unrelated-mobile-token')->accessToken;

        $familyTokens = $session->tokens()->pluck('id')->all();

        $this->assertCount(2, $familyTokens);
        $this->assertContains($accessToken->id, $familyTokens);
        $this->assertContains($refreshToken->id, $familyTokens);
        $this->assertNotContains($unboundToken->id, $familyTokens);

        // Unbound token remains session_id NULL (no backfill)
        $this->assertNull($unboundToken->fresh()->session_id);
    }

    public function test_migration_rollback_and_reapply_against_sqlite_database(): void
    {
        $migration = require database_path('migrations/2026_10_12_000002_add_session_id_to_personal_access_tokens.php');

        // Verify initial state: column exists
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'session_id'));

        // Rollback
        $migration->down();
        $this->assertFalse(
            Schema::hasColumn('personal_access_tokens', 'session_id'),
            'Down migration must remove session_id column'
        );

        // Reapply
        $migration->up();
        $this->assertTrue(
            Schema::hasColumn('personal_access_tokens', 'session_id'),
            'Up migration must restore session_id column'
        );
    }

    public function test_existing_token_rows_survive_migration_rollback_and_reapply(): void
    {
        $user = AsabUser::create([
            'name' => 'Pre-existing Token User',
            'email' => 'preexisting@assab.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $expiresAt = now()->addDays(30)->startOfSecond();
        $token = $user->createToken('pre-existing-token', ['read', 'write'], $expiresAt);
        $pat = $token->accessToken;

        $originalId = $pat->id;
        $originalTokenHash = $pat->token;
        $originalTokenableType = $pat->tokenable_type;
        $originalTokenableId = $pat->tokenable_id;
        $originalAbilities = $pat->abilities;
        $originalExpiresAt = $pat->expires_at->toDateTimeString();

        $migration = require database_path('migrations/2026_10_12_000002_add_session_id_to_personal_access_tokens.php');

        // Rollback migration
        $migration->down();

        // Query row directly from DB table after rollback
        $rowAfterDown = DB::table('personal_access_tokens')->where('id', $originalId)->first();
        $this->assertNotNull($rowAfterDown, 'Token row must survive rollback');
        $this->assertSame($originalTokenHash, $rowAfterDown->token);
        $this->assertSame($originalTokenableType, $rowAfterDown->tokenable_type);
        $this->assertSame($originalTokenableId, $rowAfterDown->tokenable_id);
        $this->assertSame(json_encode($originalAbilities), $rowAfterDown->abilities);
        $this->assertSame($originalExpiresAt, Carbon::parse($rowAfterDown->expires_at)->toDateTimeString());
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'session_id'));

        // Reapply migration
        $migration->up();

        // Query row directly from DB table after reapply
        $rowAfterUp = DB::table('personal_access_tokens')->where('id', $originalId)->first();
        $this->assertNotNull($rowAfterUp, 'Token row must survive reapply');
        $this->assertSame($originalTokenHash, $rowAfterUp->token);
        $this->assertSame($originalTokenableType, $rowAfterUp->tokenable_type);
        $this->assertSame($originalTokenableId, $rowAfterUp->tokenable_id);
        $this->assertSame(json_encode($originalAbilities), $rowAfterUp->abilities);
        $this->assertSame($originalExpiresAt, Carbon::parse($rowAfterUp->expires_at)->toDateTimeString());
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'session_id'));
        $this->assertNull($rowAfterUp->session_id, 'Pre-existing token session_id must be NULL upon reapply');

        // Verify index is restored
        $indices = collect(Schema::getIndexes('personal_access_tokens'))
            ->pluck('name')
            ->all();
        $this->assertContains('idx_pat_session_id', $indices);
    }

    public function test_real_cashier_model_sanctum_token_operates_with_null_session_id(): void
    {
        $branch = Branch::create([
            'name' => 'POS Branch Test',
            'location' => 'Riyadh',
        ]);

        $manager = BranchManager::create([
            'name' => 'Branch Manager Creator',
            'email' => 'creator@chain.test',
            'phone' => '0501112233',
            'password' => 'secret123',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $cashier = Cashier::create([
            'name' => 'Real Cashier User',
            'email' => 'real.cashier@chain.test',
            'phone' => '0511112233',
            'password' => 'secret123',
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => 'active',
        ]);

        $tokenResult = $cashier->createToken('cashier-pos-token');
        $this->assertNotNull($tokenResult->plainTextToken);

        $pat = $tokenResult->accessToken;
        $this->assertSame($cashier->getMorphClass(), $pat->tokenable_type);
        $this->assertSame($cashier->id, $pat->tokenable_id);
        $this->assertNull($pat->session_id, 'Real Cashier token must keep session_id as NULL');

        // Verify Sanctum token lookup resolves real Cashier model
        $resolved = PersonalAccessToken::findToken($tokenResult->plainTextToken);
        $this->assertNotNull($resolved);
        $this->assertInstanceOf(Cashier::class, $resolved->tokenable);
        $this->assertSame($cashier->id, $resolved->tokenable->id);
    }

    public function test_real_branch_manager_model_sanctum_token_operates_with_null_session_id(): void
    {
        $branch = Branch::create([
            'name' => 'Manager Branch Test',
            'location' => 'Jeddah',
        ]);

        $manager = BranchManager::create([
            'name' => 'Real Branch Manager',
            'email' => 'real.manager@chain.test',
            'phone' => '0522223344',
            'password' => 'secret123',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $tokenResult = $manager->createToken('manager-mobile-token');
        $this->assertNotNull($tokenResult->plainTextToken);

        $pat = $tokenResult->accessToken;
        $this->assertSame($manager->getMorphClass(), $pat->tokenable_type);
        $this->assertSame($manager->id, $pat->tokenable_id);
        $this->assertNull($pat->session_id, 'Real BranchManager token must keep session_id as NULL');

        // Verify Sanctum token lookup resolves real BranchManager model
        $resolved = PersonalAccessToken::findToken($tokenResult->plainTextToken);
        $this->assertNotNull($resolved);
        $this->assertInstanceOf(BranchManager::class, $resolved->tokenable);
        $this->assertSame($manager->id, $resolved->tokenable->id);
    }
}
