<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Exceptions\BranchManagerSettingsException;
use Modules\BranchManagers\Http\Requests\Settings\ResetMyPasswordRequest;
use Modules\BranchManagers\Services\BranchManagerSettingsService;
use Modules\BranchManagers\Transformers\Settings\MyAccountDetailsResource;
use Modules\BranchManagers\Transformers\Settings\SettingsSnapshotResource;

/**
 * Branch manager settings screen: account details, settings snapshot
 * and self-service password reset.
 */
class BranchManagerSettingsController extends BaseController
{
    public function __construct(
        private readonly BranchManagerSettingsService $settingsService,
    ) {}

    /**
     * GET /branch-manager/settings/account-details
     *
     * Account details for the authenticated user (any role).
     */
    public function accountDetails(): JsonResponse
    {
        $user = $this->settingsService->accountDetails(auth()->user());

        return $this->successResponse(
            new MyAccountDetailsResource($user),
            'Account details retrieved successfully',
        );
    }

    /**
     * GET /branch-manager/settings/aggregators
     *
     * Full settings snapshot for the branch manager settings home section.
     */
    public function snapshot(): JsonResponse
    {
        $snapshot = $this->settingsService->snapshot(auth()->user());

        return $this->successResponse(
            new SettingsSnapshotResource($snapshot),
            'Settings retrieved successfully',
        );
    }

    /**
     * POST /branch-manager/settings/reset-password
     *
     * Resets the authenticated user's password (any role).
     */
    public function resetPassword(ResetMyPasswordRequest $request): JsonResponse
    {
        try {
            $this->settingsService->resetPassword(
                auth()->user(),
                $request->validated('old_password'),
                $request->validated('password'),
            );
        } catch (BranchManagerSettingsException $e) {
            return $this->errorResponse($e->getMessage(), $e->status());
        }

        return $this->successResponse(null, 'Password updated successfully');
    }
}
