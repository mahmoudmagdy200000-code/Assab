<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\ExpenseEnums;

/**
 * SRS ACC-2 — an expenses operation is a **statement** carrying one or more
 * invoices. Everything the accountant does (توثيق, review, convert-to-asset)
 * happens per invoice, not per statement.
 *
 * The stored shape lives at `payload.invoices[]` and holds only facts:
 * what the branch entered, what the accountant read off the document, and the
 * verify/convert stamps. VAT, match status and totals are *derived on read* so
 * they can never drift from the amounts.
 *
 * Statements that predate this contract (a single invoice smeared across
 * payload root keys) are folded into a one-row `invoices[]` on the fly, so old
 * rows render in the new UI without a data migration.
 */
class ExpenseInvoiceService
{
    /** Fields the accountant may overwrite from the attached document (review modal). */
    private const DOCUMENT_FIELDS = ['documentAmountHalalas', 'documentVendor', 'documentInvNum', 'documentDate'];

    /**
     * Validation for `POST branch/upload/expenses` (T05.1). The statement total
     * is derived from these rows, never trusted from the client.
     *
     * @return array<string, string>
     */
    public function uploadRules(): array
    {
        return [
            'invoices' => 'required|array|min:1',
            'invoices.*.invNum' => 'required|string|max:64',
            'invoices.*.vendor' => 'required|string|max:200',
            'invoices.*.desc' => 'required|string|max:500',
            'invoices.*.date' => 'required|date',
            'invoices.*.amountHalalas' => 'required|integer|min:0',
            'invoices.*.vatHalalas' => 'sometimes|integer|min:0',
            'invoices.*.attachments' => 'sometimes|array',
        ];
    }

    /** The statement total: the sum of the invoices' tax-inclusive amounts. */
    public function statementTotal(array $invoices): int
    {
        return array_sum(array_map(fn ($i) => (int) ($i['amountHalalas'] ?? 0), $invoices));
    }

    /**
     * The stored rows for an operation, back-filling a legacy single-invoice
     * statement from the payload root. Never returns computed fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stored(Operation $op): array
    {
        $payload = $op->payload ?? [];
        $invoices = $payload['invoices'] ?? null;

        if (is_array($invoices) && $invoices !== []) {
            return array_values($invoices);
        }

        return [array_filter([
            'invNum' => $payload['invNum'] ?? null,
            'vendor' => $payload['vendor'] ?? null,
            'desc' => $payload['desc'] ?? ($payload['description'] ?? null),
            'date' => $payload['date'] ?? optional($op->operation_date)->toDateString(),
            'amountHalalas' => (int) $op->amount,
            'verified' => $payload['verified'] ?? false,
            'verifiedAt' => $payload['verifiedAt'] ?? null,
            'verifiedBy' => $payload['verifiedBy'] ?? null,
            'convertedToAsset' => $payload['convertedToAsset'] ?? false,
            'attachments' => $payload['attachments'] ?? [],
        ], fn ($v) => $v !== null)];
    }

    /**
     * The ACC-2 statement block: every invoice with its VAT split, verify stamp,
     * match badge and attachments, plus the statement totals.
     *
     * @param  array<int, array<string,mixed>>|null  $attachmentsByIndex  preloaded to avoid N+1 in list surfaces
     * @return array<string, mixed>
     */
    public function present(Operation $op, ?array $attachmentsByIndex = null): array
    {
        $stored = $this->stored($op);
        $attachmentsByIndex ??= $this->attachmentRows($op);

        $invoices = [];
        foreach ($stored as $index => $invoice) {
            $invoices[] = $this->presentInvoice($invoice, $index, $attachmentsByIndex[$index] ?? []);
        }

        $verifiedCount = count(array_filter($invoices, fn ($i) => $i['verified']));
        $allVerified = $invoices !== [] && $verifiedCount === count($invoices);

        return [
            'invoices' => $invoices,
            'totals' => [
                'invoiceCount' => count($invoices),
                'preTaxHalalas' => array_sum(array_column($invoices, 'preTaxHalalas')),
                'vat15Halalas' => array_sum(array_column($invoices, 'vat15Halalas')),
                'inclTaxHalalas' => array_sum(array_column($invoices, 'inclTaxHalalas')),
            ],
            'verifiedCount' => $verifiedCount,
            'allVerified' => $allVerified,
            'allVerifiedBadgeAr' => $allVerified ? ExpenseEnums::ALL_VERIFIED_BADGE_AR : null,
            'matchSummary' => $this->matchSummary($invoices),
            'isLocked' => $op->status === Operation::STATUS_FINAL,
        ];
    }

