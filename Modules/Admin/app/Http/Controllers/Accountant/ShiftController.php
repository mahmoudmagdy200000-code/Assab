<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\ShiftPresenter;

/**
 * Accountant shift oversight (BACKEND_API_SPEC.md §6.3.9).
 */
class ShiftController extends AsabController
{
    public function __construct(private readonly ShiftPresenter $presenter) {}

    /** Combined entry for /company/me/shifts?status=live|closed (COMPANY_DASHBOARD_API_SPEC.md §5.3.9). */
    public function index(Request $request): JsonResponse
    {
        if ($request->query('status', 'closed') === 'live') {
            return $this->run(function () {
                $active = $this->scopeToAssignedBranches(Shift::whereIn('status', ['active', 'late']))->orderByDesc('started_at')->get();

                return $this->listResponse($this->presenter->collection($active), ['kpis' => $this->kpis()]);
            });
        }

        return $this->history($request);
    }

    public function live(): JsonResponse
    {
        return $this->run(function () {
            $active = $this->scopeToAssignedBranches(Shift::whereIn('status', ['active', 'late']))->orderByDesc('started_at')->get();
            $presented = $this->presenter->collection($active);

            return $this->ok([
                'active' => $presented,
                'overdue' => array_values(array_filter($presented, fn ($s) => $s['status'] === 'late')),
                'kpis' => $this->kpis(),
            ]);
        });
    }

    public function history(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            // Same rule the `closedToday` KPI below already applies: a bridged
            // close stops at `pending_review` and only reaches `closed` on final
            // approval, so filtering on `closed` alone left the history table
            // empty while the KPI beside it counted the very same shifts
            // (prod E2E 2026-07-31: 10 SHF operations, zero history rows).
            $q = $this->scopeToAssignedBranches(Shift::whereIn('status', ['pending_review', 'closed']));
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($type = $request->query('shiftType')) {
                $q->where('shift_type', $type);
            }
            if ($from = $request->query('dateFrom')) {
                $q->whereDate('ended_at', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->whereDate('ended_at', '<=', $to);
            }
            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('cashier_name', 'like', "%{$search}%")->orWhere('supervisor_name', 'like', "%{$search}%"));
            }
            $p = $q->orderByDesc('ended_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, $this->presenter->collection(collect($p->items())));
        });
    }

    /** ACC-6.1 KPI tiles — assignment-scoped, Asia/Riyadh day bounds. */
    private function kpis(): array
    {
        $today = now('Asia/Riyadh')->startOfDay();
        $base = fn () => $this->scopeToAssignedBranches(Shift::query());

        return [
            'openNow' => (clone $base())->whereIn('status', ['active', 'late'])->count(),
            // A shift that ended today counts as closed even while it awaits the
            // accountant/head review (`pending_review`) — the card read 0 all day
            // otherwise, since bridged closes only reach `closed` on final approval.
            'closedToday' => (clone $base())->whereIn('status', ['pending_review', 'closed'])
                ->where('ended_at', '>=', $today)->count(),
            'todaySalesHalalas' => (int) (clone $base())->where('started_at', '>=', $today)->sum('sales_amount'),
            'cashGapsPendingReview' => (clone $base())->whereIn('status', ['pending_review', 'closed'])
                ->where('variance', '<', 0)->count(),
        ];
    }

    /**
     * POST …/shifts/{id}/close — ACC-6.4. Moves the shift to `pending_review`
     * and mints the SHF- pipeline operation; expected cash is server-derived, so
     * the body no longer sends `salesSystem`. Accountant then approves, head
     * final-approves (which closes the shift + posts the gap).
     */
    public function close(Request $request, \Modules\Admin\Services\ShiftCloseService $closeService, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $closeService, $id) {
            // Doc alias: cashInDrawer(Halalas) → cashActualHalalas.
            $request->merge([
                'cashActualHalalas' => $request->input('cashActualHalalas',
                    $request->input('cashInDrawer', $request->input('cashInDrawerHalalas'))),
            ]);
            $data = $request->validate([
                'cashActualHalalas' => 'required|integer|min:0',
                'cardTotalHalalas' => 'sometimes|integer|min:0',
                'aggregatorTotalsHalalas' => 'sometimes|integer|min:0',
                'notes' => 'nullable|string',
            ]);
            $shift = $this->scopeToAssignedBranches(Shift::query())->findOrFail($id);

            $result = $closeService->close($shift, $data, $request->user(), 'system');

            return $this->ok(array_merge(
                $this->presenter->present($result['shift']),
                ['operationId' => $result['operation']->id, 'operationPublicId' => $result['operation']->public_id],
            ));
        });
    }

    /**
     * POST …/shifts/{id}/variance-allocations — ACC-6.4 / ACC-7.4. The
     * accountant's explicit split of the cash gap across employees before head
     * approval; amounts must sum to the gap. Auto-to-cashier applies otherwise.
     */
    public function varianceAllocations(Request $request, \Modules\Admin\Services\ShiftCloseService $closeService, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $closeService, $id) {
            $data = $request->validate([
                'allocations' => 'required|array|min:1',
                'allocations.*.employeeId' => 'sometimes|string',
                'allocations.*.empNumber' => 'sometimes|string',
                'allocations.*.amountHalalas' => 'required|integer|min:1',
            ]);
            $shift = $this->scopeToAssignedBranches(Shift::query())->findOrFail($id);
            $op = \Modules\Admin\Models\Operation::where('module_key', 'shifts')
                ->where('payload->shiftId', $shift->id)
                ->whereIn('status', ['pending', 'approved'])
                ->orderByDesc('created_at')->firstOrFail();

            $rows = $closeService->setVarianceAllocations($op, $data['allocations'], $request->user());

            return $this->ok(['operationId' => $op->id, 'allocations' => $rows]);
        });
    }
}
