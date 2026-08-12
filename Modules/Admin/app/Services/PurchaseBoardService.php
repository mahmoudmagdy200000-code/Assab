<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\OperationEnums;
use Modules\Branch\Models\Branch;

/**
 * ACC-3 «موديول المشتريات» — the grouped purchases board.
 *
 * The flat operations list answered «which purchase orders exist»; the screen
 * the client signed off asks a different question: «what did each MORD/branch
 * cost me, and which of its invoices still disagree». So this service folds the
 * purchases operations into cards — by supplier (default) or by branch — each
 * carrying its invoices, its invoices' lines, and the price movement per line
 * («آخر سعر وصول» + the ▲/▼ delta against the previous arrival).
 *
 * Reads only. Every figure is derived on read so it cannot drift from the
 * operations; the two writers are the line editor and the line-level توثيق,
 * both in AccountantController.
 *
 * Bounded by design: the aggregate scans at most MAX_OPERATIONS operations and
 * each card returns at most MAX_INVOICES_PER_GROUP invoices — both report the
 * truncation rather than silently cutting the tail.
 */
class PurchaseBoardService
{
    /** Hard cap on the operations one board request will aggregate. */
    public const MAX_OPERATIONS = 1000;

    /** Hard cap on the invoices returned inside one card. */
    public const MAX_INVOICES_PER_GROUP = 50;

    /** How far back «آخر سعر وصول» looks for a previous arrival of the item. */
    private const PRICE_HISTORY_LIMIT = 2000;

    public function __construct(
        private readonly PurchasePresenterService $presenter,
        private readonly PurchaseReceivingBridge $receiving,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /**
     * @param  string[]|null  $assignedBranchIds  null = unrestricted
     * @param  array{groupBy?:?string, brandId?:?string, branchId?:?string, supplierId?:?string,
     *               status?:?string, match?:?string, documented?:?string, q?:?string,
     *               dateFrom?:?string, dateTo?:?string, page?:int, pageSize?:int}  $filters
     * @return array{groups:array, kpis:array, meta:array}
     */
    public function board(string|array $companyId, ?array $assignedBranchIds, array $filters): array
    {
        $groupBy = ($filters['groupBy'] ?? 'supplier') === 'branch' ? 'branch' : 'supplier';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min((int) ($filters['pageSize'] ?? 20), 100));

        $ops = $this->fetch($companyId, $assignedBranchIds, $filters);
        $truncated = $ops->count() >= self::MAX_OPERATIONS;

        // Names for every op in the set — two queries, never per row.
        $branchNames = $this->branchNames($ops->pluck('branch_id'));
        $supplierNames = $this->supplierNames($ops);

        $kpis = $this->kpis($ops, $filters);

        $grouped = $ops->groupBy(fn (Operation $op) => $groupBy === 'branch'
            ? ((string) ($op->branch_id ?? 'unknown'))
            : ((string) ($op->payload['supplierId'] ?? $op->payload['supplierName'] ?? 'unknown')));

        $cards = $grouped
            ->map(fn ($rows, $key) => $this->card((string) $key, $rows->values(), $groupBy, $branchNames, $supplierNames, $filters))
            ->sortByDesc('totalHalalas')
            ->values();

        $total = $cards->count();
        $slice = $cards->slice(($page - 1) * $pageSize, $pageSize)->values();

        // Lines are hydrated only for the invoices actually returned, and for
        // the whole page at once — three queries, not three per card.
        $slice = $this->hydrate($slice, $companyId, $ops);

        return [
            'groups' => $slice->all(),
            'kpis' => $kpis,
            'meta' => [
                'groupBy' => $groupBy,
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
                'scannedOperations' => $ops->count(),
                // Explicit rather than a silent tail cut.
                'truncated' => $truncated,
                'maxOperations' => self::MAX_OPERATIONS,
            ],
        ];
    }

