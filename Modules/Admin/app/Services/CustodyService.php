<?php

namespace Modules\Admin\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Support\CustodyStatus;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyTransaction;

/**
 * SRS §7 ACC-8 / §8 HEAD-4 — cash-custody presentation + health. Owns the
 * normal/low/critical derivation, persists it on every balance change, and
 * builds the ACC-8.1 KPI block. Balance math (apply/reverse a txn) lives in the
 * controllers that own the txn lifecycle; this service is the read/status core.
 */
class CustodyService
{
    private const BRANCH_AMOUNT = 'ROUND(amount * 100)';

    private const BRANCH_FLOW = 'CASE WHEN is_cash_in = 1 THEN '.self::BRANCH_AMOUNT.' ELSE -'.self::BRANCH_AMOUNT.' END';

    private const BRANCH_FUNDING = 'CASE WHEN is_cash_in = 1 AND related_expense_id IS NULL THEN '.self::BRANCH_AMOUNT.' ELSE 0 END';
    public function __construct(
        private readonly RealtimeBroadcaster $rt,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /**
     * Recompute the derived status from the live balance and persist it. Emits a
     * low-balance push when the custody is now low/critical. Returns the status.
     */
    public function recompute(CashCustody $custody, bool $notify = true): string
    {
        $remaining = (int) $custody->amount - (int) $custody->used;
        $status = CustodyStatus::derive($remaining, $custody->min_alert);

        if ($custody->status !== $status) {
            $custody->status = $status;
            $custody->save();
        }
        if ($notify && $status !== 'normal' && $custody->branch_id) {
            $this->rt->custodyLowBalance($custody->branch_id, $custody->id, $status, $remaining);
        }

        return $status;
    }

    /**
     * «حالة العهدة» filter, expressed in SQL against the LIVE balance rather
     * than the stored `status` column — legacy rows carry `active` there, so a
     * plain `where('status', …)` silently drops every pre-T09 custody.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyStatusFilter($query, ?string $status)
    {
        if ($status === null || $status === '') {
            return $query;
        }

        $near = CustodyStatus::NEAR_DEPLETION_HALALAS;
        $remaining = '(amount - used)';
        // A zero/NULL min_alert means «unset» → the SRS default threshold.
        $threshold = 'COALESCE(NULLIF(min_alert, 0), '.CustodyStatus::DEFAULT_MIN_ALERT_HALALAS.')';

        return match ($status) {
            'critical' => $query->whereRaw("{$remaining} < ?", [$near]),
            'low' => $query->whereRaw("{$remaining} >= ?", [$near])->whereRaw("{$remaining} < {$threshold}"),
            default => $query->whereRaw("{$remaining} >= ?", [$near])->whereRaw("{$remaining} >= {$threshold}"),
        };
    }

    /**
     * @param  array<string,string>  $branchNames
     * @param  Collection<int,CashTransaction>|null  $txns  eager-loaded, else read from relation
     * @return array<string,mixed>
     */
    public function present(CashCustody $custody, array $branchNames = [], ?Collection $txns = null): array
    {
        $remaining = (int) $custody->amount - (int) $custody->used;
        $status = CustodyStatus::derive($remaining, $custody->min_alert);
        $amount = max(1, (int) $custody->amount);
        $txns ??= $custody->transactions;

        return [
            'id' => $custody->id,
            'branchId' => $custody->branch_id,
            'branchName' => $branchNames[$custody->branch_id] ?? null,
            'custodianName' => $custody->custodian_name,
            'amountHalalas' => (int) $custody->amount,
            'usedHalalas' => (int) $custody->used,
            'remainingHalalas' => $remaining,
            'minAlertHalalas' => (int) $custody->min_alert,
            'usagePct' => round((int) $custody->used / $amount * 100, 1),
            'status' => $status,
            'statusLabelAr' => CustodyStatus::labelAr($status),
            'daysSinceSettlement' => (int) $custody->days_since_settlement,
            // Legacy aliases (pre-T09 keys still read by parts of the SPA).
            'amount' => (int) $custody->amount,
            'used' => (int) $custody->used,
            'remaining' => $remaining,
            'transactions' => $txns->map(fn (CashTransaction $t) => [
                'id' => $t->id,
                'txnDate' => optional($t->txn_date)->toIso8601String(),
                'description' => $t->description,
                'txnType' => $t->txn_type,
                'amountHalalas' => (int) $t->amount,
                'amount' => (int) $t->amount,
                'status' => $t->status,
                'source' => $t->source,
            ])->all(),
        ];
    }

    /** @return array{activeCustodies:int, pendingRequests:int, nearDepletion:int} */
    public function kpis(Collection $custodies, Collection $ledgerCards): array
    {
        $legacy = $custodies->whereNotIn('branch_id', $ledgerCards->pluck('branchId'));
        $ids = $custodies->pluck('id');
        $pendingSettlements = $ids->isEmpty() ? 0 : SettlementRequest::whereIn('custody_id', $ids)->where('status', 'pending')->count();
        $pendingTxns = $ids->isEmpty() ? 0 : CashTransaction::whereIn('custody_id', $ids)->where('status', 'pending')->count();

        return [
            'activeCustodies' => $legacy->count() + $ledgerCards->count(),
            'pendingRequests' => $pendingSettlements + $pendingTxns,
            'nearDepletion' => $legacy->filter(
                fn (CashCustody $c) => CustodyStatus::isNearDepletion((int) $c->amount - (int) $c->used)
            )->count() + $ledgerCards->filter(
                fn (array $card) => CustodyStatus::isNearDepletion($card['remainingHalalas'])
            )->count(),
        ];
    }

    /**
     * Apply a txn's balance effect once: a credit raises the custody amount
     * (a تعزيز top-up), a debit raises `used` (a disbursement). Recomputes status.
     */
    public function applyTxn(CashCustody $custody, CashTransaction $txn): void
    {
        if ($txn->txn_type === 'debit') {
            $custody->increment('used', (int) $txn->amount);
        } else {
            $custody->increment('amount', (int) $txn->amount);
        }
        $this->recompute($custody->refresh());
    }

    /** Reverse a previously-applied txn's balance effect. Recomputes status. */
    public function reverseTxn(CashCustody $custody, CashTransaction $txn): void
    {
        if ($txn->txn_type === 'debit') {
            $custody->decrement('used', (int) $txn->amount);
        } else {
            $custody->decrement('amount', (int) $txn->amount);
        }
        $this->recompute($custody->refresh());
    }

    /**
     * ACC-8.2 overdraw guard: a disbursement may not exceed the remaining balance
     * (that would drive `used` past `amount` and render negative usage bars).
     */
    public function assertNotOverdrawn(CashCustody $custody, int $debitAmount): void
    {
        $remaining = (int) $custody->amount - (int) $custody->used;
        if ($debitAmount > $remaining) {
            throw new \Modules\Admin\Exceptions\AsabException(
                'CUSTODY_OVERDRAWN',
                'Disbursement exceeds the remaining custody balance',
                'المبلغ يتجاوز الرصيد المتبقي للعهدة',
                422,
                ['amountHalalas' => ["remaining {$remaining} halalas"]],
            );
        }
    }

    /**
     * HEAD-4 monthly ledger for one custody: month-bounded rows, per-row running
     * balance, مدين وارد / دائن صادر labels, current-balance footer. Only approved
     * txns move the running balance; pending rows are shown but carry it flat.
     *
     * Running reconciles exactly to remaining (amount − used): the custody's
     * initial (non-txn) amount = amount − Σ approved credits, and each approved
     * credit/debit moves the balance +/−.
     *
     * @return array<string,mixed>
     */
    public function ledger(CashCustody $custody, ?string $month, int $page = 1, ?int $pageSize = 50): array
    {
        [$start, $end, $monthKey] = $this->period($month);
        $base = CashTransaction::where('custody_id', $custody->id);
        $signed = "CASE WHEN status <> 'approved' THEN 0 WHEN txn_type = 'credit' THEN amount ELSE -amount END";
        $approvedCredits = (int) (clone $base)->where('status', 'approved')->where('txn_type', 'credit')->sum('amount');
        $opening = (int) $custody->amount - $approvedCredits;
        if ($month) {
            $prior = (clone $base)->where('txn_date', '<', $start)->selectRaw("SUM({$signed}) as net")->first();
            $opening += (int) $prior->net;
        }

        $period = (clone $base)->when($month, fn ($q) => $q->whereBetween('txn_date', [$start, $end]));
        $ledger = $this->ledgerPage($period, 'txn_date', $signed, $opening, $page, $pageSize);

        return [
            'custody' => [
                'id' => $custody->id, 'custodianName' => $custody->custodian_name,
                'amountHalalas' => (int) $custody->amount, 'usedHalalas' => (int) $custody->used,
                'currentBalanceHalalas' => (int) $custody->amount - (int) $custody->used,
                'openingBalanceHalalas' => $opening,
                'closingBalanceHalalas' => $ledger['closingBalanceHalalas'],
            ],
            'period' => ['month' => $monthKey, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'transactions' => $ledger['transactions'],
            'meta' => $ledger['meta'],
        ];
    }

    /**
     * Custody txn type → UI label. A credit is money INTO the custody («وارد»,
     * incl. تعزيز عهدة); a debit is money OUT («صادر», a disbursement).
     *
     * @return array{key:string, labelAr:string}
     */
    public static function typeLabel(string $txnType): array
    {
        return $txnType === 'credit'
            ? ['key' => 'in', 'labelAr' => 'مدين - وارد']
            : ['key' => 'out', 'labelAr' => 'دائن - صادر'];
    }

    /**
     * @return array{0:\Illuminate\Support\Carbon,1:\Illuminate\Support\Carbon,2:string}
     */
    private function period(?string $month): array
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

            return [$start, (clone $start)->endOfMonth(), $month];
        }

        return [\Illuminate\Support\Carbon::createFromFormat('Y-m-d', '2000-01-01')->startOfDay(), now()->endOfMonth(), now()->format('Y-m')];
    }

