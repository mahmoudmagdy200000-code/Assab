<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Services\CustodyService;
use Modules\Branch\Models\Branch;

/**
 * Cash custody management (SRS §7 ACC-8 / §8 HEAD-4). Serves both the platform
 * (`/accountant/*`, accountant+head) and company (`/company/me/*`) surfaces.
 */
class CashCustodyController extends AsabController
{
    public function __construct(private readonly CustodyService $custody) {}

    /** ACC-8.1 list: derived status per row + KPI header, paginated. */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // KPIs reflect the whole assigned scope (not the filtered view).
            $scopeBase = $this->scopeToAssignedBranches(CashCustody::query());
            $kpiSet = (clone $scopeBase)->get(['id', 'branch_id', 'amount', 'used', 'min_alert']);
            $kpis = $this->custody->kpis($kpiSet);

            $q = clone $scopeBase;
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($needle = trim((string) $request->query('q', ''))) {
                $branchIds = Branch::where('name', 'like', '%'.$needle.'%')->pluck('id');
                $q->where(fn ($sub) => $sub->where('custodian_name', 'like', '%'.$needle.'%')
                    ->orWhereIn('branch_id', $branchIds));
            }

            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->with('transactions')->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $branchNames = $this->custody->branchNames(collect($p->items())->pluck('branch_id'));
            $data = array_map(fn (CashCustody $c) => $this->custody->present($c, $branchNames), $p->items());

            return $this->paginated($p, $data, ['kpis' => $kpis]);
        });
    }

    public function settlementRequest(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeToAssignedBranches(CashCustody::query())->findOrFail($id);
            $req = SettlementRequest::create([
                'custody_id' => $custody->id,
                'requested_by_id' => $request->user()->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            return $this->created(['id' => $req->id, 'status' => 'pending']);
        });
    }

    /**
     * Post a custody transaction. A credit raises the custody amount (تعزيز /
     * replenish); a debit is a disbursement. `status=pending` records the txn
     * without touching balances (a disbursement request awaiting approval);
     * `approved` (default) applies the effect immediately. Debits are guarded
     * against overdrawing the remaining balance.
     */
    public function addTransaction(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeToAssignedBranches(CashCustody::query())->findOrFail($id);
            $data = $request->validate([
                'txnType' => 'required|in:credit,debit',
                'amount' => 'required|integer|min:1',
                'description' => 'nullable|string|max:255',
                'date' => 'nullable|date',
                'status' => 'nullable|in:pending,approved',
                'source' => 'nullable|in:treasury,manual',
            ]);
            $status = $data['status'] ?? 'approved';
            $apply = $status === 'approved';
            $source = $data['source'] ?? 'manual';

            // A treasury credit is a تعزيز عهدة top-up — default its description.
            $description = $data['description']
                ?? ($data['txnType'] === 'credit' && $source === 'treasury'
                    ? 'تعزيز عهدة من الخزينة'
                    : 'حركة عهدة');

            $txn = DB::transaction(function () use ($custody, $data, $status, $apply, $source, $description, $request) {
                if ($apply && $data['txnType'] === 'debit') {
                    $this->custody->assertNotOverdrawn($custody, (int) $data['amount']);
                }
                $txn = CashTransaction::create([
                    'custody_id' => $custody->id,
                    'txn_type' => $data['txnType'],
                    'amount' => (int) $data['amount'],
                    'description' => $description,
                    'txn_date' => $data['date'] ?? now(),
                    'status' => $status,
                    'source' => $source,
                    'created_by_id' => $request->user()->id,
                ]);
                if ($apply) {
                    $this->custody->applyTxn($custody, $txn);
                }

                return $txn;
            });

            return $this->created([
                'id' => $txn->id, 'status' => $txn->status, 'txnType' => $txn->txn_type,
                'amountHalalas' => (int) $txn->amount, 'source' => $txn->source,
            ]);
        });
    }
}
