<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\ExceptionService;

/**
 * Risk / exception panel (MISSING_Dashboard §3.3). Returns a paginated list of
 * derived, per-record exceptions filterable by severity / moduleKey / branchId.
 */
class ExceptionController extends AsabController
{
    public function __construct(private readonly ExceptionService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $user = $request->user();
            $companyId = $user->hasAsabRole('admin') ? $request->query('companyId') : $user->company_id;

            $rows = $this->service->all($companyId, $request->query('branchId'));

            if ($severity = $request->query('severity')) {
                $rows = $rows->where('severity', $severity)->values();
            }
            if ($moduleKey = $request->query('moduleKey')) {
                $rows = $rows->where('moduleKey', $moduleKey)->values();
            }

            $page = max(1, (int) $request->query('page', 1));
            $pageSize = min(100, max(1, (int) $request->query('pageSize', 20)));
            $total = $rows->count();

            return $this->listResponse(
                $rows->forPage($page, $pageSize)->values()->all(),
                [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => (int) ceil($total / $pageSize),
                ],
            );
        });
    }
}
