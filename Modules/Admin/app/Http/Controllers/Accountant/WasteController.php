<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;

/**
 * Accountant waste & damage review (BACKEND_API_SPEC.md §6.3.7).
 * Waste records are operations with module_key=waste; products live in payload.
 */
class WasteController extends AsabController
{
    public function __construct(private readonly OperationService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = Operation::where('module_key', 'waste');
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            $entries = $q->orderByDesc('operation_date')->get();

            return $this->ok([
                'data' => $entries->map(fn ($o) => [
                    'id' => $o->id,
                    'publicId' => $o->public_id,
                    'branchId' => $o->branch_id,
                    'status' => $o->status,
                    'amount' => $o->amount,
                    'products' => $o->payload['products'] ?? [],
                ])->all(),
                'summary' => [
                    'total' => $entries->count(),
                    'pending' => $entries->where('status', 'pending')->count(),
                    'totalAmount' => (int) $entries->sum('amount'),
                ],
            ]);
        });
    }

    public function classifyProduct(Request $request, string $entryId, int $productIdx): JsonResponse
    {
        return $this->run(function () use ($request, $entryId, $productIdx) {
            $data = $request->validate([
                'classification' => 'sometimes|in:هدر,تالف',
                'responsibility' => 'sometimes|in:موظف,مطعم',
            ]);
            $op = $this->find($entryId);
            $payload = $op->payload ?? [];
            if (! isset($payload['products'][$productIdx])) {
                return $this->fail('NOT_FOUND', 'Product not found in waste entry', 'المنتج غير موجود', [], 404);
            }
            foreach ($data as $k => $v) {
                $payload['products'][$productIdx][$k] = $v;
            }
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'product' => $payload['products'][$productIdx]]);
        });
    }

    public function allocations(Request $request, string $entryId, int $productIdx): JsonResponse
    {
        return $this->run(function () use ($request, $entryId, $productIdx) {
            $data = $request->validate(['empAllocs' => 'required|array']);
            $op = $this->find($entryId);
            $payload = $op->payload ?? [];
            if (! isset($payload['products'][$productIdx])) {
                return $this->fail('NOT_FOUND', 'Product not found', 'المنتج غير موجود', [], 404);
            }
            $payload['products'][$productIdx]['empAllocs'] = $data['empAllocs'];
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'empAllocs' => $data['empAllocs']]);
        });
    }

    public function approve(Request $request, string $entryId): JsonResponse
    {
        return $this->run(fn () => $this->ok([
            'id' => $entryId,
            'status' => $this->service->approve($this->find($entryId), $request->user())->status,
        ]));
    }

    public function reject(Request $request, string $entryId): JsonResponse
    {
        return $this->run(function () use ($request, $entryId) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'notes' => 'nullable|string']);

            return $this->ok([
                'id' => $entryId,
                'status' => $this->service->reject($this->find($entryId), $request->user(), $data['reason'], $data['notes'] ?? null)->status,
            ]);
        });
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['entryIds' => 'sometimes|array', 'branchId' => 'sometimes|string']);
            $ids = $data['entryIds'] ?? Operation::where('module_key', 'waste')
                ->when($data['branchId'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
                ->where('status', 'pending')->pluck('id')->all();

            return $this->ok($this->service->bulkApprove($ids, $request->user()));
        });
    }

    private function find(string $id): Operation
    {
        return Operation::where('module_key', 'waste')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
    }
}
