<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\PipelineService;

/**
 * Pipeline overview + module aggregation grid (MISSING_Dashboard §3.1–3.2).
 * Company-scoped for non-admin roles; admin may pass ?companyId= for a
 * cross-tenant view.
 */
class PipelineController extends AsabController
{
    public function __construct(private readonly PipelineService $pipeline) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->pipeline->overview($this->companyScope($request))));
    }

    public function aggregation(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok([
            'modules' => $this->pipeline->aggregation(
                $this->companyScope($request),
                $request->query('dateFrom'),
                $request->query('dateTo'),
            ),
        ]));
    }

    /** Only an admin may target another company; everyone else is token-scoped. */
    private function companyScope(Request $request): ?string
    {
        return $request->user()->hasAsabRole('admin') ? $request->query('companyId') : null;
    }
}
