<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\Supplier\Http\Requests\Settings\UpdateAccountSettingsRequest;
use Modules\Supplier\Http\Requests\Settings\UpdateNotificationSettingsRequest;
use Modules\Supplier\Http\Requests\Settings\UpdateProfileRequest;
use Modules\Supplier\Http\Requests\Settings\UpdateSystemSettingsRequest;
use Modules\Supplier\Transformers\SupplierResource;

class SettingsController extends BaseController
{
    /**
     * Get profile settings
     */
    public function getProfile(): JsonResponse
    {
        try {
            $supplier = auth()->user();

            return $this->successResponse(
                new SupplierResource($supplier),
                'Profile retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching profile');
        }
    }

    /**
     * Update profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $data = $request->validated();

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($supplier->image) {
                    Storage::disk('public')->delete($supplier->image);
                }

                $data['image'] = $request->file('image')->store('suppliers', 'public');
            }

            $supplier->update($data);

            return $this->successResponse(
                new SupplierResource($supplier->fresh()),
                'Profile updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating profile');
        }
    }

    /**
     * Update account settings
     */
    public function updateAccountSettings(UpdateAccountSettingsRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $supplier->update($request->validated());

            return $this->successResponse(
                new SupplierResource($supplier->fresh()),
                'Account settings updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating account settings');
        }
    }

    /**
     * Update system settings (language, theme)
     */
    public function updateSystemSettings(UpdateSystemSettingsRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $supplier->update($request->validated());

            return $this->successResponse(
                new SupplierResource($supplier->fresh()),
                'System settings updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating system settings');
        }
    }

    /**
     * Update notification settings
     */
    public function updateNotificationSettings(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $supplier->update([
                'notification_preferences' => $request->validated(),
            ]);

            return $this->successResponse(
                new SupplierResource($supplier->fresh()),
                'Notification settings updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating notification settings');
        }
    }
}
