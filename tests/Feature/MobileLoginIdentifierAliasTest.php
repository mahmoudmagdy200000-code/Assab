<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * The mobile «Welcome to Assab» screens POST the account value as `email`
 * (labelled «Registered»), not `identifier`. The alias fold was added to the
 * branch-manager requests only, so a SUPPLIER created from the dashboard still
 * hit a hard "The identifier field is required" 422 on a filled field.
 *
 * The trait now lives in the app (App\Http\Requests\Concerns) and every
 * identifier-taking auth request uses it — the sweep at the bottom is what keeps
 * a newly added auth request from re-opening the same hole.
 */
class MobileLoginIdentifierAliasTest extends TestCase
{
    use RefreshDatabase;

    /** Modules\Supplier\Models\Supplier ships no factory — build the row. */
    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Dashboard Supplier',
            'email' => 'sup-'.uniqid().'@assab.com',
            'phone' => '05'.random_int(10000000, 99999999),
            'password' => bcrypt('default_password'),
            'is_active' => true,
            'is_first_login' => true,
        ], $attributes));
    }

    public function test_supplier_first_login_accepts_the_email_alias(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/first-login', [
            'email' => $supplier->email,          // NOT `identifier`
            'password' => 'default_password',
        ])->assertStatus(200)->assertJsonPath('data.requires_password_reset', true);
    }

    public function test_supplier_login_accepts_the_phone_alias(): void
    {
        $supplier = $this->supplier([
            'is_first_login' => false,
            'phone' => '0551234567',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/v1/supplier/auth/login', [
            'phone' => $supplier->phone,
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_explicit_identifier_still_wins(): void
    {
        $supplier = $this->supplier([
            'is_first_login' => false,
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/v1/supplier/auth/login', [
            'identifier' => $supplier->email,
            'email' => 'someone-else@assab.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_missing_account_field_still_fails_validation(): void
    {
        $this->postJson('/api/v1/supplier/auth/login', ['password' => 'password123'])
            ->assertStatus(422);
    }

    /**
     * Every mobile auth request that requires `identifier` must fold the alias,
     * or the app's «Registered» field 422s on that one screen only.
     */
    public function test_every_identifier_request_normalizes_the_alias(): void
    {
        $missing = [];
        foreach (['Supplier', 'BrandOwner', 'Cashier', 'BranchManagers'] as $module) {
            foreach (glob(base_path("Modules/{$module}/app/Http/Requests/**/*.php")) + glob(base_path("Modules/{$module}/app/Http/Requests/*.php")) as $file) {
                $body = file_get_contents($file);
                if (str_contains($body, "'identifier' => 'required") && ! str_contains($body, 'NormalizesIdentifier')) {
                    $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame([], $missing, 'Auth requests requiring `identifier` without the alias fold: '.implode(', ', $missing));
    }
}
