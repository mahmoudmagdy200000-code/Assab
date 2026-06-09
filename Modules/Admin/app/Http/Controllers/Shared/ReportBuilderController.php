<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\ReportBuilderService;

/**
 * Custom report builder (FE completion request §2.4). Foundation for self-serve
 * reports over the operations table; the pre-built reports cover the rest.
 */
class ReportBuilderController extends AsabController
{
    public function __construct(private readonly ReportBuilderService $builder) {}

    /** GET /reports/builder/fields */
    public function fields(): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->builder->fields()));
    }

    /** POST /reports/builder/preview */
    public function preview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'dimensions' => 'sometimes|array',
                'dimensions.*' => 'string',
                'metrics' => 'sometimes|array',
                'metrics.*' => 'string',
                'filters' => 'sometimes|array',
                'dateRange' => 'sometimes|array',
                'dateRange.from' => 'sometimes|nullable|date',
                'dateRange.to' => 'sometimes|nullable|date',
            ]);

            return $this->ok($this->builder->preview($data));
        });
    }

    /** POST /reports/builder/save */
    public function save(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:160',
                'descriptionAr' => 'sometimes|nullable|string|max:255',
                'definition' => 'required|array',
            ]);

            $report = $this->builder->save($data, $request->user()->company_id, $request->user()->id);

            return $this->created([
                'id' => $report->id,
                'name' => $report->name,
                'createdAt' => optional($report->created_at)->toIso8601String(),
            ]);
        });
    }
}
