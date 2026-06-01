<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Shift;

/**
 * Accountant shift oversight (BACKEND_API_SPEC.md §6.3.9).
 */
class ShiftController extends AsabController
{
    /** Combined entry for /company/me/shifts?status=live|closed (COMPANY_DASHBOARD_API_SPEC.md §5.3.9). */
    public function index(Request $request): JsonResponse
    {
        if ($request->query('status', 'closed') === 'live') {
            return $this->run(fn () => $this->listResponse(
                Shift::whereIn('status', ['active', 'late'])->orderByDesc('started_at')->get()->map([$this, 'present'])->all()
            ));
        }

        return $this->history($request);
    }

    public function live(): JsonResponse
    {
        return $this->run(function () {
            $active = Shift::whereIn('status', ['active', 'late'])->orderByDesc('started_at')->get();

            return $this->ok([
                'active' => $active->map([$this, 'present'])->all(),
                'overdue' => $active->where('status', 'late')->map([$this, 'present'])->values()->all(),
            ]);
        });
    }

    public function history(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = Shift::where('status', 'closed');
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            $p = $q->orderByDesc('ended_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    public function close(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $id) {
            $data = $request->validate([
                'cashInDrawer' => 'required|integer',
                'salesSystem' => 'required|integer',
                'notes' => 'nullable|string',
            ]);
            $shift = Shift::findOrFail($id);
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

            return $this->ok($this->present($shift->fresh()));
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
