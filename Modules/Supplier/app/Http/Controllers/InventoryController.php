<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\Inventory\CreateProductRequest;
use Modules\Supplier\Http\Requests\Inventory\UpdateProductRequest;
use Modules\Supplier\Http\Requests\Inventory\UpdateStockRequest;
use Modules\Supplier\Models\SupplierProduct;
use Modules\Supplier\Services\InventoryService;
use Modules\Supplier\Transformers\ProductResource;

class InventoryController extends BaseController
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * Get products list
     */
    public function getProducts(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['search', 'is_available', 'quality_level']);
            $perPage = request()->get('per_page', 15);

            $products = $this->inventoryService->getProducts($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                ProductResource::collection($products),
                'Products retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching products');
        }
    }

    /**
     * Create new product
     */
    public function createProduct(CreateProductRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $product = $this->inventoryService->createProduct($supplier, $request->validated());

            return $this->createdResponse(
                new ProductResource($product),
                'Product created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating product');
        }
    }

    /**
     * Update product
     */
    public function updateProduct(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $product = SupplierProduct::findOrFail($id);

            $product = $this->inventoryService->updateProduct($product, $supplier, $request->validated());

            return $this->successResponse(
                new ProductResource($product),
                'Product updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating product');
        }
    }

    /**
     * Update stock levels
     */
    public function updateStock(UpdateStockRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $product = SupplierProduct::findOrFail($id);

            $inventory = $this->inventoryService->updateStock($product, $supplier, $request->validated());

            return $this->successResponse(
                $inventory,
                'Stock updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating stock');
        }
    }

    /**
     * Get low stock alerts
     */
    public function getLowStockAlerts(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $alerts = $this->inventoryService->getLowStockAlerts($supplier);

            return $this->successResponse($alerts, 'Low stock alerts retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching low stock alerts');
        }
    }
}
