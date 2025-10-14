<?php

namespace Modules\BranchManagers\Services;
use Modules\BranchManagers\Models\BranchManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class ProfileService
{
    /**
     * Get branch manager profile
     */
    public function getProfile(int $managerId): BranchManager
    {
        return BranchManager::with(['branch', 'cashiers'])
            ->findOrFail($managerId);
    }

    /**
     * Update branch manager profile
     */
    public function updateProfile(BranchManager $manager, array $data): BranchManager
    {
        $manager->update([
            'name' => $data['name'] ?? $manager->name,
            'phone' => $data['phone'] ?? $manager->phone,
        ]);

        return $manager->fresh(['branch']);
    }

    /**
     * Upload profile image
     */
    public function uploadProfileImage(BranchManager $manager, UploadedFile $image): string
    {
        // Delete old image if exists
        if ($manager->image) {
            Storage::disk('public')->delete($manager->image);
        }

        // Store new image
        $filename = 'manager_' . $manager->id . '_' . time() . '.' . $image->getClientOriginalExtension();
        $path = $image->storeAs('profiles/managers', $filename, 'public');

        // Update manager record
        $manager->update(['image' => $path]);

        return $path;
    }

    /**
     * Delete profile image
     */
    public function deleteProfileImage(BranchManager $manager): void
    {
        if ($manager->image) {
            Storage::disk('public')->delete($manager->image);
            $manager->update(['image' => null]);
        }
    }

    /**
     * Change password
     */
    public function changePassword(BranchManager $manager, string $currentPassword, string $newPassword): void
    {
        // Verify current password
        if (!Hash::check($currentPassword, $manager->password)) {
            throw new \Exception('Current password is incorrect');
        }

        // Update password
        $manager->update([
            'password' => Hash::make($newPassword),
        ]);

        // Revoke all tokens except current
        $currentToken = $manager->currentAccessToken();
        $manager->tokens()->where('id', '!=', $currentToken->id)->delete();
    }
}
