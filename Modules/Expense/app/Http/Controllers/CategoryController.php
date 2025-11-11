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
            $categories,
            'Categories retrieved successfully',
        );
    }

    /**
     * Get category by ID
     * GET /api/branch-manager/expenses/categories/{category}
     */
    public function show(string $category): JsonResponse
    {
        $categoryModel = \Modules\Expense\Models\Category::with('parent', 'children')->findOrFail($category);

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($categoryModel)
        ]);
    }

    /**
     * Create category
     * POST /api/branch-manager/expenses/categories
     */
    public function store(Request $request): JsonResponse
    {
        // Validation
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'type' => 'required|in:purchase,expense',
            'is_active' => 'required|boolean',
        ]);
        $category = $this->helperService->createCategory($request->all());

        return $this->successResponse(
            $category,
            'Category created successfully',
        );
    }
    /**
     * Update category
     * PUT /api/branch-manager/expenses/categories/{category}
     */
    public function update(Request $request, string $category): JsonResponse
    {

        // Validation
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'type' => 'required|in:purchase,expense',
            'is_active' => 'required|boolean',
        ]);

        $categoryModel = $this->helperService->updateCategory($category, $request->all());

        return $this->successResponse(
            $categoryModel,
            'Category updated successfully',
        );
    }
    /**
     * Delete category
     * DELETE /api/branch-manager/expenses/categories/{category}
     */
    public function destroy(string $category): JsonResponse
    {
        $this->helperService->deleteCategory($category);

        return $this->successResponse(
            null,
            'Category deleted successfully',
        );
    }
}
