<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Expense\Services\ExpenseHelperService;
use Modules\Expense\Transformers\CategoryResource;

/**
 * Category Controller
 */
class CategoryController extends BaseController
{
    public function __construct(
        private ExpenseHelperService $helperService
    ) {}

    /**
     * Get all categories
     * GET /api/branch-manager/expenses/categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = $this->helperService->getCategories($request->input('search'));

        return $this->successResponse(
            'Categories retrieved successfully',
            $categories
        );
    }

    /**
     * Get category by ID
     * GET /api/branch-manager/expenses/categories/{category}
     */
    // public function show(int $category): JsonResponse
    // {
    //     $categoryModel = \Modules\Expense\Models\Category::with('parent', 'children')->findOrFail($category);

    //     return response()->json([
    //         'success' => true,
    //         'data' => new CategoryResource($categoryModel)
    //     ]);
    // }
}
