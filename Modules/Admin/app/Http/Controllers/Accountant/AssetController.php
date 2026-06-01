<?php

namespace Modules\Admin\Http\Controllers\Accountant;

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
            $q = Asset::query();
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
                    'pendingAccountant' => Asset::where('status', 'pending_accountant')->count(),
                    'pendingBranch' => Asset::where('status', 'pending_branch')->count(),
                    'confirmed' => Asset::where('status', 'confirmed')->count(),
                    'bookValueTotal' => (int) Asset::sum('book_value'),
                ],
                'drafts' => AssetDraft::where('status', 'draft')->get()->map([$this, 'presentDraft'])->all(),
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
                'cost' => 'required|integer|min:0',
                'usefulLifeMonths' => 'required|integer|min:1',
                'custodian' => 'nullable|string|max:200',
            ]);
            $asset = Asset::create([
                'company_id' => $request->user()->company_id,
                'public_id' => $this->nextAssetId(),
                'name' => $data['name'],
                'category' => $data['category'],
                'branch_id' => $data['branchId'],
                'inv_num' => $data['invNum'] ?? null,
                'cost' => $data['cost'],
                'book_value' => $data['cost'],
                'useful_life_months' => $data['usefulLifeMonths'],
                'case_type' => 'acc_register',
                'status' => 'pending_branch',
                'custodian' => $data['custodian'] ?? null,
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
            $asset = Asset::findOrFail($id);
            $asset->update(['status' => 'confirmed']);

            return $this->ok($this->present($asset));
        });
    }

    public function drafts(): JsonResponse
    {
        return $this->run(fn () => $this->listResponse(AssetDraft::where('status', 'draft')->get()->map([$this, 'presentDraft'])->all()));
    }

    public function confirmDraft(string $draftId, \Modules\Admin\Services\RealtimeBroadcaster $rt): JsonResponse
    {
        return $this->run(function () use ($draftId, $rt) {
            $draft = AssetDraft::where('draft_id', $draftId)->orWhere('id', $draftId)->firstOrFail();

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

    public function discardDraft(string $draftId): JsonResponse
    {
        return $this->run(function () use ($draftId) {
            $draft = AssetDraft::where('draft_id', $draftId)->orWhere('id', $draftId)->firstOrFail();
            $draft->update(['status' => 'discarded']);

            return $this->noContent();
        });
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
            'bookValue' => $a->book_value,
            'usefulLifeMonths' => $a->useful_life_months,
            'status' => $a->status,
            'invNum' => $a->inv_num,
            'custodian' => $a->custodian,
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
