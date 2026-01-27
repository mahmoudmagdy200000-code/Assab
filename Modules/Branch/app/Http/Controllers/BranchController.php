<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Branch\Transformers\BranchResource;

class BranchController extends BaseController
{
    public function index(Request $request)
    {
        $manager = auth()->user();

        if (!$manager) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $branches = Branch::query()
            ->orderBy('name')
            ->paginate($request->input('per_page', 10));

        return $this->paginatedResponse(BranchResource::collection($branches), 'Branches retrieved successfully');
    }
}
