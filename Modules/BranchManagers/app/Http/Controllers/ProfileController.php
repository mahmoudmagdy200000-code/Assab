<?php

namespace Modules\BranchManagers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

use Modules\BranchManagers\Http\Requests\UploadImageRequest;
use Modules\BranchManagers\Http\Requests\ChangePasswordRequest;
use Modules\BranchManagers\Http\Requests\UpdateProfileRequest ;
use Modules\BranchManagers\Transformers\BranchManagerDetailResource;
use Modules\BranchManagers\Services\ProfileService;
use Modules\BranchManagers\Traits\ApiResponseTrait;

class ProfileController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private ProfileService $profileService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('branch.manager');
    }

    /**
     * Display branch manager profile
     */
    public function show(): JsonResponse
    {
        $manager = auth()->user();

        $profile = $this->profileService->getProfile($manager->id);

        return $this->successResponse(
            new BranchManagerDetailResource($profile),
            'Profile retrieved successfully'
        );
    }

    /**
     * Update branch manager profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $manager = auth()->user();

        $data = $request->validated();
        $updatedManager = $this->profileService->updateProfile($manager, $data);

        return $this->successResponse(
            new BranchManagerDetailResource($updatedManager),
            'Profile updated successfully'
        );
    }

    /**
     * Upload profile image
     */
    public function uploadImage(UploadImageRequest $request): JsonResponse
    {
        $manager = auth()->user();

        $imagePath = $this->profileService->uploadProfileImage(
            manager: $manager,
            image: $request->file('image')
        );

        return $this->successResponse([
            'image_url' => asset('storage/' . $imagePath),
        ], 'Profile image uploaded successfully');
    }

    /**
     * Change password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $manager = auth()->user();

        $this->profileService->changePassword(
            manager: $manager,
            currentPassword: $request->current_password,
            newPassword: $request->new_password
        );

        return $this->successResponse(null, 'Password changed successfully');
    }

    /**
     * Delete profile image
     */
    public function deleteImage(): JsonResponse
    {
        $manager = auth()->user();

        $this->profileService->deleteProfileImage($manager);

        return $this->successResponse(null, 'Profile image deleted successfully');
    }
}