    /**
     * ACC-2.3 review modal: entered vs attached-document values, side by side.
     *
     * @return array<string, mixed>
     */
    public function review(Operation $op, int $index): array
    {
        $invoice = $this->presentInvoice(
            $this->invoiceAt($op, $index), $index, $this->attachmentRows($op)[$index] ?? [],
        );

        $rows = [
            ['field' => 'invNum', 'labelAr' => 'رقم الفاتورة', 'entered' => $invoice['invNum'], 'document' => $invoice['documentInvNum']],
            ['field' => 'vendor', 'labelAr' => 'المورد', 'entered' => $invoice['vendor'], 'document' => $invoice['documentVendor']],
            ['field' => 'date', 'labelAr' => 'التاريخ', 'entered' => $invoice['date'], 'document' => $invoice['documentDate']],
            ['field' => 'amountHalalas', 'labelAr' => 'المبلغ شامل الضريبة', 'entered' => $invoice['amountHalalas'], 'document' => $invoice['documentAmountHalalas']],
        ];

        return [
            'invoice' => $invoice,
            'rows' => array_map(
                fn ($r) => $r + ['matches' => $r['document'] === null || $r['document'] === $r['entered']],
                $rows,
            ),
            'deltaHalalas' => $invoice['deltaHalalas'],
            'deltaNoteAr' => $invoice['deltaNoteAr'],
        ];
    }

    /** ACC-2.2 توثيق — stamp one invoice. */
    public function verify(Operation $op, int $index, AsabUser $actor): array
    {
        return $this->stampVerification($op, $index, [
            'verified' => true,
            'verifiedAt' => now()->toIso8601String(),
            'verifiedBy' => $actor->id,
        ]);
    }

    public function unverify(Operation $op, int $index): array
    {
        return $this->stampVerification($op, $index, [
            'verified' => false,
            'verifiedAt' => null,
            'verifiedBy' => null,
        ]);
    }

    /** ACC-2.3 — record what the accountant read off the attached document. */
    public function setDocument(Operation $op, int $index, array $document): array
    {
        $this->assertMutable($op);
        $this->invoiceAt($op, $index);

        $patch = array_intersect_key($document, array_flip(self::DOCUMENT_FIELDS));
        $this->writeInvoice($op, $index, $patch);

        return $this->review($op->fresh(), $index);
    }

    /**
     * ACC-2.4 — flag the invoice as converted so it can never mint a second
     * asset draft. Runs inside the caller's transaction.
     */
    public function markConverted(Operation $op, int $index, string $draftId): void
    {
        $this->writeInvoice($op, $index, [
            'convertedToAsset' => true,
            'assetDraftId' => $draftId,
            'convertedAt' => now()->toIso8601String(),
        ]);
    }

    /** @throws AsabException 422 when the index addresses no invoice */
    public function invoiceAt(Operation $op, int $index): array
    {
        $invoices = $this->stored($op);
        if (! array_key_exists($index, $invoices)) {
            throw new AsabException(
                'INVOICE_INDEX_OUT_OF_RANGE',
                'Invoice index out of range',
                'رقم الفاتورة خارج النطاق',
                422,
                ['invoiceIndex' => $index, 'invoiceCount' => count($invoices)],
            );
        }

        return $invoices[$index];
    }

    public function assertNotConverted(Operation $op, int $index): void
    {
        if (! empty($this->invoiceAt($op, $index)['convertedToAsset'])) {
            throw new AsabException(
                'INVOICE_ALREADY_CONVERTED',
                'Invoice already converted to an asset',
                'الفاتورة محوّلة مسبقاً',
                409,
                ['invoiceIndex' => $index],
            );
        }
    }

