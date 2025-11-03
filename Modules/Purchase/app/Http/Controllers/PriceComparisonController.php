<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Purchase\Services\PriceComparisonService;
use Illuminate\Http\Request;

class PriceComparisonController extends Controller
{
    public function __construct(
        private PriceComparisonService $priceComparisonService
    ) {}

    /**
     * Compare prices for items
     */
    public function compare(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $comparison = $this->priceComparisonService->compareItemPrices(
            $request->get('items'),
            auth()->user()->branch_id
        );

        return response()->json([
            'success' => true,
            'data' => $comparison,
        ]);
    }

    /**
     * Get price trends for last 3 months
     */
    public function trends(Request $request): JsonResponse
    {
        $request->validate([
            'item_id' => 'required|exists:items,id',
        ]);

        $trends = $this->priceComparisonService->getPriceTrends(
            $request->get('item_id'),
            auth()->user()->branch_id
        );

        return response()->json([
            'success' => true,
            'data' => $trends,
        ]);
    }
}
