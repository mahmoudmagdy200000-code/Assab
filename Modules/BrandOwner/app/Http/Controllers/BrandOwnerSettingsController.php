<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerSettingsService;
use Modules\BrandOwner\Transformers\BrandOwnerSettingApprovalResource;
use Modules\BrandOwner\Transformers\BrandOwnerSettingNotificationsResource;
use Modules\BrandOwner\Transformers\BrandOwnerSettingReportResource;
use Modules\BrandOwner\Transformers\BrandOwnerSettingRetentionResource;
use Modules\BrandOwner\Transformers\BrandOwnerSettingSecurityResource;
use Modules\FixedAssets\Enums\BrandOwnerNotificationSettingType;
use Modules\FixedAssets\Enums\BrandOwnerReportSettingReport;

class BrandOwnerSettingsController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerSettingsService $service,
    ) {}

    public function showApproval(): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $row = $this->service->approval(auth()->user());

        return $this->successResponse(
            (new BrandOwnerSettingApprovalResource($row))->toArray(request()),
            'Approval settings retrieved successfully',
        );
    }

    public function updateApproval(Request $request): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'response_time_hours' => 'sometimes|integer|min:0',
            'initial_audit_minimum_assets' => 'sometimes|integer|min:0',
            'initial_audit_excellent_ratio' => 'sometimes|integer|min:0|max:100',
            'personal_approval_for_all_new_branches' => 'sometimes|boolean',
            'auto_approve_transfers_within' => 'sometimes|integer|min:0',
            'auto_approve_modifications_within' => 'sometimes|integer|min:0',
            'auto_approve_disposals_within' => 'sometimes|integer|min:0',
            'critical_assets_always_require_approval' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $row = $this->service->updateApproval(auth()->user(), $validator->validated());

        return $this->successResponse(
            (new BrandOwnerSettingApprovalResource($row))->toArray(request()),
            'Approval settings updated successfully',
        );
    }

    public function showReport(): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            (new BrandOwnerSettingReportResource($this->service->report(auth()->user())))->toArray(request()),
            'Report settings retrieved successfully',
        );
    }

    public function updateReport(Request $request): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $allowed = BrandOwnerReportSettingReport::values();
        $validator = Validator::make($request->all(), [
            'monthly_reports' => 'sometimes|array',
            'monthly_reports.*' => 'string|in:'.implode(',', $allowed),
            'quarterly_reports' => 'sometimes|array',
            'quarterly_reports.*' => 'string|in:'.implode(',', $allowed),
            'annual_reports' => 'sometimes|array',
            'annual_reports.*' => 'string|in:'.implode(',', $allowed),
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $row = $this->service->updateReport(auth()->user(), $validator->validated());

        return $this->successResponse(
            (new BrandOwnerSettingReportResource($row))->toArray(request()),
            'Report settings updated successfully',
        );
    }

    public function showSecurity(): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            (new BrandOwnerSettingSecurityResource($this->service->security(auth()->user())))->toArray(request()),
            'Security settings retrieved successfully',
        );
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'data_encryption' => 'sometimes|boolean',
            'daily_backup_enabled' => 'sometimes|boolean',
            'daily_backup_interval_hours' => 'sometimes|integer|min:1',
            'monthly_security_audit' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $row = $this->service->updateSecurity(auth()->user(), $validator->validated());

        return $this->successResponse(
            (new BrandOwnerSettingSecurityResource($row))->toArray(request()),
            'Security settings updated successfully',
        );
    }

    public function showRetention(): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            (new BrandOwnerSettingRetentionResource($this->service->retention(auth()->user())))->toArray(request()),
            'Retention settings retrieved successfully',
        );
    }

    public function updateRetention(Request $request): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'photo_retention_years' => 'sometimes|integer|min:0',
            'handover_reports_retention_years' => 'sometimes|integer|min:0',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $row = $this->service->updateRetention(auth()->user(), $validator->validated());

        return $this->successResponse(
            (new BrandOwnerSettingRetentionResource($row))->toArray(request()),
            'Retention settings updated successfully',
        );
    }

    public function showNotifications(): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $rows = $this->service->notifications(auth()->user());

        return $this->successResponse(
            (new BrandOwnerSettingNotificationsResource($rows))->toArray(request()),
            'Notification settings retrieved successfully',
        );
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        if (! $this->guard()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $allowed = BrandOwnerNotificationSettingType::values();
        $validator = Validator::make($request->all(), [
            'notifications' => 'required|array',
            'notifications.*.type' => 'required|string|in:'.implode(',', $allowed),
            'notifications.*.enabled' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $rows = $this->service->updateNotifications(auth()->user(), $validator->validated());

        return $this->successResponse(
            (new BrandOwnerSettingNotificationsResource($rows))->toArray(request()),
            'Notification settings updated successfully',
        );
    }

    private function guard(): bool
    {
        return auth()->user() instanceof BrandOwner;
    }
}
