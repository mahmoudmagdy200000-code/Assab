<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\Employee;
use Modules\Admin\Services\CustodyService;
use Modules\Admin\Services\EmployeeLedgerService;
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
    public function __construct(
        private readonly ExportService $exports,
        private readonly EmployeeLedgerService $ledger,
        private readonly CustodyService $custody,
    ) {}

    private function format(Request $request): string
    {
        return $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';
    }

    /** GET /operations/{id}/export — single operation detail sheet. */
    public function operation(Request $request, string $id): BinaryFileResponse
    {
        // Zero-trust: an operation outside the caller's branches reads as absent.
        return $this->exports->operation($this->format($request), $id, $this->assignedBranchIds());
    }

    /** GET /company/me/inventory/export — variance sheet (FE completion request §1.8). */
    public function inventoryExport(Request $request): BinaryFileResponse
    {
        return $this->exports->inventory($this->format($request), $request->user()->company_id, [
            'brandId' => $request->query('brandId'),
            'branchId' => $request->query('branchId'),
            'date' => $request->query('date'),
        ]);
    }

    /** GET /waste/export */
    public function waste(Request $request): BinaryFileResponse
    {
        // Zero-trust: a branch-scoped accountant exports only their branches.
        return $this->exports->waste($this->format($request), $request->query('branchId'), $this->assignedBranchIds());
    }

    /** GET /shifts/export */
    public function shifts(Request $request): BinaryFileResponse
    {
        // Zero-trust: a branch-scoped accountant exports only their branches.
        return $this->exports->shifts($this->format($request), $request->query('branchId'), $this->assignedBranchIds());
    }

    /** GET /employees/payroll/export?month=YYYY-MM */
    public function payroll(Request $request): BinaryFileResponse
    {
        // Zero-trust: a branch-scoped accountant exports only their branches.
        return $this->exports->payroll($this->format($request), $request->query('month'), $this->assignedBranchIds());
    }

    /** GET /employees/{id}/statement/export?month=YYYY-MM — per-employee ledger. */
    public function employeeStatement(Request $request, string $id): BinaryFileResponse
    {
        // Zero-trust: an employee outside the caller's branches reads as absent.
        $employee = $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);
        $statement = $this->ledger->statement($employee, $request->query('month'), 1, 2000);

        return $this->exports->employeeStatement($this->format($request), $statement);
    }

    /** GET /cash-custody/export */
    public function cashCustody(Request $request): BinaryFileResponse
    {
        // Zero-trust: a branch-scoped accountant exports only their branches.
        return $this->exports->cashCustody($this->format($request), $request->query('branchId'), $this->assignedBranchIds());
    }

    /** GET /cash-custody/{id}/transactions/export?month=YYYY-MM — HEAD-4 monthly ledger. */
    public function custodyLedger(Request $request, string $id): BinaryFileResponse
    {
        // Zero-trust: a custody outside the caller's branches reads as absent.
        $custody = $this->scopeToAssignedBranches(
            CashCustody::where('company_id', $request->user()->company_id)
        )->findOrFail($id);
        $ledger = $this->custody->ledger($custody, $request->query('month'), 1, 2000);

        return $this->exports->custodyLedger($this->format($request), $ledger);
    }

    /**
     * GET /company/me/operations/export — sales/expenses/purchases bulk export
     * (also serves the shared GET /operations/export when no moduleKey is given).
     */
    public function operationsExport(Request $request): BinaryFileResponse
    {
        return $this->exports->operations($this->format($request), $request->user()->company_id, [
            'moduleKey' => $request->query('moduleKey'),
            'status' => $request->query('status'),
            'branchId' => $request->query('branchId'),
            'brandId' => $request->query('brandId'),
            'dateFrom' => $request->query('dateFrom'),
            'dateTo' => $request->query('dateTo'),
            // Zero-trust: a branch-scoped accountant exports only their branches.
            'branchIds' => $this->assignedBranchIds(),
        ]);
    }

    /** GET /company/me/assets/export — fixed-assets register. */
    public function assetsExport(Request $request): BinaryFileResponse
    {
        return $this->exports->assets(
            $this->format($request),
            $request->user()->company_id,
            $request->query('category'),
            $request->query('branchId'),
            // Zero-trust: a branch-scoped accountant exports only their branches.
            $this->assignedBranchIds(),
        );
    }

    /** GET /company/me/accountant/reminders/export */
    public function remindersExport(Request $request): BinaryFileResponse
    {
        return $this->exports->reminders($this->format($request), $request->user()->company_id);
    }

    /** GET /company/me/suppliers/export */
    public function suppliersExport(Request $request): BinaryFileResponse
    {
        return $this->exports->companySuppliers($this->format($request), $request->user()->company_id);
    }

    /** GET /company/me/procurement/items/export */
    public function procurementItemsExport(Request $request): BinaryFileResponse
    {
        return $this->exports->procurementItems($this->format($request), $request->user()->company_id);
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
