<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\PurchaseEnums;

/**
 * SRS ACC-3.3 — the purchases 3-way match. Turns a purchases operation's raw
 * payload (three historical shapes) into one canonical `purchaseItems[]` line
 * set, the ordered/received/invoice comparison, and the summary tiles.
 *
 * Match model — two legs live, a third reserved:
 *  - quantity: `ordQty` (PO) vs `rcvQty` (goods received). `rcvQty=null` means
 *    "not yet received", which is `pending`, NOT a shortfall.
 *  - price: `orderedUnitPriceHalalas` (PO) vs `unitPriceHalalas` (the accountant
 *    /invoice figure). Divergence is a real mismatch even when quantity agrees.
 *  - (future) invoiced quantity slots in beside the two price fields with no
 *    payload reshape.
 *
 * Nothing here is stored: the whole block is derived on read so it cannot drift
 * from the amounts. The one writer is recomputeMatch(), called by the line
 * editor.
 */
class PurchasePresenterService
{
    /** Quantities are decimal (kg/L); never compare with ===. */
    private const EPSILON = 0.0001;

    /**
     * The canonical line set for an operation, mapping every historical payload
     * shape:
     *  - procurement  `{items:[{itemId,qty,unitPriceHalalas?,totalHalalas?}]}`
     *  - branch req.  `{item,qty,unit}`  (single implicit line)
     *  - legacy       hydrated separately by PurchaseReceivingBridge
     *
     * @param  array<string, float>|null  $receivedByRow  rowId → received qty (legacy bridge)
     * @return array<int, array<string, mixed>>
     */
    public function lines(Operation $op, ?array $receivedByRow = null): array
    {
        $payload = $op->payload ?? [];

        // Already canonical (re-read after an edit).
        if (isset($payload['purchaseItems']) && is_array($payload['purchaseItems'])) {
            $raw = $payload['purchaseItems'];
        } elseif (isset($payload['items']) && is_array($payload['items'])) {
            $raw = $payload['items'];
        } elseif (isset($payload['item'])) {
            $raw = [['item' => $payload['item'], 'qty' => $payload['qty'] ?? 0, 'unit' => $payload['unit'] ?? null]];
        } else {
            $raw = [];
        }

        $lines = [];
        foreach (array_values($raw) as $index => $row) {
            $line = $this->normalizeLine($row, $index);
            if ($receivedByRow !== null && array_key_exists($line['rowId'], $receivedByRow)) {
                $line['rcvQty'] = (float) $receivedByRow[$line['rowId']];
            }
            $lines[] = $this->deriveLine($line);
        }

        return $lines;
    }

    /**
     * The ACC-3.3 detail block. `$attachments`/`$supplierName` are injectable so
     * a list surface can batch them and avoid N+1.
     *
     * @return array<string, mixed>
     */
    public function present(Operation $op, ?array $receivedByRow = null, ?string $supplierName = null, ?array $attachments = null): array
    {
        $lines = $this->lines($op, $receivedByRow);
        $payload = $op->payload ?? [];

        return [
            'supplierId' => $payload['supplierId'] ?? null,
            'supplierName' => $supplierName ?? $this->supplierName($op),
            'orderSource' => $this->orderSource($op),
            'urgency' => $payload['urgency'] ?? 'normal',
            'deliveryDate' => $payload['deliveryDate'] ?? null,
            'description' => $payload['description'] ?? ($payload['notes'] ?? null),
            'purchaseItems' => $lines,
            'summary' => $this->summary($lines, $op),
            'isDocumented' => ! empty($payload['documentation']['documentedAt']),
            'documentation' => $payload['documentation'] ?? null,
            'attachments' => $attachments ?? $this->attachments($op),
        ];
    }

    /** The list-row projection (ACC-3.2) — no per-line detail. */
    public function row(Operation $op, ?string $supplierName = null): array
    {
        $lines = $this->lines($op);
        $summary = $this->summary($lines);
        $payload = $op->payload ?? [];

        return [
            'supplierId' => $payload['supplierId'] ?? null,
            'supplierName' => $supplierName ?? $this->supplierName($op),
            'orderSource' => $this->orderSource($op),
            'receiveDate' => $payload['deliveryDate'] ?? null,
            'itemCount' => count($lines),
            'orderedTotalHalalas' => $summary['orderedValueHalalas'],
            'receivedTotalHalalas' => $summary['receivedValueHalalas'],
            'hasDiff' => $summary['qtyDiscrepancyCount'] > 0 || $summary['priceDiscrepancyCount'] > 0,
            'isDocumented' => ! empty($payload['documentation']['documentedAt']),
        ];
    }

