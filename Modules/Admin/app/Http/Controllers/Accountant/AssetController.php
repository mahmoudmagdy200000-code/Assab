<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AssetDraft;
use Modules\Admin\Services\AssetDraftService;
use Modules\Admin\Services\AssetSequence;
use Modules\Admin\Services\RealtimeBroadcaster;
use Modules\Admin\Support\AssetEnums;

/**
 * Fixed-assets register (SRS §4.2) and the expense→asset drafts panel (ACC-2.6).
 */
class AssetController extends AsabController
{
    public function __construct(private readonly AssetDraftService $drafts) {}

    /**
     * GET /assets — the register: KPI tiles (`meta.summary`), category pills and
     * search, plus the pending drafts panel (`meta.drafts`).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'search' => 'sometimes|string|max:120',
                'category' => 'sometimes|string|max:32',
                'status' => 'sometimes|string|max:24',
                'branchId' => 'sometimes|string',
                'page' => 'sometimes|integer|min:1',
                'pageSize' => 'sometimes|integer|min:1|max:100',
            ]);

            $q = $this->scopeToAssignedBranches(Asset::query());
            if (($status = $request->query('status')) && $status !== 'all') {
                $q->where('status', $status);
            }
            if (($category = $request->query('category')) && $category !== 'all') {
                $q->where('category', $category);
            }
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhere('custodian', 'like', "%{$search}%"));
            }

            $p = $q->orderByDesc('created_at')->paginate(
                min((int) $request->query('pageSize', 20), 100), ['*'], 'page', (int) $request->query('page', 1),
            );

            return $this->paginated($p, array_map([$this, 'present'], $p->items()), [
                'summary' => $this->summary(),
                'drafts' => $this->visibleDrafts($request)->map([$this->drafts, 'present'])->all(),
            ]);
        });
    }

    public function store(Request $request, RealtimeBroadcaster $rt): JsonResponse
    {
        return $this->run(function () use ($request, $rt) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'category' => 'required|string|max:32',
                'branchId' => 'required|string',
                'invNum' => 'nullable|string|max:64',
                'serial' => 'nullable|string|max:64',
                'purchaseDate' => 'sometimes|nullable|date',
                // 'cost' is the canonical field; 'priceHalalas' is the doc alias — accept either.
                'cost' => 'required_without:priceHalalas|integer|min:0',
                'priceHalalas' => 'required_without:cost|integer|min:0',
                'usefulLifeMonths' => ['required', 'integer', AssetEnums::usefulLifeRule()],
                'custodian' => 'nullable|string|max:200',
                'notes' => 'nullable|string',
            ]);
            $this->assertBranchAssigned($data['branchId']);
            $cost = $data['cost'] ?? $data['priceHalalas'];
            $companyId = $request->user()->company_id;

            $asset = AssetSequence::createOne($companyId, fn (string $publicId) => Asset::create([
                'company_id' => $companyId,
                'public_id' => $publicId,
                'name' => $data['name'],
                'category' => $data['category'],
                'branch_id' => $data['branchId'],
                'inv_num' => $data['invNum'] ?? null,
                'serial' => $data['serial'] ?? null,
                'cost' => $cost,
                'book_value' => $cost,
                'useful_life_months' => $data['usefulLifeMonths'],
                'case_type' => 'acc_register',
                'status' => 'pending_branch',
                'custodian' => $data['custodian'] ?? null,
                'notes' => $data['notes'] ?? null,
                'submitted_by_id' => $request->user()->id,
                'purchased_at' => $data['purchaseDate'] ?? now(),
            ]));
            $rt->assetConfirmationNeeded($asset);
            // Meeting 2026-07-30: the branch manager gets a real mobile receive
            // request + push, not just a dashboard socket event.
            if ($asset->branch_id !== null) {
                \Modules\Admin\Events\AssetAssignedToBranch::dispatch($asset);
            }

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
        return $this->run(fn () => $this->listResponse(
            $this->visibleDrafts($request)->map([$this->drafts, 'present'])->all(),
        ));
    }

    public function confirmDraft(Request $request, string $draftId): JsonResponse
    {
        return $this->run(function () use ($request, $draftId) {
            $created = $this->drafts->confirm($this->findDraft($request, $draftId));

            return $this->created(['createdAssets' => array_map([$this, 'present'], $created)]);
        });
    }

    public function discardDraft(Request $request, string $draftId): JsonResponse
    {
        return $this->run(function () use ($request, $draftId) {
            $this->drafts->discard($this->findDraft($request, $draftId));

            return $this->noContent();
        });
    }

    /** @return array<string, int> the register's KPI tiles */
    private function summary(): array
    {
        $tile = fn (callable $filter) => $filter($this->scopeToAssignedBranches(Asset::query()))->count();

        return [
            'pendingAccountant' => $tile(fn ($q) => $q->where('status', 'pending_accountant')),
            'pendingBranch' => $tile(fn ($q) => $q->where('status', 'pending_branch')),
            'confirmed' => $tile(fn ($q) => $q->where('status', 'confirmed')),
            'active' => $tile(fn ($q) => $q->where('status', 'active')),
            'maintenance' => $tile(fn ($q) => $q->where('status', 'maintenance')),
            'retired' => $tile(fn ($q) => $q->where('status', 'retired')),
            'total' => $tile(fn ($q) => $q),
            'bookValueTotal' => (int) $this->scopeToAssignedBranches(Asset::query())->sum('book_value'),
        ];
    }

    /** Pending drafts of the caller's company, filtered to their branch scope. */
    private function visibleDrafts(Request $request): \Illuminate\Support\Collection
    {
        $branchIds = $this->assignedBranchIds();

        return AssetDraft::where('company_id', $request->user()->company_id)
            ->where('status', 'draft')->orderByDesc('created_at')->limit(100)->get()
            ->filter(fn (AssetDraft $d) => $this->draftInScope($d, $branchIds))
            ->values();
    }

    /**
     * Company-scoped draft lookup; out-of-scope drafts read as absent (404).
     * Any status resolves — the lifecycle guard belongs to AssetDraftService, so
     * a confirmed draft answers 409 «تم تأكيد المسودة مسبقاً», not 404.
     */
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

    public function present(Asset $a): array
    {
        $cost = (int) $a->cost;

        return [
            'id' => $a->id,
            'publicId' => $a->public_id,
            'name' => $a->name,
            'category' => $a->category,
            'categoryLabelAr' => AssetEnums::categoryLabelAr($a->category),
            'branchId' => $a->branch_id,
            'cost' => $cost,
            'priceHalalas' => $cost,
            'bookValue' => $a->book_value,
            'bookValueHalalas' => $a->book_value,
            'usefulLifeMonths' => $a->useful_life_months,
            'monthlyDepreciationHalalas' => AssetEnums::monthlyDepreciation($cost, $a->useful_life_months),
            'annualDepreciationHalalas' => AssetEnums::annualDepreciation($cost, $a->useful_life_months),
            'status' => $a->status,
            'statusLabelAr' => AssetEnums::statusLabelAr($a->status),
            'invNum' => $a->inv_num,
            'serial' => $a->serial,
            'purchaseDate' => optional($a->purchased_at)->toIso8601String(),
            'custodian' => $a->custodian,
            'notes' => $a->notes,
        ];
    }
}