    /** @param  Collection<int,?string>  $branchIds */
    public function branchNames(Collection $branchIds): array
    {
        $ids = $branchIds->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Branch::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Shared selection for the JSON register and its spreadsheet. Live ledger
     * precedence is decided within the authorized scope, before display filters.
     */
    public function register(Builder $legacyScope, Builder $branchScope, array $filters): array
    {
        $ledgerScope = $this->branchCustodiesFromLedger((clone $branchScope)->pluck('id'));
        $legacy = (clone $legacyScope)->where(fn ($q) => $q
            ->whereNull('branch_id')->orWhereNotIn('branch_id', $ledgerScope->pluck('branchId')));
        $branches = clone $branchScope;
        $this->brandBranches->applyFilter($legacy, $filters['brandId'] ?? null);
        $this->brandBranches->applyFilter($branches, $filters['brandId'] ?? null, 'id');
        if ($branch = $filters['branchId'] ?? null) {
            $legacy->where('branch_id', $branch);
            $branches->whereKey($branch);
        }
        $status = $filters['status'] ?? null;
        $this->applyStatusFilter($legacy, $status);
        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle !== '') {
            $branchIds = (clone $branches)->where('name', 'like', '%'.$needle.'%')->pluck('id');
            $legacy->where(fn ($q) => $q->where('custodian_name', 'like', '%'.$needle.'%')
                ->orWhereIn('branch_id', $branchIds));
        }

        $visibleIds = $branches->pluck('id')->flip();
        $search = mb_strtolower($needle);
        $ledger = $ledgerScope->filter(fn (array $card) => $visibleIds->has($card['branchId'])
            && (! $status || $card['status'] === $status)
            && ($search === ''
                || str_contains(mb_strtolower((string) $card['branchName']), $search)
                || str_contains(mb_strtolower($card['custodianName']), $search)))->values();

        return compact('ledgerScope', 'legacy', 'ledger');
    }

