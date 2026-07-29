<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Modules\Admin\Services\MobileBranchScopeService;
use Modules\Branch\Models\Branch;
use Modules\Branch\Transformers\BranchResource;

class BranchController extends BaseController
{
    public function __construct(
        private readonly MobileBranchScopeService $scope
    ) {}

    /**
     * Branch picker for the mobile app (add-cashier, filters).
     *
     * Brand-isolated: returns the branches of the caller's OWN brand only. It
     * used to return every branch of every brand to any authenticated user.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (! $user) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $branches = Branch::query()
            ->whereIn('id', $this->scope->visibleBranchIds($user))
            ->when($request->input('search'), fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->input('per_page', 10));

        return $this->paginatedResponse(BranchResource::collection($branches), 'Branches retrieved successfully');
    }
}
