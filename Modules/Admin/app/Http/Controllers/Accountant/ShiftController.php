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
            $q = $this->scopeToAssignedBranches(Shift::where('status', 'closed'));
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
            'closedToday' => (clone $base())->where('status', 'closed')->where('ended_at', '>=', $today)->count(),
            'todaySalesHalalas' => (int) (clone $base())->where('started_at', '>=', $today)->sum('sales_amount'),
            'cashGapsPendingReview' => (clone $base())->whereIn('status', ['pending_review', 'closed'])
                ->where('variance', '<', 0)->count(),
        ];
    }

    public function close(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $id) {
            // Doc aliases: cashInDrawerHalalas->cashInDrawer, salesSystemHalalas->salesSystem.
            $request->merge([
                'cashInDrawer' => $request->input('cashInDrawer', $request->input('cashInDrawerHalalas')),
                'salesSystem' => $request->input('salesSystem', $request->input('salesSystemHalalas')),
            ]);
            $data = $request->validate([
                'cashInDrawer' => 'required|integer',
                'salesSystem' => 'required|integer',
                'notes' => 'nullable|string',
            ]);
            $shift = $this->scopeToAssignedBranches(Shift::query())->findOrFail($id);
            $variance = $data['cashInDrawer'] - $data['salesSystem'];
            $shift->update([
                'status' => 'closed',
                'ended_at' => now(),
                'cash_actual' => $data['cashInDrawer'],
                'cash_expected' => $data['salesSystem'],
                'variance' => $variance,
                'notes' => $data['notes'] ?? null,
            ]);
            $rt->shiftChanged($shift->fresh(), 'closed');

            // Superset response: present() keys + varianceHalalas + createdAt.
            $fresh = $shift->fresh();

            return $this->ok(array_merge($this->present($fresh), [
                'varianceHalalas' => $fresh->variance,
                'createdAt' => optional($fresh->created_at)->toIso8601String(),
            ]));
        });
    }

    public function present(Shift $s): array
    {
        return [
            'id' => $s->id,
            'branchId' => $s->branch_id,
            'supervisor' => $s->supervisor_name,
            'startedAt' => optional($s->started_at)->toIso8601String(),
            'endedAt' => optional($s->ended_at)->toIso8601String(),
            'status' => $s->status,
            'ordersCount' => $s->orders_count,
            'salesAmount' => $s->sales_amount,
            'cashExpected' => $s->cash_expected,
            'cashActual' => $s->cash_actual,
            'variance' => $s->variance,
        ];
    }
}
