<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Jobs\GenerateDataExportJob;
use Modules\Admin\Models\AccountDeletionRequest;
use Modules\Admin\Models\DataExportJob;

/**
 * GDPR / Saudi PDPL self-service (FE completion request §3.4): personal-data
 * export + account-deletion request (T+30, cancelable).
 */
class DataPrivacyController extends AsabController
{
    /** Account-deletion grace period (days). */
    private const DELETION_GRACE_DAYS = 30;

    /** POST /users/me/data-export */
    public function requestExport(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $user = $request->user();
            $job = DataExportJob::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'status' => 'queued',
            ]);
            GenerateDataExportJob::dispatch($job->id);

            return $this->created(['jobId' => $job->id, 'status' => 'queued']);
        });
    }

    /** GET /users/me/data-export/{jobId} */
    public function exportStatus(Request $request, string $jobId): JsonResponse
    {
        return $this->run(function () use ($request, $jobId) {
            $job = DataExportJob::where('user_id', $request->user()->id)->findOrFail($jobId);

            return $this->ok([
                'jobId' => $job->id,
                'status' => $job->status,
                'downloadUrl' => $job->status === 'ready' ? $job->download_url : null,
                'expiresAt' => optional($job->expires_at)->toIso8601String(),
            ]);
        });
    }

    /** POST /users/me/account-deletion-request */
    public function requestDeletion(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'reason' => 'sometimes|nullable|string|max:1000',
                'confirmEmail' => 'required|email',
            ]);
            $user = $request->user();

            if (strcasecmp(trim($data['confirmEmail']), (string) $user->email) !== 0) {
                throw new AsabException('EMAIL_MISMATCH', 'Confirmation email does not match your account', 'البريد المُدخل لا يطابق حسابك', 422, [
                    'confirmEmail' => ['must match your account email'],
                ]);
            }

            $scheduledFor = now()->addDays(self::DELETION_GRACE_DAYS);
            $req = AccountDeletionRequest::create([
                'user_id' => $user->id,
                'confirm_email' => $data['confirmEmail'],
                'reason' => $data['reason'] ?? null,
                'status' => 'scheduled',
                'scheduled_for' => $scheduledFor,
            ]);

            return $this->created([
                'requestId' => $req->id,
                'scheduledFor' => $scheduledFor->toIso8601String(),
            ]);
        });
    }
}
