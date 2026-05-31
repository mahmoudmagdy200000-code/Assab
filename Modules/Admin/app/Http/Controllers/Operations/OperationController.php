<?php

namespace Modules\Admin\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;

class OperationController extends AsabController
{
    public function __construct(private readonly OperationService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = Operation::query();

            foreach (['module_key' => 'moduleKey', 'status' => 'status', 'branch_id' => 'branchId', 'match' => 'match'] as $col => $param) {
                if ($val = $request->query($param)) {
                    str_contains($val, ',')
                        ? $q->whereIn($col, explode(',', $val))
                        : $q->where($col, $val);
                }
            }
            if ($search = $request->query('search')) {
                $q->where('public_id', 'like', "%{$search}%");
            }

            $p = $q->orderByDesc('operation_date')->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated(
                $p,
                array_map([$this, 'present'], $p->items()),
                ['summary' => $this->summary($request)],
            );
        });
    }

    public function show(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = Operation::with('steps')->where('id', $id)->orWhere('public_id', $id)->firstOrFail();
            $data = $this->present($op);
            $data['payload'] = $op->payload;
            $data['auditTrail'] = $op->steps->map(fn ($s) => [
                'stageId' => $s->stage_id,
                'action' => $s->action,
                'by' => $s->actor_label,
                'time' => optional($s->occurred_at)->toIso8601String(),
                'note' => $s->note,
            ])->all();

            return $this->ok($data);
        });
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present(
            $this->service->approve($this->find($id), $request->user(), $request->input('note')),
        )));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'notes' => 'nullable|string']);

            return $this->ok($this->present(
                $this->service->reject($this->find($id), $request->user(), $data['reason'], $data['notes'] ?? null),
            ));
        });
    }

    public function finalApprove(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present(
            $this->service->finalApprove(
                $this->find($id),
                $request->user(),
                (bool) $request->input('isConditional', false),
                $request->input('conditionalNote'),
            ),
        )));
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['operationIds' => 'required|array', 'operationIds.*' => 'string']);

            return $this->ok($this->service->bulkApprove($data['operationIds'], $request->user()));
        });
    }

    public function correction(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'correctionReason' => 'required|string|max:500',
                'amount' => 'sometimes|integer|min:0',
                'diffNote' => 'sometimes|string|max:255',
            ]);
            $original = $this->find($id);
            $overrides = array_filter([
                'amount' => $data['amount'] ?? null,
                'diff_note' => $data['diffNote'] ?? null,
            ], fn ($v) => $v !== null);

            return $this->created($this->present(
                $this->service->correction($original, $request->user(), $data['correctionReason'], $overrides),
            ));
        });
    }

    public function auditTrail(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $op = Operation::with('steps')->where('id', $id)->orWhere('public_id', $id)->firstOrFail();
            $last = $op->steps->count() - 1;

            return $this->listResponse($op->steps->values()->map(fn ($s, $i) => [
                'icon' => $this->stageIcon($s->stage_id),
                'stageId' => $s->stage_id,
                'action' => $s->action,
                'by' => $s->actor_label,
                'time' => optional($s->occurred_at)->toIso8601String(),
                'note' => $s->note,
                'isTerminal' => in_array($s->stage_id, ['final', 'rejected'], true) && $i === $last,
            ])->all());
        });
    }

    private function stageIcon(string $stage): string
    {
        return [
            'submit' => '📋', 'review' => '👀', 'approved' => '✓',
            'final' => '🔒', 'erp' => '📤', 'rejected' => '✗', 'reports' => '📊',
        ][$stage] ?? '•';
    }

    private function find(string $id): Operation
    {
        return Operation::where('id', $id)->orWhere('public_id', $id)->firstOrFail();
    }

    private function summary(Request $request): array
    {
        $base = Operation::query();
        if ($module = $request->query('moduleKey')) {
            $base->where('module_key', $module);
        }

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', Operation::STATUS_PENDING)->count(),
            'approved' => (clone $base)->where('status', Operation::STATUS_APPROVED)->count(),
            'finalApproved' => (clone $base)->where('status', Operation::STATUS_FINAL)->count(),
            'rejected' => (clone $base)->where('status', Operation::STATUS_REJECTED)->count(),
        ];
    }

    private function present(Operation $op): array
    {
        return [
            'id' => $op->id,
            'publicId' => $op->public_id,
            'branchId' => $op->branch_id,
            'moduleKey' => $op->module_key,
            'sourceModule' => $op->source_module,
            'sourceId' => $op->source_id,
            'amount' => $op->amount,
            'match' => $op->match,
            'diffNote' => $op->diff_note,
            'origin' => $op->origin,
            'attachmentCount' => $op->attachment_count,
            'status' => $op->status,
            'rejectReason' => $op->reject_reason,
            'isConditional' => (bool) $op->is_conditional,
            'isCorrection' => (bool) $op->is_correction,
            'erpPosted' => (bool) $op->erp_posted,
            'operationDate' => optional($op->operation_date)->toIso8601String(),
            'submittedAt' => optional($op->submitted_at)->toIso8601String(),
            'approvedAt' => optional($op->approved_at)->toIso8601String(),
            'finalApprovedAt' => optional($op->final_approved_at)->toIso8601String(),
            'createdAt' => optional($op->created_at)->toIso8601String(),
        ];
    }
}