    /**
     * Attachments per invoice. A row of `asab_attachments` belongs to invoice
     * `i` when its label is prefixed `invoice:i`; anything else is a
     * statement-level document and lands under key `-1`.
     *
     * @param  \Illuminate\Support\Collection<int, Attachment>|null  $rows  preloaded rows (one query for many operations)
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function attachmentRows(Operation $op, $rows = null): array
    {
        $rows ??= Attachment::where('owner_id', $op->id)->orderBy('uploaded_at')->get();

        $byIndex = [];
        foreach ($this->stored($op) as $index => $invoice) {
            $byIndex[$index] = array_map(
                fn ($a) => is_array($a) ? $a : ['publicUrl' => $a, 'filename' => basename((string) $a)],
                array_values($invoice['attachments'] ?? []),
            );
        }

        // A statement with exactly one invoice cannot be ambiguous: its
        // unlabelled documents are that invoice's documents.
        $statementIndex = count($byIndex) === 1 ? 0 : -1;

        foreach ($rows as $row) {
            $index = $this->invoiceIndexOfLabel($row->label);
            $byIndex[$index ?? $statementIndex][] = [
                'id' => $row->id,
                'filename' => $row->filename,
                'mimeType' => $row->mime_type,
                'size' => $row->size,
                'publicUrl' => $row->public_url,
                'label' => $row->label,
                'invoiceIndex' => $index,
                'verifiedAt' => optional($row->verified_at)->toIso8601String(),
                'uploadedAt' => optional($row->uploaded_at)->toIso8601String(),
            ];
        }

        return $byIndex;
    }

    /**
     * Per-invoice derived view. `matchStatus` (ACC-2.1/2.3):
     * no document attached → `missing`; a document amount that disagrees with
     * the entered amount → `mismatch`; otherwise `matched`.
     *
     * @return array<string, mixed>
     */
    private function presentInvoice(array $invoice, int $index, array $attachments): array
    {
        $amount = (int) ($invoice['amountHalalas'] ?? 0);
        $split = ExpenseEnums::split($amount, isset($invoice['vatHalalas']) ? (int) $invoice['vatHalalas'] : null);
        $documentAmount = isset($invoice['documentAmountHalalas']) ? (int) $invoice['documentAmountHalalas'] : null;

        $matchStatus = match (true) {
            $attachments === [] => 'missing',
            $documentAmount !== null && $documentAmount !== $amount => 'mismatch',
            default => 'matched',
        };
        $delta = $matchStatus === 'mismatch' ? $documentAmount - $amount : null;

        return [
            'index' => $index,
            'invNum' => $invoice['invNum'] ?? null,
            'vendor' => $invoice['vendor'] ?? null,
            'desc' => $invoice['desc'] ?? null,
            'date' => $invoice['date'] ?? null,
            'amountHalalas' => $amount,
            'preTaxHalalas' => $split['preTaxHalalas'],
            'vat15Halalas' => $split['vat15Halalas'],
            'inclTaxHalalas' => $split['inclTaxHalalas'],
            'verified' => (bool) ($invoice['verified'] ?? false),
            'verifiedAt' => $invoice['verifiedAt'] ?? null,
            'verifiedBy' => $invoice['verifiedBy'] ?? null,
            'convertedToAsset' => (bool) ($invoice['convertedToAsset'] ?? false),
            'convertedLabelAr' => ! empty($invoice['convertedToAsset']) ? ExpenseEnums::CONVERTED_LABEL_AR : null,
            'assetDraftId' => $invoice['assetDraftId'] ?? null,
            'documentAmountHalalas' => $documentAmount,
            'documentVendor' => $invoice['documentVendor'] ?? null,
            'documentInvNum' => $invoice['documentInvNum'] ?? null,
            'documentDate' => $invoice['documentDate'] ?? null,
            'matchStatus' => $matchStatus,
            'matchLabelAr' => ExpenseEnums::matchLabelAr($matchStatus),
            'matchIcon' => ExpenseEnums::MATCH[$matchStatus]['icon'],
            'deltaHalalas' => $delta,
            'deltaNoteAr' => $delta === null ? null : '⚠ فرق: '.$this->sar(abs($delta)).' ر.س عن الفاتورة الأصلية',
            'attachments' => $attachments,
            'attachmentCount' => count($attachments),
        ];
    }

    /** @return array{matched:int, mismatch:int, missing:int} */
    public function matchSummary(array $presentedInvoices): array
    {
        $counts = ['matched' => 0, 'mismatch' => 0, 'missing' => 0];
        foreach ($presentedInvoices as $invoice) {
            $counts[$invoice['matchStatus']]++;
        }

        return $counts;
    }

    private function stampVerification(Operation $op, int $index, array $stamp): array
    {
        $this->assertMutable($op);
        $this->invoiceAt($op, $index);
        $this->writeInvoice($op, $index, $stamp);

        $presented = $this->present($op->fresh());

        return [
            'operationId' => $op->id,
            'invoiceIndex' => $index,
            'verified' => $stamp['verified'],
            'verifiedAt' => $stamp['verifiedAt'],
            'allVerified' => $presented['allVerified'],
            'allVerifiedBadgeAr' => $presented['allVerifiedBadgeAr'],
            'verifiedCount' => $presented['verifiedCount'],
            'invoiceCount' => $presented['totals']['invoiceCount'],
        ];
    }

    /**
     * Merge `$patch` into one invoice and keep the payload-root mirrors that the
     * exports still read (`verified` = every invoice verified, `convertedToAsset`
     * = any invoice converted).
     */
    private function writeInvoice(Operation $op, int $index, array $patch): void
    {
        DB::transaction(function () use ($op, $index, $patch) {
            $payload = $op->payload ?? [];
            $invoices = $this->stored($op);
            $invoices[$index] = array_merge($invoices[$index], $patch);

            $payload['invoices'] = $invoices;
            $payload['verified'] = ! array_filter($invoices, fn ($i) => empty($i['verified']));
            $payload['convertedToAsset'] = (bool) array_filter($invoices, fn ($i) => ! empty($i['convertedToAsset']));

            $op->update(['payload' => $payload]);
        });
    }

    /** A final-approved statement is immutable (SRS §5.2). */
    private function assertMutable(Operation $op): void
    {
        if ($op->status === Operation::STATUS_FINAL) {
            throw new AsabException(
                'OP_ALREADY_FINAL',
                'Operation is final-approved',
                'العملية معتمدة نهائياً',
                409,
            );
        }
    }

    /** `invoice:2:receipt` → 2; anything else → null (statement-level). */
    private function invoiceIndexOfLabel(?string $label): ?int
    {
        if ($label === null || ! str_starts_with($label, 'invoice:')) {
            return null;
        }
        $index = explode(':', $label)[1] ?? '';

        return ctype_digit($index) ? (int) $index : null;
    }

    private function sar(int $halalas): string
    {
        return number_format($halalas / 100, 2);
    }
}