    /**
     * The filtered purchases operations, newest first.
     *
     * @param  string[]|null  $assignedBranchIds
     * @return \Illuminate\Support\Collection<int, Operation>
     */
    private function fetch(string|array $companyId, ?array $assignedBranchIds, array $filters)
    {
        $q = Operation::query()
            ->whereIn('company_id', (array) $companyId)
            ->where('module_key', 'purchases');

        // Zero-trust: the caller's assigned branches bound everything below.
        if ($assignedBranchIds !== null) {
            $q->whereIn('branch_id', $assignedBranchIds);
        }
        // Brand → branches through the restaurant too (2026-08-03 rule).
        $this->brandBranches->applyFilter($q, $filters['brandId'] ?? null);

        if (! empty($filters['branchId'])) {
            $q->where('branch_id', $filters['branchId']);
        }
        if (! empty($filters['supplierId'])) {
            $q->where('payload->supplierId', $filters['supplierId']);
        }
        if (! empty($filters['status'])) {
            $q->whereIn('status', explode(',', (string) $filters['status']));
        }
        if (! empty($filters['match'])) {
            $q->whereIn('match', explode(',', (string) $filters['match']));
        }
        if (! empty($filters['dateFrom'])) {
            $q->whereDate('operation_date', '>=', $filters['dateFrom']);
        }
        if (! empty($filters['dateTo'])) {
            $q->whereDate('operation_date', '<=', $filters['dateTo']);
        }

        // public_id breaks the tie: two invoices uploaded in the same second
        // would otherwise come back in whatever order the engine chose, and the
        // cards would reshuffle between two identical requests.
        $ops = $q->orderByDesc('operation_date')->orderByDesc('created_at')->orderByDesc('public_id')
            ->limit(self::MAX_OPERATIONS)->get();

        // «بحث (فرع / مورد / منتج / مطعم)» spans a JSON payload and a joined
        // branch name, so it is applied in memory over the bounded set.
        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle !== '') {
            $branchNames = $this->branchNames($ops->pluck('branch_id'));
            $ops = $ops->filter(fn (Operation $op) => $this->matchesNeedle($op, $needle, $branchNames))->values();
        }

        // «موثّق / غير موثّق» is derived from the payload, not a column.
        if (isset($filters['documented']) && $filters['documented'] !== null && $filters['documented'] !== '') {
            $want = filter_var($filters['documented'], FILTER_VALIDATE_BOOLEAN);
            $ops = $ops->filter(fn (Operation $op) => $this->isDocumented($op) === $want)->values();
        }

