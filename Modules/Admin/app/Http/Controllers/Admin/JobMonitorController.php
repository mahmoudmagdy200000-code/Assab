<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\JobRun;

/**
 * Admin background-job monitoring (FE completion request §3.6). Long-running jobs
 * record a JobRun row; this surface lists them and exposes retry/cancel.
 */
class JobMonitorController extends AsabController
{
    /** GET /admin/jobs?status=&type=&page=&pageSize= */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = JobRun::query();
            if ($status = $request->query('status')) {
                $q->whereIn('status', explode(',', $status));
            }
            if ($type = $request->query('type')) {
                $q->where('type', $type);
            }
            $p = $q->orderByDesc('queued_at')->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    /** POST /admin/jobs/{id}/retry */
    public function retry(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $job = JobRun::findOrFail($id);
            $job->update(['status' => 'retrying', 'attempt_count' => $job->attempt_count + 1, 'last_error' => null]);

            return $this->ok(['ok' => true, 'requeuedAt' => now()->toIso8601String()]);
        });
    }

    /** POST /admin/jobs/{id}/cancel */
    public function cancel(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $job = JobRun::findOrFail($id);
            $job->update(['status' => 'cancelled', 'finished_at' => now()]);

            return $this->ok(['ok' => true, 'cancelledAt' => now()->toIso8601String()]);
        });
    }

    /** @return array<string, mixed> */
    private function present(JobRun $j): array
    {
        return [
            'id' => $j->id,
            'type' => $j->type,
            'status' => $j->status,
            'companyId' => $j->company_id,
            'branchId' => $j->branch_id,
            'triggeredBy' => ['userId' => $j->triggered_by_id, 'name' => $j->triggered_by_name],
            'progressPct' => $j->progress_pct,
            'attemptCount' => $j->attempt_count,
            'lastError' => $j->last_error,
            'queuedAt' => optional($j->queued_at)->toIso8601String(),
            'startedAt' => optional($j->started_at)->toIso8601String(),
            'finishedAt' => optional($j->finished_at)->toIso8601String(),
        ];
    }
}
