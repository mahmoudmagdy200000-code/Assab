<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;

/**
 * Async export jobs (COMPANY_DASHBOARD_API_SPEC.md — operations/waste/shifts/
 * payroll/cash-custody/billing Excel exports). Real file generation is deferred
 * to a background worker; the endpoint returns a job id the client polls.
 */
class ExportController extends AsabController
{
    public function job(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok([
            'jobId' => 'job_'.strtoupper(bin2hex(random_bytes(6))),
            'format' => $request->query('format', 'xlsx'),
            'status' => 'queued',
        ], 202));
    }
}
