<?php

namespace Modules\BranchManagers\Tests\Unit\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\BranchManagers\Models\BranchManager;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = BranchManager::factory()->create();
    }

    /** @test */
    public function branch_manager_can_view_profile()
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'branch',
                    'statistics',
                ],
            ]);
    }

    /** @test */
    public function branch_manager_can_update_profile()
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/profile', [
                'name' => 'Updated Name',
                'phone' => '+966500000099',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('branch_managers', [
            'id' => $this->manager->id,
            'name' => 'Updated Name',
            'phone' => '+966500000099',
        ]);
    }

    /** @test */
    public function branch_manager_can_upload_profile_image()
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('profile.jpg');

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/profile/image', [
                'image' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'image_url',
                ],
            ]);

        Storage::disk('public')->assertExists('profiles/managers/'.$file->hashName());
    }

    /** @test */
    public function branch_manager_can_change_password()
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/profile/change-password', [
                'current_password' => 'password123',
                'new_password' => 'NewPassword123',
                'new_password_confirmation' => 'NewPassword123',
            ]);

        $response->assertStatus(200);

        $this->manager->refresh();
        $this->assertTrue(
            Hash::check('NewPassword123', $this->manager->password)
        );
    }
}
