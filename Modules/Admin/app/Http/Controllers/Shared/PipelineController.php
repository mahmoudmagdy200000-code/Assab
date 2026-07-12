<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\DailyRollupService;
use Modules\Admin\Services\PipelineService;

/**
 * Pipeline overview + module aggregation grid (MISSING_Dashboard §3.1–3.2) and
 * the per-branch/day rollup state machine (SRS §5.2c).
 * Company-scoped for non-admin roles; admin may pass ?companyId= for a
 * cross-tenant view. A branch-scoped accountant only ever counts their own
 * branches.
 */
class PipelineController extends AsabController
{
    public function __construct(
        private readonly PipelineService $pipeline,
        private readonly DailyRollupService $rollup,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->pipeline->overview($this->companyScope($request), $this->assignedBranchIds()),
        ));
    }

    public function aggregation(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok([
            'modules' => $this->pipeline->aggregation(
                $this->companyScope($request),
                $request->query('dateFrom'),
                $request->query('dateTo'),
                $this->assignedBranchIds(),
            ),
        ]));
    }

    /**
     * GET /pipeline/daily-rollup — one state chip per branch per day (§5.2c).
     * `date` (default today) returns every in-scope branch, including the ones
     * that uploaded nothing («لا بيانات»). A dateFrom/dateTo range returns only
     * the branch-days that carry operations.
     */
    public function dailyRollup(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'date' => 'sometimes|date',
                'dateFrom' => 'sometimes|date',
                'dateTo' => 'sometimes|date|after_or_equal:dateFrom',
                'branchId' => 'sometimes|string',
                'brandId' => 'sometimes|string',
            ]);

            $result = $this->rollup->rollup(
                $this->targetCompanyId($request),
                $this->assignedBranchIds(),
                [
                    'date' => $request->query('date'),
                    'dateFrom' => $request->query('dateFrom'),
                    'dateTo' => $request->query('dateTo'),
                    'branchId' => $request->query('branchId'),
                    'brandId' => $request->query('brandId'),
                ],
            );

            return $this->listResponse($result['rows'], $result['summary']);
        });
    }

    /** Only an admin may target another company; everyone else is token-scoped. */
    private function companyScope(Request $request): ?string
    {
        return $request->user()->hasAsabRole('admin') ? $request->query('companyId') : null;
    }

    /**
     * The company the rollup reports on. Branch rows carry no tenant global
     * scope, so the id is always explicit: a platform admin must name the
     * company unless their own account is attached to one.
     */
    private function targetCompanyId(Request $request): string
    {
        $companyId = $this->companyScope($request) ?? $request->user()->company_id;

        if (! $companyId) {
            throw new AsabException(
                'COMPANY_REQUIRED',
                'companyId is required for platform admins',
                'يجب تحديد الشركة',
                422,
            );
        }

        return $companyId;
    }
}
