<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class ExpenseDelegationController extends BaseController
{
    public function index(): JsonResponse
    {
        $manager = $this->manager();

        $cashiers = Cashier::query()
            ->where('branch_id', $manager->branch_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'branch_id']);

        $delegations = DB::table('branch_manager_expense_delegations')
            ->where('branch_manager_id', $manager->id)
            ->where('branch_id', $manager->branch_id)
            ->whereNull('revoked_at')
            ->pluck('cashier_id')
            ->flip();

        return $this->successResponse($cashiers->map(fn (Cashier $cashier): array => [
            'id' => $cashier->id,
            'name' => $cashier->name,
            'status' => $cashier->status,
            'can_write_expenses' => isset($delegations[$cashier->id]),
        ])->values());
    }

    public function grant(string $cashier): JsonResponse
    {
        $manager = $this->manager();
        $cashierRecord = Cashier::query()
            ->whereKey($cashier)
            ->where('branch_id', $manager->branch_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if ($cashierRecord === null) {
            return $this->errorResponse('Cashier is not active in your branch', 404);
        }

        $granted = DB::transaction(function () use ($manager, $cashierRecord): bool {
            Cashier::query()->whereKey($cashierRecord->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('branch_manager_expense_delegations')
                ->where('cashier_id', $cashierRecord->id)
                ->where('branch_id', $manager->branch_id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing->branch_manager_id === $manager->id;
            }

            $values = [
                'branch_manager_id' => $manager->id,
                'branch_id' => $manager->branch_id,
                'granted_by' => $manager->id,
                'granted_at' => now(),
                'revoked_at' => null,
                'updated_at' => now(),
            ];

            DB::table('branch_manager_expense_delegations')->insert($values + [
                'id' => (string) Str::uuid(),
                'cashier_id' => $cashierRecord->id,
                'created_at' => now(),
            ]);

            return true;
        });

        if (! $granted) {
            return $this->errorResponse('Cashier already has an active expense delegation in this branch', 409);
        }

        return $this->successResponse(['cashier_id' => $cashierRecord->id, 'can_write_expenses' => true]);
    }

    public function revoke(string $cashier): JsonResponse
    {
        $manager = $this->manager();
        $updated = DB::table('branch_manager_expense_delegations')
            ->where('cashier_id', $cashier)
            ->where('branch_manager_id', $manager->id)
            ->where('branch_id', $manager->branch_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            return $this->errorResponse('No active expense delegation for this cashier in your branch', 404);
        }

        return $this->successResponse(['cashier_id' => $cashier, 'can_write_expenses' => false]);
    }

    private function manager(): BranchManager
    {
        $manager = auth()->user();

        abort_unless($manager instanceof BranchManager && $manager->branch_id !== null, 403);

        return $manager;
    }
}
