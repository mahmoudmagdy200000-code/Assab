<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AssetDraft;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\AssetEnums;
use Modules\Admin\Support\ExpenseEnums;
use Modules\Branch\Models\Branch;

/**
 * SRS ACC-2.4 / ACC-2.6 — the expense-invoice → fixed-asset conversion wizard
 * and the drafts panel beneath the expenses table.
 *
 * Two invariants this service exists to hold, both previously violated:
 *  1. An invoice converts **once**. The draft is linked back to its source
 *     invoice and the invoice is stamped «محوّل» in the same transaction.
 *  2. A draft confirms **once**. Re-POSTing confirm used to mint a second set
 *     of assets because the lookup never filtered on `status = 'draft'`.
 */
class AssetDraftService
{
    public function __construct(
        private readonly ExpenseInvoiceService $invoices,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    /**
     * Convert one invoice of an expenses statement into a pending asset draft.
     * The draft's value is the invoice's **pre-tax** amount — VAT is reclaimable
     * and never capitalised. A client-sent `amount` is a fallback only, for
     * statements whose invoice rows carry no amount.
     *
     * @param  array<string, mixed>  $data  validated wizard input
     * @return array<string, mixed> the presented draft
     */
    public function convert(Operation $op, int $invoiceIndex, array $data, AsabUser $actor): array
    {
        $this->invoices->assertNotConverted($op, $invoiceIndex);
        $invoice = $this->invoices->invoiceAt($op, $invoiceIndex);

        $preTax = ExpenseEnums::split(
            (int) ($invoice['amountHalalas'] ?? 0),
            isset($invoice['vatHalalas']) ? (int) $invoice['vatHalalas'] : null,
        )['preTaxHalalas'];
        $amount = $preTax > 0 ? $preTax : (int) ($data['amount'] ?? 0);
        $branchName = $op->branch_id ? Branch::whereKey($op->branch_id)->value('name') : null;

        $draft = DB::transaction(function () use ($op, $invoiceIndex, $invoice, $data, $actor, $amount, $branchName) {
            $draft = AssetDraft::create([
                'draft_id' => 'DRAFT-'.strtoupper(Str::random(8)),
                'company_id' => $actor->company_id,
                'expense_op_id' => $op->id,
                'inv_num' => $data['invNum'] ?? ($invoice['invNum'] ?? null),
                'vendor' => $data['vendor'] ?? ($invoice['vendor'] ?? null),
                'desc' => $invoice['desc'] ?? null,
                'amount' => $amount,
                'expense_branch' => $branchName,
                'expense_date' => $invoice['date'] ?? $op->operation_date,
                'asset_name' => $data['assetName'],
                'category' => $data['category'],
                'useful_life_months' => $data['usefulLifeMonths'],
                'target_branches' => $data['targetBranches'],
                'custodian' => $data['custodian'],
                'qty' => $data['qty'],
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by_id' => $actor->id,
            ]);

            $this->invoices->markConverted($op, $invoiceIndex, $draft->draft_id);

            return $draft;
        });

        foreach ($draft->target_branches ?: [null] as $branchId) {
            $this->rt->assetDraftCreated($draft, $branchId);
        }

        return $this->present($draft);
    }

    /**
     * Confirm a draft: mint `branches × qty` assets and close the draft. The
     * whole batch shares one transaction and one reserved id range, so a
     * concurrent create can never interleave ids.
     *
     * @return array<int, Asset>
     */
    public function confirm(AssetDraft $draft): array
    {
        $this->assertOpen($draft);

        $branches = $draft->target_branches ?: [null];
        $qty = max(1, (int) $draft->qty);
        $perAsset = (int) round($draft->amount / $qty);

        $created = AssetSequence::createBatch(
            $draft->company_id,
            count($branches) * $qty,
            fn (array $publicIds) => DB::transaction(function () use ($draft, $branches, $qty, $perAsset, $publicIds) {
                // Re-read under the write path: two confirms racing on the same
                // draft must not both pass the status guard.
                $fresh = AssetDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
                $this->assertOpen($fresh);

                $assets = [];
                foreach ($branches as $branchId) {
                    for ($i = 0; $i < $qty; $i++) {
                        $assets[] = Asset::create([
                            'company_id' => $draft->company_id,
                            'public_id' => array_shift($publicIds),
                            'name' => $draft->asset_name,
                            'category' => $draft->category,
                            'branch_id' => $branchId,
                            'inv_num' => $draft->inv_num,
                            'cost' => $perAsset,
                            'book_value' => $perAsset,
                            'useful_life_months' => $draft->useful_life_months,
                            'case_type' => 'acc_register',
                            'status' => 'pending_branch',
                            'custodian' => $draft->custodian,
                            'purchased_at' => $draft->expense_date ?? now(),
                        ]);
                    }
                }
                $fresh->update(['status' => 'confirmed', 'converted_at' => now()]);

                return $assets;
            }),
        );

        foreach ($created as $asset) {
            $this->rt->assetConfirmationNeeded($asset);
            if ($asset->branch_id !== null) {
                \Modules\Admin\Events\AssetAssignedToBranch::dispatch($asset);
            }
        }
        foreach ($branches as $branchId) {
            $this->rt->assetDraftConfirmed($draft->fresh(), $branchId);
        }

        return $created;
    }

    public function discard(AssetDraft $draft): void
    {
        $this->assertOpen($draft);
        $draft->update(['status' => 'discarded']);
    }

    /** @return array<string, mixed> the ACC-2.6 drafts-panel row */
    public function present(AssetDraft $d): array
    {
        return [
            'id' => $d->id,
            'draftId' => $d->draft_id,
            'expenseOpId' => $d->expense_op_id,
            'invNum' => $d->inv_num,
            'vendor' => $d->vendor,
            'desc' => $d->desc,
            'expenseBranch' => $d->expense_branch,
            'expenseDate' => optional($d->expense_date)->toIso8601String(),
            'assetName' => $d->asset_name,
            'category' => $d->category,
            'categoryLabelAr' => AssetEnums::categoryLabelAr($d->category),
            'amount' => $d->amount,
            'amountHalalas' => $d->amount,
            'usefulLifeMonths' => $d->useful_life_months,
            'annualDepreciationHalalas' => AssetEnums::annualDepreciation((int) $d->amount, $d->useful_life_months),
            'monthlyDepreciationHalalas' => AssetEnums::monthlyDepreciation((int) $d->amount, $d->useful_life_months),
            'qty' => $d->qty,
            'targetBranches' => $d->target_branches ?? [],
            'custodian' => $d->custodian,
            'notes' => $d->notes,
            'status' => $d->status,
            'statusLabelAr' => AssetEnums::draftStatusLabelAr($d->status),
            'createdAt' => optional($d->created_at)->toIso8601String(),
        ];
    }

    /** A draft leaves `draft` exactly once; every later transition is a 409. */
    private function assertOpen(AssetDraft $draft): void
    {
        if ($draft->status === 'draft') {
            return;
        }

        throw new AsabException(
            $draft->status === 'confirmed' ? 'DRAFT_ALREADY_CONFIRMED' : 'DRAFT_ALREADY_DISCARDED',
            'Asset draft is no longer open',
            $draft->status === 'confirmed' ? 'تم تأكيد المسودة مسبقاً' : 'تم تجاهل المسودة مسبقاً',
            409,
            ['draftId' => $draft->draft_id, 'status' => $draft->status],
        );
    }
}
