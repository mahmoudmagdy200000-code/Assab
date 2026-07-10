<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AssetDraft;

/**
 * Accountant fixed assets + expense→asset drafts (BACKEND_API_SPEC.md §6.3.8).
 */
class AssetController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = $this->scopeToAssignedBranches(Asset::query());
            if (($status = $request->query('status')) && $status !== 'all') {
                $q->where('status', $status);
            }
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            $assets = $q->orderByDesc('created_at')->get();

            return $this->ok([
                'data' => $assets->map([$this, 'present'])->all(),
                'summary' => [
                    'pendingAccountant' => $this->scopeToAssignedBranches(Asset::where('status', 'pending_accountant'))->count(),
                    'pendingBranch' => $this->scopeToAssignedBranches(Asset::where('status', 'pending_branch'))->count(),
                    'confirmed' => $this->scopeToAssignedBranches(Asset::where('status', 'confirmed'))->count(),
                    'bookValueTotal' => (int) $this->scopeToAssignedBranches(Asset::query())->sum('book_value'),
                ],
                'drafts' => $this->visibleDrafts($request)->map([$this, 'presentDraft'])->all(),
            ]);
        });
    }

    public function store(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt): JsonResponse
    {
        return $this->run(function () use ($request, $rt) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'category' => 'required|string|max:32',
                'branchId' => 'required|string',
                'invNum' => 'nullable|string|max:64',
                // 'cost' is the canonical field; 'priceHalalas' is the doc alias — accept either.
                'cost' => 'required_without:priceHalalas|integer|min:0',
                'priceHalalas' => 'required_without:cost|integer|min:0',
                'usefulLifeMonths' => 'required|integer|min:1',
                'custodian' => 'nullable|string|max:200',
                'notes' => 'nullable|string',
            ]);
            $this->assertBranchAssigned($data['branchId']);
            $cost = $data['cost'] ?? $data['priceHalalas'];
            $asset = Asset::create([
                'company_id' => $request->user()->company_id,
                'public_id' => $this->nextAssetId(),
                'name' => $data['name'],
                'category' => $data['category'],
                'branch_id' => $data['branchId'],
                'inv_num' => $data['invNum'] ?? null,
                'cost' => $cost,
                'book_value' => $cost,
                'useful_life_months' => $data['usefulLifeMonths'],
                'case_type' => 'acc_register',
                'status' => 'pending_branch',
                'custodian' => $data['custodian'] ?? null,
                'notes' => $data['notes'] ?? null,
                'submitted_by_id' => $request->user()->id,
                'purchased_at' => now(),
            ]);
            $rt->assetConfirmationNeeded($asset);

            return $this->created($this->present($asset));
        });
    }

    public function confirm(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $asset = $this->scopeToAssignedBranches(Asset::query())->findOrFail($id);
            $asset->update(['status' => 'confirmed']);

            return $this->ok($this->present($asset));
        });
    }

    public function drafts(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->listResponse($this->visibleDrafts($request)->map([$this, 'presentDraft'])->all()));
    }

    public function confirmDraft(Request $request, string $draftId, \Modules\Admin\Services\RealtimeBroadcaster $rt): JsonResponse
    {
        return $this->run(function () use ($request, $draftId, $rt) {
            $draft = $this->findDraft($request, $draftId);

            $created = DB::transaction(function () use ($draft) {
                $assets = [];
                foreach (($draft->target_branches ?: [null]) as $branchId) {
                    for ($i = 0; $i < max(1, $draft->qty); $i++) {
                        $assets[] = Asset::create([
                            'company_id' => $draft->company_id,
                            'public_id' => $this->nextAssetId(),
                            'name' => $draft->asset_name,
                            'category' => $draft->category,
                            'branch_id' => $branchId,
                            'inv_num' => $draft->inv_num,
                            'cost' => (int) ($draft->amount / max(1, $draft->qty)),
                            'book_value' => (int) ($draft->amount / max(1, $draft->qty)),
                            'useful_life_months' => $draft->useful_life_months,
                            'case_type' => 'acc_register',
                            'status' => 'pending_branch',
                            'custodian' => $draft->custodian,
                        ]);
                    }
                }
                $draft->update(['status' => 'confirmed', 'converted_at' => now()]);

                return $assets;
            });

            foreach ($created as $asset) {
                $rt->assetConfirmationNeeded($asset);
            }
            foreach (($draft->target_branches ?: [null]) as $branchId) {
                $rt->assetDraftConfirmed($draft->fresh(), $branchId);
            }

            return $this->created(['createdAssets' => array_map([$this, 'present'], $created)]);
        });
    }

    public function discardDraft(Request $request, string $draftId): JsonResponse
    {
        return $this->run(function () use ($request, $draftId) {
            $draft = $this->findDraft($request, $draftId);
            $draft->update(['status' => 'discarded']);

            return $this->noContent();
        });
    }

    /** Pending drafts of the caller's company, filtered to their branch scope. */
    private function visibleDrafts(Request $request): \Illuminate\Support\Collection
    {
        $branchIds = $this->assignedBranchIds();

        return AssetDraft::where('company_id', $request->user()->company_id)
            ->where('status', 'draft')->get()
            ->filter(fn (AssetDraft $d) => $this->draftInScope($d, $branchIds))
            ->values();
    }

    /** Company-scoped draft lookup; out-of-scope drafts read as absent (404). */
    private function findDraft(Request $request, string $draftId): AssetDraft
    {
        $draft = AssetDraft::where('company_id', $request->user()->company_id)
            ->where(fn ($q) => $q->where('draft_id', $draftId)->orWhere('id', $draftId))
            ->firstOrFail();

        if (! $this->draftInScope($draft, $this->assignedBranchIds())) {
            throw (new ModelNotFoundException)->setModel(AssetDraft::class);
        }

        return $draft;
    }

    /** Scoped users only see drafts targeting at least one assigned branch. */
    private function draftInScope(AssetDraft $draft, ?array $branchIds): bool
    {
        return $branchIds === null || array_intersect($draft->target_branches ?? [], $branchIds) !== [];
    }

    private function nextAssetId(): string
    {
        return 'FA-'.str_pad((string) (Asset::count() + 1), 3, '0', STR_PAD_LEFT);
    }

    public function present(Asset $a): array
    {
        return [
            'id' => $a->id,
            'publicId' => $a->public_id,
            'name' => $a->name,
            'category' => $a->category,
            'branchId' => $a->branch_id,
            'cost' => $a->cost,
            'priceHalalas' => $a->cost,
            'bookValue' => $a->book_value,
            'bookValueHalalas' => $a->book_value,
            'usefulLifeMonths' => $a->useful_life_months,
            'status' => $a->status,
            'invNum' => $a->inv_num,
            'custodian' => $a->custodian,
            'notes' => $a->notes,
        ];
    }

    public function presentDraft(AssetDraft $d): array
    {
        return [
            'id' => $d->id,
            'draftId' => $d->draft_id,
            'assetName' => $d->asset_name,
            'category' => $d->category,
            'amount' => $d->amount,
            'qty' => $d->qty,
            'targetBranches' => $d->target_branches ?? [],
            'custodian' => $d->custodian,
            'status' => $d->status,
        ];
    }
}
