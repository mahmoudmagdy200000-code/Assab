<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Services\CustodyService;
use Modules\Admin\Support\CustodyStatus;
use Modules\Branch\Models\Branch;

/**
 * Cash custody management (SRS §7 ACC-8 / §8 HEAD-4). Serves both the platform
 * (`/accountant/*`, accountant+head) and company (`/company/me/*`) surfaces.
 */
class CashCustodyController extends AsabController
{
    public function __construct(
        private readonly CustodyService $custody,
    ) {}

    /**
     * ACC-8.1 list: derived status per row + KPI header, paginated.
     * Filters: `brandId` (العلامة التجارية), `branchId` (الفرع), `status`
     * (حالة العهدة: normal|low|critical), `q` (branch or custodian name).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'status' => 'sometimes|nullable|in:'.implode(',', CustodyStatus::keys()),
            ]);

            [$legacyScope, $branchScope] = $this->cashCustodyReadScopes($request);
            $register = $this->custody->register($legacyScope, $branchScope, $request->only(['brandId', 'branchId', 'status', 'q']));
            $q = $register['legacy'];
            $ledgerCards = $register['ledger'];

            // KPIs reflect the whole assigned scope, including historical settlement requests.
            $kpis = $this->custody->kpis(
                $legacyScope->get(['id', 'branch_id', 'amount', 'used']),
                $register['ledgerScope'],
            );

            $size = max(1, min((int) $request->query('pageSize', 20), 100));
            $page = max(1, (int) $request->query('page', 1));
            $legacyTotal = $q->count();
            $offset = ($page - 1) * $size;
            $legacy = $offset < $legacyTotal
                ? $q->with('transactions')->orderByDesc('created_at')->orderBy('id')->offset($offset)->limit($size)->get()
                : collect();
            $branchNames = $this->custody->branchNames($legacy->pluck('branch_id'));
            $data = $legacy->map(fn (CashCustody $c) => $this->custody->present($c, $branchNames));
            $remaining = $size - $data->count();
            if ($remaining > 0) {
                $ledgerPage = $ledgerCards->slice(max(0, $offset - $legacyTotal), $remaining);
                $data = $data->concat($this->custody->withRecentBranchTransactions($ledgerPage));
            }

            $paginator = new LengthAwarePaginator($data->values()->all(), $legacyTotal + $ledgerCards->count(), $size, $page);

            return $this->paginated($paginator, extraMeta: ['kpis' => $kpis]);
        });
    }

    public function settlementRequest(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeCashCustodyToBrandRole(
                $this->scopeToAssignedBranches(CashCustody::query()),
                $request,
            )->findOrFail($id);

            if (! $this->isCustodyWriteAuthorized($request->user(), $custody)) {
                return $this->fail(
                    'CUSTODY_MANUAL_WRITE_PROHIBITED',
                    'Custody funding requests are restricted to authorized brand roles.',
                    'طلبات تغذية العهدة مقتصرة على أدوار العلامة المخولة',
                    [],
                    403
                );
            }

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
     * Post a generic custody transaction. Credits are refused here because
     * funding must use a request and branch-receipt workflow. Debits may be
     * pending or approved (default), and are guarded against overdrawing the
     * remaining balance.
     */
    public function addTransaction(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeCashCustodyToBrandRole($this->scopeToAssignedBranches(
                CashCustody::whereIn('company_id', $this->tenantCompanyIdsFor($request->user()))
            ), $request)->findOrFail($id);

            if (! $this->isCustodyWriteAuthorized($request->user(), $custody)) {
                return $this->fail(
                    'CUSTODY_MANUAL_WRITE_PROHIBITED',
                    'Manual custody transaction writes are prohibited under policy FUND-04/06.',
                    'تعديل حركات العهدة اليدوية محظور بموجب السياسة المعتمدة',
                    [],
                    403
                );
            }

            $data = $request->validate([
                'txnType' => 'required|in:credit,debit',
                'amount' => 'required|integer|min:1',
                'description' => 'nullable|string|max:255',
                'date' => 'nullable|date',
                'status' => 'nullable|in:pending,approved',
                'source' => 'nullable|in:treasury,manual',
            ]);

            if ($data['txnType'] === 'credit') {
                return $this->fail(
                    'CUSTODY_FUNDING_REQUEST_REQUIRED',
                    'Custody funding must use the approved request and branch receipt workflow.',
                    'يجب تنفيذ تغذية العهدة عبر طلب معتمد وتأكيد استلام الفرع',
                    [],
                    422
                );
            }

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
