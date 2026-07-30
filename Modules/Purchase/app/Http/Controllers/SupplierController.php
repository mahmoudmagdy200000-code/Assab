<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Enums\SupplierStatus;
use Modules\Purchase\Transformers\SupplierResource;
use Modules\Supplier\Models\Supplier;

class SupplierController extends BaseController
{
    /**
     * Get suppliers list
     *
     * @group Suppliers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Meeting 2026-07-30: the order picker offers ONLY the brand's
            // account suppliers (can log in and receive orders) — Excel-uploaded
            // expense-only suppliers stay out. Fail-closed for unlinked branches.
            $orderableIds = app(\Modules\Expense\Services\SupplierBrandScopeService::class)
                ->orderableSupplierIds(auth()->user()?->branch_id);

            $query = Supplier::query()->active()->whereIn('id', $orderableIds);

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus(SupplierStatus::from($request->status));
            }

            // Filter by availability
            if ($request->boolean('available_only')) {
                $query->available();
            }

            // Filter by delivery time
            if ($request->has('max_delivery_hours')) {
                $query->byDeliveryTime((int) $request->max_delivery_hours);
            }

            // Filter by rating
            if ($request->has('min_rating')) {
                $query->byRating((float) $request->min_rating);
            }

            // Search
            if ($request->has('search')) {
                $query->search($request->search);
            }

            $suppliers = $query->orderBy('rating', 'desc')
                ->paginate($request->get('per_page', 15));

            return $this->paginatedResponse(
                SupplierResource::collection($suppliers),
                'Suppliers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching suppliers');
        }
    }

    /**
     * Get supplier details
     *
     * @group Suppliers
     */
    public function show(string $id): JsonResponse
    {
        try {
            $supplier = Supplier::with(['supplierItems', 'purchaseOrders'])
                ->find($id);

            if (! $supplier) {
                return $this->notFoundResponse('Supplier not found');
            }

            return $this->successResponse(
                new SupplierResource($supplier),
                'Supplier details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier details');
        }
    }

    /**
     * Get supplier items
     *
     * @group Suppliers
     */
    public function items(Request $request, string $id): JsonResponse
    {
        try {
            $supplier = Supplier::find($id);

            if (! $supplier) {
                return $this->notFoundResponse('Supplier not found');
            }

            $items = $supplier->supplierItems()
                ->when($request->boolean('available_only'), fn ($q) => $q->available())
                ->paginate($request->get('per_page', 15));

            return $this->paginatedResponse(
                $items,
                'Supplier items retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier items');
        }
    }

    /**
     * Get supplier order history
     *
     * @group Suppliers
     */
    public function orderHistory(Request $request, string $id): JsonResponse
    {
        try {
            $supplier = Supplier::find($id);

            if (! $supplier) {
                return $this->notFoundResponse('Supplier not found');
            }

            $orders = $supplier->purchaseOrders()
                ->with(['items', 'branch'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return $this->paginatedResponse(
                $orders,
                'Supplier order history retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier order history');
        }
    }

    /**
     * Get supplier statistics
     *
     * @group Suppliers
     */
    public function statistics(string $id): JsonResponse
    {
        try {
            $supplier = Supplier::find($id);

            if (! $supplier) {
                return $this->notFoundResponse('Supplier not found');
            }

            $stats = [
                'total_orders' => $supplier->total_orders,
                'completed_orders' => $supplier->completed_orders,
                'completion_rate' => $supplier->total_orders > 0
                    ? round(($supplier->completed_orders / $supplier->total_orders) * 100, 2)
                    : 0,
                'average_response_time_hours' => $supplier->average_response_time_hours,
                'response_rate_percentage' => $supplier->response_rate_percentage,
                'rating' => $supplier->rating,
                'status' => $supplier->status?->value,
                'last_seen_at' => $supplier->last_seen_at?->diffForHumans(),
            ];

            return $this->successResponse(
                $stats,
                'Supplier statistics retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier statistics');
        }
    }
}
