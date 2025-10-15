<?php

namespace Modules\Cashier\Http\Controllers;



use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Cashier\Services\ProfileService;
use Modules\Cashier\Http\Requests\UpdateProfileRequest;
use Modules\Cashier\Http\Requests\UploadImageRequest;
use Modules\Cashier\Transformers\CashierDetailResource;
use App\ApiResponse as ApiResponseTrait;
class ProfileController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private ProfileService $profileService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('cashier');
    }

    /**
     * Display cashier profile
     */
    public function show(): JsonResponse
    {
        $cashier = auth()->user();

        $profileData = $this->profileService->getProfile($cashier->id);

        return  $this->successResponse(
            'Profile retrieved successfully',
            new CashierDetailResource($profileData),
        );
    }

    /**
     * Update cashier profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $cashier = auth()->user();

        $data = $request->validated();
        $updatedCashier = $this->profileService->updateProfile($cashier, $data);

        return  $this->successResponse(
            'Profile updated successfully',
            new CashierDetailResource($updatedCashier),
        );
    }

    /**
     * Upload profile image
     */
    public function uploadImage(UploadImageRequest $request): JsonResponse
    {
        $cashier = auth()->user();

        $imagePath = $this->profileService->uploadProfileImage(
            cashier: $cashier,
            image: $request->file('image')
        );

        return  $this->successResponse([
            'image_url' => asset('storage/' . $imagePath),
        ], 'Profile image uploaded successfully');
    }

    /**
     * Get cashier statistics
     */
    public function statistics(): JsonResponse
    {
        $cashier = auth()->user();

        $stats = $this->profileService->getCashierStatistics($cashier->id);

        return  $this->successResponse($stats, 'Statistics retrieved successfully');
    }
}