    /**
     * Recompute op `amount` (ordered value) and `match`/`diff_note` from a line
     * set — the single mutation point, called by the line editor inside its
     * transaction. Returns the derived {match, diffNote, amount}.
     *
     * @param  array<int, array<string,mixed>>  $lines  canonical (deriveLine'd) lines
     * @return array{match:string, diffNote:?string, amount:int, purchaseItems:array}
     */
    public function recomputeMatch(array $lines): array
    {
        $amount = array_sum(array_column($lines, 'totalHalalas'));

        $anyDiff = false;
        $anyPending = false;
        $notes = [];
        foreach ($lines as $line) {
            if ($line['lineMatch']['key'] === 'diff') {
                $anyDiff = true;
                if ($line['diffNoteAr'] !== null) {
                    $notes[] = $line['diffNoteAr'];
                }
            } elseif ($line['lineMatch']['key'] === 'pending') {
                $anyPending = true;
            }
        }

        $match = $anyDiff ? 'diff' : ($anyPending ? 'review' : 'exact');

        return [
            'match' => $match,
            'diffNote' => $notes === [] ? null : implode(' · ', $notes),
            'amount' => $amount,
            'purchaseItems' => $lines,
        ];
    }

    /** ACC-3 order-source, from the payload/legacy hints (T06.6). */
    public function orderSource(Operation $op): array
    {
        $payload = $op->payload ?? [];

        $key = match (true) {
            ($payload['kind'] ?? null) === 'branch_request' => 'branch',
            ($payload['orderSource'] ?? null) !== null => $payload['orderSource'],
            ($payload['orderType'] ?? null) !== null => PurchaseEnums::ORDER_TYPE_TO_SOURCE[$payload['orderType']] ?? 'procurement',
            ($op->origin === 'procurement' || ($payload['origin'] ?? null) === 'procurement') => 'procurement',
            ($payload['supplierId'] ?? null) !== null => 'supplier',
            default => 'procurement',
        };

        return PurchaseEnums::orderSource($key);
    }

    /**
     * Normalize one raw payload row to the pre-match canonical shape. Both price
     * legs start equal — the invoice/accountant price only diverges once edited.
     *
     * @return array<string, mixed>
     */
    private function normalizeLine(array $row, int $index): array
    {
        $rowId = (string) ($row['rowId'] ?? $row['itemId'] ?? $row['id'] ?? $index);
        $ordQty = (float) ($row['ordQty'] ?? $row['qty'] ?? $row['quantity'] ?? 0);
        $rcvQty = array_key_exists('rcvQty', $row) && $row['rcvQty'] !== null ? (float) $row['rcvQty'] : null;

        $unitPrice = (int) ($row['unitPriceHalalas'] ?? $this->unitFromTotal($row, $ordQty));
        $orderedUnitPrice = (int) ($row['orderedUnitPriceHalalas'] ?? $unitPrice);

        return [
            'rowId' => $rowId,
            'item' => $row['item'] ?? $row['itemName'] ?? $row['name'] ?? null,
            'itemId' => $row['itemId'] ?? $row['id'] ?? null,
            'unit' => $row['unit'] ?? $row['unitOfMeasurement'] ?? null,
            'ordQty' => $ordQty,
            'rcvQty' => $rcvQty,
            'unitPriceHalalas' => $unitPrice,
            'orderedUnitPriceHalalas' => $orderedUnitPrice,
        ];
    }

