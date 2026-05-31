<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;

/**
 * Accountant cash custody management (BACKEND_API_SPEC.md §6.3.11).
 */
class CashCustodyController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = CashCustody::with('transactions');
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            $items = $q->orderByDesc('created_at')->get();

            return $this->ok([
                'data' => $items->map(fn ($c) => [
                    'id' => $c->id,
                    'branchId' => $c->branch_id,
                    'custodianName' => $c->custodian_name,
                    'amount' => $c->amount,
                    'used' => $c->used,
                    'remaining' => $c->amount - $c->used,
                    'daysSinceSettlement' => $c->days_since_settlement,
                    'status' => $c->status,
                    'transactions' => $c->transactions->map(fn ($t) => [
                        'id' => $t->id, 'txnDate' => optional($t->txn_date)->toIso8601String(),
                        'description' => $t->description, 'txnType' => $t->txn_type, 'amount' => $t->amount,
                    ])->all(),
                ])->all(),
                'summary' => [
                    'active' => $items->where('status', 'active')->count(),
                    'lowBalance' => $items->filter(fn ($c) => ($c->amount - $c->used) < 50000)->count(),
                    'overdueSettlements' => $items->where('days_since_settlement', '>', 30)->count(),
                ],
            ]);
        });
    }

    public function settlementRequest(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = CashCustody::findOrFail($id);
            $req = SettlementRequest::create([
                'custody_id' => $custody->id,
                'requested_by_id' => $request->user()->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            return $this->created(['id' => $req->id, 'status' => 'pending']);
        });
    }

    public function addTransaction(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = CashCustody::findOrFail($id);
            $data = $request->validate([
                'txnType' => 'required|in:credit,debit',
                'amount' => 'required|integer|min:1',
                'description' => 'required|string|max:255',
                'date' => 'nullable|date',
            ]);

            $txn = DB::transaction(function () use ($custody, $data, $request) {
                $txn = CashTransaction::create([
                    'custody_id' => $custody->id,
                    'txn_type' => $data['txnType'],
                    'amount' => $data['amount'],
                    'description' => $data['description'],
                    'txn_date' => $data['date'] ?? now(),
                    'created_by_id' => $request->user()->id,
                ]);
                if ($data['txnType'] === 'debit') {
                    $custody->increment('used', $data['amount']);
                } else {
                    $custody->increment('amount', $data['amount']);
                }

                return $txn;
            });

            return $this->created(['id' => $txn->id]);
        });
    }
}