        return $ops;
    }

    private function matchesNeedle(Operation $op, string $needle, array $branchNames): bool
    {
        $payload = $op->payload ?? [];
        $haystack = [
            (string) $op->public_id,
            (string) ($payload['supplierName'] ?? ''),
            (string) ($payload['orderNumber'] ?? ''),
            (string) ($branchNames[$op->branch_id] ?? ''),
        ];
        foreach (($payload['purchaseItems'] ?? $payload['items'] ?? []) as $row) {
            $haystack[] = (string) ($row['item'] ?? $row['itemName'] ?? $row['name'] ?? '');
        }

        foreach ($haystack as $value) {
            if ($value !== '' && mb_stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The four header tiles. Computed over the whole filtered set, so they do
     * not change when the user pages through the cards.
     *
     * @param  \Illuminate\Support\Collection<int, Operation>  $ops
     */
    private function kpis($ops, array $filters): array
    {
        $suppliers = $ops->map(fn (Operation $op) => $op->payload['supplierId'] ?? $op->payload['supplierName'] ?? null)
            ->filter()->unique();

        return [
            'totalPurchasesHalalas' => (int) $ops->sum('amount'),
            'totalPurchasesSar' => round((int) $ops->sum('amount') / 100, 2),
            'pendingInvoices' => $ops->where('status', Operation::STATUS_PENDING)->count(),
            'diffInvoices' => $ops->where('match', 'diff')->count(),
            'activeSuppliers' => $suppliers->count(),
            'invoices' => $ops->count(),
            'period' => [
                'from' => $filters['dateFrom'] ?? null,
                'to' => $filters['dateTo'] ?? null,
            ],
        ];
    }

    /**
     * One card (supplier or branch) with its aggregates + invoice headers.
     *
     * @param  \Illuminate\Support\Collection<int, Operation>  $ops
     */
    private function card(string $key, $ops, string $groupBy, array $branchNames, array $supplierNames, array $filters): array
    {
        $total = (int) $ops->sum('amount');
        $first = $ops->first();
        $payload = $first->payload ?? [];

        $invoices = $ops->take(self::MAX_INVOICES_PER_GROUP)
            ->map(fn (Operation $op) => $this->invoiceHeader($op, $branchNames, $supplierNames))
            ->values();

        // The card's secondary chips: the OTHER axis, with its own subtotal
        // («3 فروع» on a supplier card, «2 مورد» on a branch card).
        $chips = $ops->groupBy(fn (Operation $op) => $groupBy === 'branch'
            ? ((string) ($op->payload['supplierId'] ?? $op->payload['supplierName'] ?? 'unknown'))
            : ((string) ($op->branch_id ?? 'unknown')))
            ->map(fn ($rows, $chipKey) => [
                'id' => $chipKey === 'unknown' ? null : $chipKey,
                'name' => $groupBy === 'branch'
                    ? ($supplierNames[$chipKey] ?? $rows->first()->payload['supplierName'] ?? '—')
                    : ($branchNames[$chipKey] ?? '—'),
                'invoiceCount' => $rows->count(),
                'totalHalalas' => (int) $rows->sum('amount'),
                'totalSar' => round((int) $rows->sum('amount') / 100, 2),
            ])->sortByDesc('totalHalalas')->values()->all();

        return [
            'key' => $key === 'unknown' ? null : $key,
            'groupBy' => $groupBy,
            'supplierId' => $groupBy === 'supplier' ? ($key === 'unknown' ? null : $key) : null,
            'supplierName' => $groupBy === 'supplier'
                ? ($supplierNames[$key] ?? $payload['supplierName'] ?? '—')
                : null,
            'branchId' => $groupBy === 'branch' ? ($key === 'unknown' ? null : $key) : null,
            'branchName' => $groupBy === 'branch' ? ($branchNames[$key] ?? '—') : null,
            'title' => $groupBy === 'branch'
                ? ($branchNames[$key] ?? '—')
                : ($supplierNames[$key] ?? $payload['supplierName'] ?? '—'),
            'invoiceCount' => $ops->count(),
            'counterpartCount' => count($chips),
            'totalHalalas' => $total,
            'totalSar' => round($total / 100, 2),
            'diffCount' => $ops->where('match', 'diff')->count(),
            'pendingCount' => $ops->where('status', Operation::STATUS_PENDING)->count(),
            'documentedCount' => $ops->filter(fn (Operation $op) => $this->isDocumented($op))->count(),
            'dailyAverageHalalas' => $this->dailyAverage($total, $ops, $filters),
            'counterparts' => $chips,
            // Legacy-friendly aliases so a card renders without branching on groupBy.
            'branches' => $groupBy === 'supplier' ? $chips : [],
            'suppliers' => $groupBy === 'branch' ? $chips : [],
            'invoices' => $invoices->all(),
            'invoicesTruncated' => $ops->count() > self::MAX_INVOICES_PER_GROUP,
        ];
    }

    /** «يومياً X ر.س» — the card total spread over the days it actually spans. */
    private function dailyAverage(int $total, $ops, array $filters): int
    {
        $from = ! empty($filters['dateFrom']) ? Carbon::parse($filters['dateFrom']) : $this->opDate($ops->last());
        $to = ! empty($filters['dateTo']) ? Carbon::parse($filters['dateTo']) : $this->opDate($ops->first());

        if ($from === null || $to === null) {
            return $total;
        }
        $days = max(1, $from->diffInDays($to) + 1);

        return (int) round($total / $days);
    }

    private function opDate(?Operation $op): ?Carbon
    {
        $raw = $op?->operation_date ?? $op?->created_at;

        return $raw === null ? null : Carbon::parse($raw);
    }

    /** The invoice row — no line detail (that is added for the returned page). */
    private function invoiceHeader(Operation $op, array $branchNames, array $supplierNames): array
    {
        $payload = $op->payload ?? [];
        $supplierId = $payload['supplierId'] ?? null;
        $items = $payload['purchaseItems'] ?? $payload['items'] ?? [];
        $names = array_values(array_filter(array_map(
            fn ($row) => $row['item'] ?? $row['itemName'] ?? $row['name'] ?? null,
            is_array($items) ? $items : [],
        )));

        return [
            'id' => $op->id,
            'publicId' => $op->public_id,
            // «رقم الفاتورة» — the branch/supplier document number when present.
            'invoiceNumber' => $payload['invoiceNumber'] ?? $payload['orderNumber'] ?? $op->public_id,
            'branchId' => $op->branch_id,
            'branchName' => $branchNames[$op->branch_id] ?? '—',
            'supplierId' => $supplierId,
            'supplierName' => ($supplierId ? ($supplierNames[$supplierId] ?? null) : null) ?? ($payload['supplierName'] ?? '—'),
            'orderSource' => $this->presenter->orderSource($op),
            'date' => optional($op->operation_date)->toDateString(),
            'createdAt' => optional($op->created_at)->toIso8601String(),
            'status' => $op->status,
            'statusLabelAr' => OperationEnums::status($op->status)['labelAr'] ?? $op->status,
            'match' => $op->match,
            'matchLabelAr' => OperationEnums::match($op->match)['labelAr'] ?? $op->match,
            'diffNote' => $op->diff_note,
            'itemCount' => count($names),
            'itemsPreview' => implode('، ', array_slice($names, 0, 3)),
            'totalHalalas' => (int) $op->amount,
            'totalSar' => round((int) $op->amount / 100, 2),
            'isDocumented' => $this->isDocumented($op),
            'attachmentsCount' => (int) $op->attachment_count,
        ];
    }

    /**
     * Fill in every returned invoice's lines + price movement. Batched across
     * the whole page: one receiving lookup, one attachments query and one
     * price-history query for all the cards together.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $cards
     * @param  \Illuminate\Support\Collection<int, Operation>  $scanned  the filtered set
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function hydrate($cards, string|array $companyId, $scanned)
    {
        $ids = [];
        foreach ($cards as $card) {
            $ids = array_merge($ids, array_column($card['invoices'], 'id'));
        }
        if ($ids === []) {
            return $cards;
        }

        // Reuse the models already loaded for the aggregate — no second read.
        $ops = $scanned->whereIn('id', $ids)->keyBy('id');
        $received = $this->receiving->receivedByRowForMany($ops->values()->all());
        $attachments = $this->attachmentsFor($ids);
        $history = $this->priceHistory($companyId, $ops->values()->all());

        return $cards->map(function (array $card) use ($ops, $received, $attachments, $history) {
            $card['invoices'] = array_map(function (array $invoice) use ($ops, $received, $attachments, $history) {
                $op = $ops->get($invoice['id']);
                if ($op === null) {
                    return $invoice;
                }

                $lines = $this->presenter->lines($op, $received[$op->id] ?? null);
                $documented = 0;
                $rows = [];
                foreach ($lines as $line) {
                    $row = $this->line($line, $op, $history);
                    $documented += $row['documented'] ? 1 : 0;
                    $rows[] = $row;
                }

                $ordered = array_sum(array_column($rows, 'totalHalalas'));
                $stored = (int) $op->amount;
                $vat = $stored > $ordered ? $stored - $ordered : null;

                return $invoice + [
                    'lines' => $rows,
                    'lineCount' => count($rows),
                    'documentedLineCount' => $documented,
                    'documentedCaption' => $documented.'/'.count($rows).' موثّق',
                    'linesTotalHalalas' => $ordered,
                    'linesTotalSar' => round($ordered / 100, 2),
                    'vatHalalas' => $vat,
                    'vatSar' => $vat === null ? null : round($vat / 100, 2),
                    'attachments' => $attachments[$op->id] ?? [],
                    'attachmentsCount' => count($attachments[$op->id] ?? []),
                ];
            }, $card['invoices']);

            return $card;
        });
    }

    /**
     * One table row: the presenter's derived line + «آخر سعر وصول» and its
     * delta, + the line-level توثيق checkbox state.
     *
     * @param  array<string, array<int, array{date:string, price:int}>>  $history
     */
    private function line(array $line, Operation $op, array $history): array
    {
        $unitPrice = (int) $line['unitPriceHalalas'];
        $last = $this->lastArrivalPrice($line, $op, $history);
        $delta = $last === null ? null : $unitPrice - $last;

        $doc = $line['documentation'] ?? null;
        $qty = $line['rcvQty'] ?? $line['ordQty'];

        return [
            'rowId' => $line['rowId'],
            'itemId' => $line['itemId'],
            'item' => $line['item'],
            'unit' => $line['unit'],
            'ordQty' => $line['ordQty'],
            'rcvQty' => $line['rcvQty'],
            // «الكمية» column: what actually arrived, else what was ordered.
            'qty' => $qty,
            'unitPriceHalalas' => $unitPrice,
            'unitPriceSar' => round($unitPrice / 100, 2),
            'orderedUnitPriceHalalas' => (int) $line['orderedUnitPriceHalalas'],
            'totalHalalas' => (int) $line['totalHalalas'],
            'totalSar' => round((int) $line['totalHalalas'] / 100, 2),
            // «آخر سعر وصول» — the same item's price on this supplier's previous
            // invoice. null = first arrival, so the FE hides the delta chip.
            'lastArrivalPriceHalalas' => $last,
            'lastArrivalPriceSar' => $last === null ? null : round($last / 100, 2),
            'priceDeltaHalalas' => $delta,
            'priceDeltaSar' => $delta === null ? null : round($delta / 100, 2),
            'priceDirection' => $delta === null ? 'unknown' : ($delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same')),
            'lineMatch' => $line['lineMatch'],
            'diffNoteAr' => $line['diffNoteAr'],
            'documented' => $doc !== null && ! empty($doc['documentedAt']),
            'documentedAt' => $doc['documentedAt'] ?? null,
            'documentedBy' => $doc['documentedBy'] ?? null,
        ];
    }

    /**
     * @param  array<string, array<int, array{date:string, price:int}>>  $history
     */
    private function lastArrivalPrice(array $line, Operation $op, array $history): ?int
    {
        $key = $this->historyKey($op->payload['supplierId'] ?? null, $line['itemId'] ?? null, $line['item'] ?? null);
        $entries = $history[$key] ?? [];
        if ($entries === []) {
            return null;
        }

        $on = optional($op->operation_date)->toDateTimeString() ?? '9999-12-31 00:00:00';
        $best = null;
        foreach ($entries as $entry) {
            // Strictly earlier — the invoice being read is never its own «last».
            if ($entry['id'] !== $op->id && $entry['date'] <= $on) {
                $best = $entry['price'];
            }
        }

        return $best;
    }

    /**
     * Arrival prices of the items on these invoices, oldest first, for every
     * invoice dated at or before the newest one on the page. One query.
     *
     * Bounded to the most recent PRICE_HISTORY_LIMIT rows and then re-sorted
     * ascending: a company with years of purchases must keep the RECENT prices,
     * which an ascending `limit` would have thrown away.
     *
     * @param  Operation[]  $ops
     * @return array<string, array<int, array{id:string, date:string, price:int}>>
     */
    private function priceHistory(string|array $companyId, array $ops): array
    {
        $supplierIds = [];
        $newest = null;
        foreach ($ops as $op) {
            $supplierId = $op->payload['supplierId'] ?? null;
            if ($supplierId !== null) {
                $supplierIds[$supplierId] = true;
            }
            $date = optional($op->operation_date)->toDateTimeString();
            if ($date !== null && ($newest === null || $date > $newest)) {
                $newest = $date;
            }
        }
        if ($supplierIds === []) {
            return [];
        }

        $rows = Operation::query()
            ->whereIn('company_id', (array) $companyId)
            ->where('module_key', 'purchases')
            ->when($newest !== null, fn ($q) => $q->where('operation_date', '<=', $newest))
            ->orderByDesc('operation_date')
            ->limit(self::PRICE_HISTORY_LIMIT)
            ->get(['id', 'operation_date', 'payload'])
            ->sortBy(fn (Operation $row) => optional($row->operation_date)->toDateTimeString() ?? '');

        $out = [];
        foreach ($rows as $row) {
            $supplierId = $row->payload['supplierId'] ?? null;
            if ($supplierId === null || ! isset($supplierIds[$supplierId])) {
                continue;
            }
            $date = optional($row->operation_date)->toDateTimeString() ?? '';
            foreach ($this->presenter->lines($row) as $line) {
                $key = $this->historyKey($supplierId, $line['itemId'] ?? null, $line['item'] ?? null);
                $out[$key][] = ['id' => $row->id, 'date' => $date, 'price' => (int) $line['unitPriceHalalas']];
            }
        }

        return $out;
    }

    /** An item is identified by its id when it has one, else by its name. */
    private function historyKey(?string $supplierId, ?string $itemId, ?string $itemName): string
    {
        return ($supplierId ?? '-').'|'.($itemId ?? mb_strtolower(trim((string) $itemName)));
    }

    /**
     * @param  string[]  $operationIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function attachmentsFor(array $operationIds): array
    {
        return Attachment::whereIn('owner_id', $operationIds)
            ->orderBy('uploaded_at')->get()
            ->groupBy('owner_id')
            ->map(fn ($rows) => $rows->map(fn (Attachment $a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mimeType' => $a->mime_type,
                'publicUrl' => $a->public_url,
                'label' => $a->label,
                'verifiedAt' => optional($a->verified_at)->toIso8601String(),
            ])->all())
            ->all();
    }

    private function isDocumented(Operation $op): bool
    {
        return ! empty(($op->payload['documentation'] ?? [])['documentedAt']);
    }

    /** @param  \Illuminate\Support\Collection<int, ?string>  $ids */
    private function branchNames($ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Branch::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @param  \Illuminate\Support\Collection<int, Operation>  $ops */
    private function supplierNames($ops): array
    {
        $ids = $ops->map(fn (Operation $op) => $op->payload['supplierId'] ?? null)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : AsabSupplier::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
