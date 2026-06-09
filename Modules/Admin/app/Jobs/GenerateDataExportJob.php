<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Models\DataExportJob;
use Modules\Admin\Models\Operation;

/**
 * Builds a user's personal-data export (FE completion request §3.4). Gathers the
 * user's own records into a JSON file and marks the job ready with a download URL.
 */
class GenerateDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $exportJobId) {}

    public function handle(): void
    {
        $job = DataExportJob::find($this->exportJobId);
        if (! $job) {
            return;
        }

        try {
            $job->update(['status' => 'processing']);
            $user = AsabUser::withTrashed()->find($job->user_id);

            $data = [
                'generatedAt' => now()->toIso8601String(),
                'profile' => $user ? [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'phone' => $user->phone, 'companyId' => $user->company_id,
                    'createdAt' => optional($user->created_at)->toIso8601String(),
                ] : null,
                'roles' => AsabUserRole::where('user_id', $job->user_id)->get(['role_key', 'scope', 'brand_ids', 'restaurant_ids', 'branch_ids'])->toArray(),
                'notifications' => AsabNotification::where('user_id', $job->user_id)->limit(5000)->get(['type', 'title', 'body', 'created_at'])->toArray(),
                'auditLogs' => AuditLog::where('actor_user_id', $job->user_id)->limit(5000)->get(['action', 'entity_type', 'entity_id', 'occurred_at'])->toArray(),
                'operationsSubmitted' => Operation::where('submitted_by_id', $job->user_id)->limit(5000)->get(['public_id', 'module_key', 'amount', 'status', 'operation_date'])->toArray(),
            ];

            $key = 'data-exports/'.$job->id.'.json';
            Storage::disk('public')->put($key, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $job->update([
                'status' => 'ready',
                'storage_key' => $key,
                'download_url' => Storage::disk('public')->url($key),
                'expires_at' => now()->addDays(7),
            ]);
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
