<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Http\Requests\CreateOrderFromComparisonRequest;
use Modules\BranchManagers\Http\Requests\ExportPriceComparisonRequest;
use Modules\BranchManagers\Http\Requests\ListPriceComparisonsRequest;
use Modules\BranchManagers\Services\BranchManagerPriceComparisonService;
use Modules\BranchManagers\Transformers\PriceComparisonDetailsResource;
use Modules\BranchManagers\Transformers\PriceComparisonListItemResource;
use Modules\Purchase\Exceptions\PurchaseOrderException;

/**
 * Branch Manager — Price Comparison screen.
 *
 * Routes are guarded by the `branch.manager` middleware, so the authenticated
 * user is always an active BranchManager bound to a branch. Every read and
 * write is scoped to that branch (tenant isolation in the service layer).
 */
class BranchManagerPriceComparisonController extends BaseController
{
    public function __construct(
        private readonly BranchManagerPriceComparisonService $service
    ) {}

    /**
     * GET /branch-manager/price-comparisons
     */
    public function index(ListPriceComparisonsRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);

        $comparisons = $this->service->list(
            $request->user()->branch_id,
            $request->filters(),
            $perPage
        );

        return $this->paginatedResponse(
            PriceComparisonListItemResource::collection($comparisons),
            'Saved price comparisons retrieved successfully'
        );
    }

    /**
     * GET /branch-manager/price-comparisons/{comparisonId}
     */
    public function show(string $comparisonId): JsonResponse
    {
        $comparison = $this->service->find($comparisonId, auth()->user()->branch_id);

        if (! $comparison) {
            return $this->notFoundResponse('Price comparison not found');
        }

        return $this->successResponse(
            new PriceComparisonDetailsResource($comparison),
            'Price comparison retrieved successfully'
        );
    }

    /**
     * GET /branch-manager/price-comparisons/{comparisonId}/export
     */
    public function export(ExportPriceComparisonRequest $request, string $comparisonId): JsonResponse
    {
        $comparison = $this->service->find($comparisonId, $request->user()->branch_id);

        if (! $comparison) {
            return $this->notFoundResponse('Price comparison not found');
        }

        try {
            $path = $this->service->export($comparison, $request->validated()['formatType']);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'exporting price comparison');
        }

        return $this->successResponse(
            ['url' => asset('storage/'.$path)],
            'Price comparison exported successfully'
        );
    }

    /**
     * POST /branch-manager/price-comparisons/{comparisonId}/orders
     */
    public function createOrder(CreateOrderFromComparisonRequest $request, string $comparisonId): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $comparison = $this->service->find($comparisonId, $branchId);

        if (! $comparison) {
            return $this->notFoundResponse('Price comparison not found');
        }

        try {
            $orders = $this->service->createOrderFromComparison(
                $comparison,
                $branchId,
                $request->user()->getKey(),
                $request->overrides()
            );
        } catch (\InvalidArgumentException|PurchaseOrderException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'creating order from price comparison');
        }

        return $this->createdResponse(
            $orders->map(fn ($order) => $this->orderSummary($order))->values(),
            'Order created from price comparison successfully'
        );
    }

    /**
     * Compact summary of a created purchase order for the API response.
     */
    private function orderSummary($order): array
    {
        $value = static fn ($enum) => $enum instanceof \BackedEnum ? $enum->value : $enum;

        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'orderType' => $value($order->order_type),
            'status' => $value($order->status),
        ];
    }
}
