<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Purchase\Http\Requests\CreateGoodsReceiptRequest;
use Modules\Purchase\Services\GoodsReceiptService;
use Illuminate\Http\Request;
use Modules\Purchase\Transformers\GoodsReceiptResource;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private GoodsReceiptService $goodsReceiptService
    ) {}

    /**
     * Get goods receipt list
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->get('status'), // in_progress, draft, missing, completed
            'type' => $request->get('type'),
            'branch_id' => auth()->user()->branch_id,
        ];

        $receipts = $this->goodsReceiptService->getReceiptsList($filters);

        return response()->json([
            'success' => true,
            'data' => GoodsReceiptResource::collection($receipts),
        ]);
    }

    /**
     * Create goods receipt
     */
    public function store(CreateGoodsReceiptRequest $request): JsonResponse
    {
        $receipt = $this->goodsReceiptService->createReceipt(
            $request->validated(),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Goods receipt created successfully',
            'data' => new GoodsReceiptResource($receipt),
        ], 201);
    }

    /**
     * Show goods receipt details
     */
    public function show($id): JsonResponse
    {
        $receipt = $this->goodsReceiptService->getReceiptDetails($id);

        return response()->json([
            'success' => true,
            'data' => new GoodsReceiptResource($receipt),
        ]);
    }

    /**
     * Save as draft
     */
    public function saveDraft(CreateGoodsReceiptRequest $request): JsonResponse
    {
        $receipt = $this->goodsReceiptService->saveDraft(
            $request->validated(),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Goods receipt saved as draft',
            'data' => new GoodsReceiptResource($receipt),
        ]);
    }

    /**
     * Complete receipt
     */
    public function complete($id): JsonResponse
    {
        $receipt = $this->goodsReceiptService->completeReceipt($id, auth()->user());

        return response()->json([
            'success' => true,
            'message' => 'Goods receipt completed successfully',
            'data' => new GoodsReceiptResource($receipt),
        ]);
    }

    /**
     * Handle variance actions
     */
    public function handleVariance(Request $request, $id): JsonResponse
    {
        $request->validate([
            'variance_id' => 'required|exists:goods_receipt_variances,id',
            'action' => 'required|in:accept_variance,create_compensatory_order,deduct_from_invoice',
            'notes' => 'nullable|string',
            'photo_evidence' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        $receipt = $this->goodsReceiptService->handleVariance(
            $id,
            $request->get('variance_id'),
            $request->get('action'),
            $request->only(['notes', 'photo_evidence']),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Variance handled successfully',
            'data' => new GoodsReceiptResource($receipt),
        ]);
    }
}
