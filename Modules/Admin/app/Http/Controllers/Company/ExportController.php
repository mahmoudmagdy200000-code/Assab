<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\ExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spreadsheet exports (COMPANY_DASHBOARD_API_SPEC.md §5.3). The spec returns a
 * synchronous xlsx/csv binary (200) for operations/waste/shifts/payroll/cash —
 * generated here with openspout. Only billing-invoice export is async (see
 * BillingController::export). All queries are tenant-scoped via the global scope.
 */
class ExportController extends AsabController
{
    public function __construct(private readonly ExportService $exports) {}

    private function format(Request $request): string
    {
        return $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';
    }

    /** GET /operations/{id}/export — single operation detail sheet. */
    public function operation(Request $request, string $id): BinaryFileResponse
    {
        return $this->exports->operation($this->format($request), $id);
    }

    /** GET /waste/export */
    public function waste(Request $request): BinaryFileResponse
    {
        return $this->exports->waste($this->format($request), $request->query('branchId'));
    }

    /** GET /shifts/export */
    public function shifts(Request $request): BinaryFileResponse
    {
        return $this->exports->shifts($this->format($request), $request->query('branchId'));
    }

    /** GET /employees/payroll/export?month=YYYY-MM */
    public function payroll(Request $request): BinaryFileResponse
    {
        return $this->exports->payroll($this->format($request), $request->query('month'));
    }

    /** GET /cash-custody/export */
    public function cashCustody(Request $request): BinaryFileResponse
    {
        return $this->exports->cashCustody($this->format($request), $request->query('branchId'));
    }

    /**
     * GET /exports/{jobId}/download — fetch a file produced by an async export job.
     * Files live under the company namespace, so a user can only ever reach its own.
     */
    public function download(Request $request, string $jobId)
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $jobId)) {
            return $this->fail('INVALID_JOB', 'Invalid job id', 'معرف غير صالح', [], 400);
        }
        $companyId = $request->user()->company_id;
        foreach (['xlsx', 'csv'] as $ext) {
            $path = "exports/{$companyId}/{$jobId}.{$ext}";
            if (Storage::disk('local')->exists($path)) {
                return Storage::disk('local')->download($path, "export-{$jobId}.{$ext}");
            }
        }

        return $this->fail('EXPORT_NOT_READY', 'Export not found or still processing', 'التصدير غير جاهز', [], 404);
    }
}
