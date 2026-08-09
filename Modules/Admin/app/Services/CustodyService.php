<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Support\CustodyStatus;
use Modules\Branch\Models\Branch;

/**
 * SRS §7 ACC-8 / §8 HEAD-4 — cash-custody presentation + health. Owns the
 * normal/low/critical derivation, persists it on every balance change, and
 * builds the ACC-8.1 KPI block. Balance math (apply/reverse a txn) lives in the
 * controllers that own the txn lifecycle; this service is the read/status core.
 */
class CustodyService
{
    public function __construct(private readonly RealtimeBroadcaster $rt) {}

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

    /**
     * ACC-8.1 KPI block over a scoped custody set.
     *
     * @param  Collection<int,CashCustody>  $custodies
     * @return array{activeCustodies:int, pendingRequests:int, nearDepletion:int}
     */
    public function kpis(Collection $custodies): array
    {
        $ids = $custodies->pluck('id');
        $pendingSettlements = $ids->isEmpty() ? 0 : SettlementRequest::whereIn('custody_id', $ids)->where('status', 'pending')->count();
        $pendingTxns = $ids->isEmpty() ? 0 : CashTransaction::whereIn('custody_id', $ids)->where('status', 'pending')->count();

        return [
            'activeCustodies' => $custodies->count(),
            'pendingRequests' => $pendingSettlements + $pendingTxns,
            'nearDepletion' => $custodies->filter(
                fn (CashCustody $c) => CustodyStatus::isNearDepletion((int) $c->amount - (int) $c->used)
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
    public function ledger(CashCustody $custody, ?string $month, int $page = 1, int $pageSize = 50): array
    {
        $pageSize = max(1, min($pageSize, 200));
        [$start, $end, $monthKey] = $this->period($month);

        $base = CashTransaction::where('custody_id', $custody->id);
        $approvedCredits = (int) (clone $base)->where('status', 'approved')->where('txn_type', 'credit')->sum('amount');
        $initialAmount = (int) $custody->amount - $approvedCredits;

        // Opening = initial amount + net flow of approved txns before the window.
        $opening = $initialAmount;
        if ($month) {
            $priorCredit = (int) (clone $base)->where('status', 'approved')->where('txn_type', 'credit')->where('txn_date', '<', $start)->sum('amount');
            $priorDebit = (int) (clone $base)->where('status', 'approved')->where('txn_type', 'debit')->where('txn_date', '<', $start)->sum('amount');
            $opening = $initialAmount + $priorCredit - $priorDebit;
        }

        $asc = (clone $base)
            ->when($month, fn ($q) => $q->whereBetween('txn_date', [$start, $end]))
            ->orderBy('txn_date')->orderBy('id')->limit(2000)->get();
        $total = $asc->count();

        $running = $opening;
        $all = [];
        foreach ($asc as $t) {
            if ($t->status === 'approved') {
                $running += $t->txn_type === 'credit' ? (int) $t->amount : -(int) $t->amount;
            }
            $type = self::typeLabel($t->txn_type);
            $all[] = [
                'id' => $t->id,
                'txnDate' => optional($t->txn_date)->toIso8601String(),
                'description' => $t->description,
                'txnType' => $t->txn_type,
                'typeLabel' => $type,
                'typeLabelAr' => $type['labelAr'],
                'amountHalalas' => (int) $t->amount,
                'amount' => (int) $t->amount,
                'status' => $t->status,
                'source' => $t->source,
                'runningBalanceHalalas' => $running,
            ];
        }
        $rows = array_slice(array_reverse($all), ($page - 1) * $pageSize, $pageSize);
        $remaining = (int) $custody->amount - (int) $custody->used;

        return [
            'custody' => [
                'id' => $custody->id, 'custodianName' => $custody->custodian_name,
                'amountHalalas' => (int) $custody->amount, 'usedHalalas' => (int) $custody->used,
                'currentBalanceHalalas' => $remaining,
            ],
            'period' => ['month' => $monthKey, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'transactions' => $rows,
            'meta' => [
                'page' => $page, 'pageSize' => $pageSize, 'total' => $total,
                'totalPages' => (int) ceil($total / max(1, $pageSize)),
            ],
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
}