    /** Derive the match legs + line total from a normalized line. */
    private function deriveLine(array $line): array
    {
        $ordQty = (float) $line['ordQty'];
        $rcvQty = $line['rcvQty'];
        $unitPrice = (int) $line['unitPriceHalalas'];
        $orderedUnitPrice = (int) $line['orderedUnitPriceHalalas'];

        $received = $rcvQty !== null;
        $qtyMatched = $received ? abs($rcvQty - $ordQty) < self::EPSILON : null;
        $priceMatched = $unitPrice === $orderedUnitPrice;
        $diffQty = $received ? round($rcvQty - $ordQty, 3) : null;

        $lineMatchKey = match (true) {
            ! $received => 'pending',
            $qtyMatched === false || ! $priceMatched => 'diff',
            default => 'matched',
        };

        return $line + [
            'totalHalalas' => (int) round($ordQty * $unitPrice),
            'receivedValueHalalas' => $received ? (int) round($rcvQty * $unitPrice) : null,
            'diffQty' => $diffQty,
            'qtyMatched' => $qtyMatched,
            'priceMatched' => $priceMatched,
            'received' => $received,
            'lineMatch' => PurchaseEnums::lineMatch($lineMatchKey),
            'diffNoteAr' => $this->lineDiffNote($line['item'], $line['unit'], $diffQty, $priceMatched, $orderedUnitPrice, $unitPrice),
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $lines
     * @param  Operation|null  $op  supplies the stored order total so the block can
     *                              state the VAT explicitly — the line items are NET
     *                              while `operations.amount` is VAT-inclusive, so the
     *                              detail card showed two totals differing by exactly
     *                              15% with nothing on screen to explain the gap
     *                              (prod E2E 2026-07-31, 3/3 purchase operations).
     */
    private function summary(array $lines, ?Operation $op = null): array
    {
        $ordered = array_sum(array_column($lines, 'totalHalalas'));
        $received = array_sum(array_map(fn ($l) => $l['receivedValueHalalas'] ?? 0, $lines));
        $qtyDiff = count(array_filter($lines, fn ($l) => $l['qtyMatched'] === false));
        $priceDiff = count(array_filter($lines, fn ($l) => ! $l['priceMatched']));
        $pending = count(array_filter($lines, fn ($l) => ! $l['received']));

        // Only when the stored total genuinely exceeds the net lines — a partially
        // mapped payload must not invent a VAT figure out of a rounding gap.
        $storedTotal = $op !== null ? (int) $op->amount : 0;
        $vat = $storedTotal > $ordered ? $storedTotal - $ordered : null;

        return [
            'lineCount' => count($lines),
            'orderedValueHalalas' => $ordered,
            'receivedValueHalalas' => $received,
            'vatHalalas' => $vat,
            'orderedValueWithVatHalalas' => $vat === null ? $ordered : $storedTotal,
            'qtyDiscrepancyCount' => $qtyDiff,
            'priceDiscrepancyCount' => $priceDiff,
            'pendingReceiptCount' => $pending,
            'isMatched' => $lines !== [] && $qtyDiff === 0 && $priceDiff === 0 && $pending === 0,
            'hasMismatch' => $qtyDiff > 0 || $priceDiff > 0,
        ];
    }

    private function lineDiffNote(?string $item, ?string $unit, ?float $diffQty, bool $priceMatched, int $orderedPrice, int $price): ?string
    {
        $parts = [];
        if ($diffQty !== null && abs($diffQty) >= self::EPSILON) {
            $verb = $diffQty < 0 ? 'نقص' : 'زيادة';
            $parts[] = "{$verb} في الكمية: ".$this->qty(abs($diffQty)).' '.($unit ?? '').' ('.($item ?? '—').')';
        }
        if (! $priceMatched) {
            $delta = ($price - $orderedPrice) / 100;
            $parts[] = 'فرق سعر: '.number_format($delta, 2).' ر.س للوحدة';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function unitFromTotal(array $row, float $ordQty): int
    {
        $total = (int) ($row['totalHalalas'] ?? 0);

        return $total > 0 && $ordQty > self::EPSILON ? (int) round($total / $ordQty) : 0;
    }

    /**
     * A mobile purchase order carries a LEGACY supplier id, which never resolves
     * against asab_suppliers — so the lookup returned null and the detail panel
     * showed «—» while the list row (which reads the payload directly) showed the
     * real name (prod E2E 2026-07-31, all 3 PUR ops). Fall back to the name the
     * bridge already stored rather than leaving the panel blank.
     */
    private function supplierName(Operation $op): ?string
    {
        $id = $op->payload['supplierId'] ?? null;
        $resolved = $id ? AsabSupplier::where('id', $id)->value('name') : null;

        return $resolved ?? ($op->payload['supplierName'] ?? null);
    }

    /** @return array<int, array<string, mixed>> */
    private function attachments(Operation $op): array
    {
        return Attachment::where('owner_id', $op->id)->orderBy('uploaded_at')->get()->map(fn (Attachment $a) => [
            'id' => $a->id,
            'filename' => $a->filename,
            'mimeType' => $a->mime_type,
            'publicUrl' => $a->public_url,
            'label' => $a->label,
            'verifiedAt' => optional($a->verified_at)->toIso8601String(),
        ])->all();
    }

    private function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
