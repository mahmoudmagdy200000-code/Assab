<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Employee;
use Modules\Admin\Services\ShiftCustodyStatementService;
use Modules\Admin\Transformers\ShiftCustodyStatementTransformer;

/**
 * Controller for Shift Custody Statement (P2 / G2).
 * Scoped by company tenant and accountant assigned branches. Zero writes.
 */
class ShiftCustodyStatementController extends AsabController
{
    public function __construct(
        private readonly ShiftCustodyStatementService $statementService,
        private readonly ShiftCustodyStatementTransformer $transformer,
    ) {}

    /**
     * GET /company/me/employees/{id}/shift-custody-statement
     */
    public function statement(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $employee = $this->scopeToAssignedBranches(Employee::query())->findOrFail($id);

            $data = $this->statementService->getStatement(
                $employee,
                $request->query('from'),
                $request->query('to'),
                (int) $request->query('page', 1),
                (int) $request->query('pageSize', 20),
            );

            return $this->ok($this->transformer->transform($data));
        });
    }
}