    /**
     * A branch with posted movements reads from the same ledger as the mobile
     * balance. Keep the legacy register as the fallback for branches without
     * ledger movements and for direct lookups by historical custody ID.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function branchCustodiesFromLedger(Collection $branchIds): Collection
    {
        $totals = CustodyTransaction::query()
            ->whereIn('branch_id', $branchIds)
            ->select('branch_id')
            ->selectRaw('SUM('.self::BRANCH_FLOW.') as balance, SUM('.self::BRANCH_FUNDING.') as funding')
            ->groupBy('branch_id')->orderBy('branch_id')->get();
        $branchNames = $this->branchNames($totals->pluck('branch_id'));
        $managerNames = BranchManager::query()
            ->whereIn('branch_id', $totals->pluck('branch_id'))
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('name', 'branch_id');

        return $totals->map(function (CustodyTransaction $total) use ($branchNames, $managerNames) {
            $balance = (int) $total->balance;
            $amount = max(0, (int) $total->funding ?: $balance);
            $used = max(0, $amount - $balance);
            $status = CustodyStatus::derive($balance, 0);

            return [
                'id' => 'branch-custody-'.$total->branch_id,
                'branchId' => $total->branch_id,
                'branchName' => $branchNames[$total->branch_id] ?? null,
                'custodianName' => $managerNames[$total->branch_id] ?? 'عُهدة الفرع',
                'amountHalalas' => (int) $amount,
                'usedHalalas' => $used,
                'remainingHalalas' => $balance,
                'minAlertHalalas' => 0,
                'usagePct' => $amount > 0 ? round($used / $amount * 100, 1) : 0.0,
                'status' => $status,
                'statusLabelAr' => CustodyStatus::labelAr($status),
                'daysSinceSettlement' => 0,
                'amount' => (int) $amount,
                'used' => $used,
                'remaining' => $balance,
                'source' => 'branch_custody_ledger',
            ];
        });
    }

    /** Add recent activity only for the ledger-backed cards on the requested page. */
    public function withRecentBranchTransactions(Collection $cards): Collection
    {
        if ($cards->isEmpty()) {
            return $cards;
        }

        $byBranch = CustodyTransaction::query()->whereIn('branch_id', $cards->pluck('branchId'))
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->groupLimit(20, 'branch_id')->get()->groupBy('branch_id');

        return $cards->map(function (array $card) use ($byBranch) {
            $card['transactions'] = $byBranch->get($card['branchId'], collect())
                ->map(fn (CustodyTransaction $t) => $this->branchTransaction($t))->values()->all();

            return $card;
        });
    }

