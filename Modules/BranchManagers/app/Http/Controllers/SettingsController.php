<?php

namespace Modules\BranchManagers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\BranchManagers\Services\SettingsService;
use Modules\BranchManagers\Http\Requests\UpdateNotificationSettingsRequest;
use Modules\BranchManagers\Http\Requests\UpdateSystemSettingsRequest;
use App\ApiResponse as ApiResponseTrait;

class SettingsController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private SettingsService $settingsService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('branch.manager');
    }

    /**
     * Get all settings
     */
    public function index(): JsonResponse
    {
        $manager = auth()->user();

        $settings = $this->settingsService->getAllSettings($manager);

        return $this->successResponse($settings, 'Settings retrieved successfully');
    }

    /**
     * Get notification settings
     */
    public function getNotificationSettings(): JsonResponse
    {
        $manager = auth()->user();

        $settings = $this->settingsService->getNotificationSettings($manager);

        return $this->successResponse($settings, 'Notification settings retrieved successfully');
    }

    /**
     * Update notification settings
     */
    public function updateNotificationSettings(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $manager = auth()->user();

        $settings = $this->settingsService->updateNotificationSettings(
            manager: $manager,
            data: $request->validated()
        );

        return $this->successResponse($settings, 'Notification settings updated successfully');
    }

    /**
     * Get system settings
     */
    public function getSystemSettings(): JsonResponse
    {
        $manager = auth()->user();

        $settings = $this->settingsService->getSystemSettings($manager);

        return $this->successResponse($settings, 'System settings retrieved successfully');
    }

    /**
     * Update system settings
     */
    public function updateSystemSettings(UpdateSystemSettingsRequest $request): JsonResponse
    {
        $manager = auth()->user();

        $settings = $this->settingsService->updateSystemSettings(
            manager: $manager,
            data: $request->validated()
        );

        return $this->successResponse($settings, 'System settings updated successfully');
    }

    /**
     * Get branch & aggregator settings
     */
    public function getBranchSettings(): JsonResponse
    {
        $manager = auth()->user();

        $settings = $this->settingsService->getBranchSettings($manager);

        return $this->successResponse($settings, 'Branch settings retrieved successfully');
    }

    /**
     * Update aggregators
     */
    public function updateAggregators(): JsonResponse
    {
        $manager = auth()->user();

        $request->validate([
            'aggregator_ids' => 'required|array',
            'aggregator_ids.*' => 'exists:aggregators,id',
        ]);

        $result = $this->settingsService->updateBranchAggregators(
            manager: $manager,
            aggregatorIds: $request->aggregator_ids
        );

        return $this->successResponse($result, 'Aggregators updated successfully');
    }
}
