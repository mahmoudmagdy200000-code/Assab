<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Tests\TestCase;

/**
 * The mobile «Welcome to Assab» verify/login screens POST the account value as
 * `email` (labelled «Registered»), but the auth requests required `identifier`
 * — a hard "The identifier field is required" 422 on a filled field. The
 * NormalizesIdentifier trait folds the alias onto `identifier`; these lock that.
 */
class BranchManagerLoginIdentifierAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_accepts_the_email_alias_the_app_sends(): void
    {
        BranchManager::factory()->firstLogin()->create([
            'email' => 'verify@assab.com',
            'password' => Hash::make('default-pass-1'),
        ]);

        $this->postJson('/api/v1/branch-manager/auth/first-login', [
            'email' => 'verify@assab.com',        // NOT `identifier`
            'password' => 'default-pass-1',
            // false since 2026-07-26: the sign-in itself completes activation.
        ])->assertOk()->assertJsonPath('data.requires_password_reset', false);
    }

    public function test_login_accepts_the_email_alias_the_app_sends(): void
    {
        BranchManager::factory()->create([
            'email' => 'active@assab.com',
            'password' => Hash::make('secret-pass-1'),
            'is_first_login' => false,
        ]);

        $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'active@assab.com',
            'password' => 'secret-pass-1',
        ])->assertOk();
    }

    public function test_explicit_identifier_is_still_accepted_unchanged(): void
    {
        BranchManager::factory()->create([
            'email' => 'both@assab.com',
            'password' => Hash::make('secret-pass-2'),
            'is_first_login' => false,
        ]);

        $this->postJson('/api/v1/branch-manager/auth/login', [
            'identifier' => 'both@assab.com',
            'password' => 'secret-pass-2',
        ])->assertOk();
    }
}