    /** Monthly/paginated display, or the complete requested period for export. */
    public function branchLedgerFromTransactions(Branch $branch, ?string $month, int $page = 1, ?int $pageSize = 50): array
    {
        [$start, $end, $monthKey] = $this->period($month);
        $base = CustodyTransaction::query()->where('branch_id', $branch->id);
        $prior = $month ? "SUM(CASE WHEN transaction_date < ? THEN ".self::BRANCH_FLOW." ELSE 0 END)" : '0';
        $balances = (clone $base)
            ->selectRaw('SUM('.self::BRANCH_FLOW.') as balance, SUM('.self::BRANCH_FUNDING.') as funding')
            ->selectRaw("{$prior} as opening", $month ? [$start] : [])
            ->first();
        $opening = (int) $balances->opening;
        $current = (int) $balances->balance;
        $amount = max(0, (int) $balances->funding ?: $current);

        $period = (clone $base)->when($month, fn ($q) => $q->whereBetween('transaction_date', [$start, $end]));
        $ledger = $this->ledgerPage($period, 'transaction_date', self::BRANCH_FLOW, $opening, $page, $pageSize);
        $custodian = BranchManager::where('branch_id', $branch->id)->where('is_active', true)->orderByDesc('id')->value('name');

        return [
            'custody' => [
                'id' => 'branch-custody-'.$branch->id,
                'custodianName' => $custodian ?? 'عُهدة الفرع',
                'amountHalalas' => $amount,
                'usedHalalas' => max(0, $amount - $current),
                'currentBalanceHalalas' => $current,
                'openingBalanceHalalas' => $opening,
                'closingBalanceHalalas' => $ledger['closingBalanceHalalas'],
            ],
            'period' => ['month' => $monthKey, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'transactions' => $ledger['transactions'],
            'meta' => $ledger['meta'],
        ];
    }

    /**
     * Compute running balances over the full window before limiting the page.
     * A null page size is internal to export, which includes every matching row.
     */
    private function ledgerPage(Builder $period, string $date, string $signed, int $opening, int $page, ?int $pageSize): array
    {
        $summary = (clone $period)->selectRaw("COUNT(*) as total, SUM({$signed}) as net")->first();
        $total = (int) $summary->total;
        $size = $pageSize === null ? max(1, $total) : max(1, min($pageSize, 200));
        $page = max(1, $page);
        $query = $period->select('*')
            ->selectRaw("SUM({$signed}) OVER (ORDER BY {$date}, id ROWS UNBOUNDED PRECEDING) as running_net")
            ->orderByDesc($date)->orderByDesc('id');
        if ($pageSize !== null) {
            $query->forPage($page, $size);
        }

        $rows = $query->get()->map(function (CashTransaction|CustodyTransaction $t) use ($opening) {
            $row = $t instanceof CustodyTransaction
                ? $this->branchTransaction($t)
                : [
                    'id' => $t->id,
                    'txnDate' => $t->txn_date?->toIso8601String(),
                    'description' => $t->description,
                    'txnType' => $t->txn_type,
                    'amountHalalas' => $t->amount,
                    'amount' => $t->amount,
                    'status' => $t->status,
                    'source' => $t->source,
                ];
            $type = self::typeLabel($row['txnType']);

            return $row + [
                'typeLabel' => $type,
                'typeLabelAr' => $type['labelAr'],
                'runningBalanceHalalas' => $opening + (int) $t->running_net,
            ];
        });

        return [
            'closingBalanceHalalas' => $opening + (int) $summary->net,
            'transactions' => $rows->all(),
            'meta' => ['page' => $page, 'pageSize' => $size, 'total' => $total, 'totalPages' => (int) ceil($total / $size)],
        ];
    }

    private function branchTransaction(CustodyTransaction $t): array
    {
        $amount = (int) round((float) $t->amount * 100);

        return [
            'id' => $t->id,
            'txnDate' => $t->transaction_date?->toIso8601String(),
            'description' => $t->notes ?: $t->type,
            'txnType' => $t->is_cash_in ? 'credit' : 'debit',
            'amountHalalas' => $amount,
            'amount' => $amount,
            'status' => 'approved',
            'source' => 'branch_custody_ledger',
        ];
    }
}
