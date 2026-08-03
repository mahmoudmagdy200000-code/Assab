<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * «قائمة يرفع تقريره إلى — الأسامي غير واضحة» (2026-08-03). The picker had a
 * name and a raw English role key to work with, and nothing to tell two people
 * with the same name apart.
 */
class AdminUserLookupLabelTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@lookup.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
    }

    private function user(string $name, string $email, string $role, string $status = 'active'): AsabUser
    {
        $u = AsabUser::create([
            'name' => $name, 'email' => $email, 'password' => 'secret-password', 'status' => $status,
        ]);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all']);

        return $u;
    }

    public function test_the_head_picker_gets_a_ready_to_render_label(): void
    {
        $this->user('حسن رشدي', 'hasan@lookup.test', 'head');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/lookups/users?role=head')
            ->assertStatus(200);

        $row = collect($res->json('data'))->firstWhere('email', 'hasan@lookup.test');
        $this->assertSame('حسن رشدي — رئيس حسابات', $row['label']);
        $this->assertSame('رئيس حسابات', $row['roleLabel']);
        // The old shape is untouched for existing consumers.
        $this->assertSame('head', $row['role']);
    }

    public function test_a_nameless_invite_falls_back_to_its_email_instead_of_a_blank_option(): void
    {
        $this->user('', 'pending@lookup.test', 'head');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/lookups/users?role=head')
            ->assertStatus(200);

        $row = collect($res->json('data'))->firstWhere('email', 'pending@lookup.test');
        $this->assertSame('pending@lookup.test — رئيس حسابات', $row['label']);
    }

    public function test_deactivated_accounts_can_be_filtered_out_of_the_picker(): void
    {
        $this->user('نشط', 'active@lookup.test', 'head');
        $this->user('موقوف', 'inactive@lookup.test', 'head', 'inactive');

        $emails = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/lookups/users?role=head&status=active')
            ->assertStatus(200)
            ->json('data'))->pluck('email');

        $this->assertContains('active@lookup.test', $emails);
        $this->assertNotContains('inactive@lookup.test', $emails);
    }
}
