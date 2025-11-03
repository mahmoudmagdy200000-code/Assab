<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Purchase\Http\Requests\CreatePurchaseReturnRequest;
use Modules\Purchase\Transformers\PurchaseReturnResource;
use Modules\Purchase\Services\PurchaseReturnService;
use Illuminate\Http\Request;


class PurchaseReturnController extends Controller
{
    public function __construct(
        private PurchaseReturnService $purchaseReturnService
    ) {}

    /**
     * Get returns list
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->get('status'), // in_progress, draft, completed
            'branch_id' => auth()->user()->branch_id,
        ];

        $returns = $this->purchaseReturnService->getReturnsList($filters);

        return response()->json([
            'success' => true,
            'data' => PurchaseReturnResource::collection($returns),
        ]);
    }

    /**
     * Create return request
     */
    public function store(CreatePurchaseReturnRequest $request): JsonResponse
    {
        $return = $this->purchaseReturnService->createReturn(
            $request->validated(),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Return request created successfully',
            'data' => new PurchaseReturnResource($return),
        ], 201);
    }

    /**
     * Show return details
     */
    public function show($id): JsonResponse
    {
        $return = $this->purchaseReturnService->getReturnDetails($id);

        return response()->json([
            'success' => true,
            'data' => new PurchaseReturnResource($return),
        ]);
    }

    /**
     * Accept rejection
     */
    public function acceptRejection($id): JsonResponse
    {
        $return = $this->purchaseReturnService->acceptRejection($id, auth()->user());

        return response()->json([
            'success' => true,
            'message' => 'Rejection accepted successfully',
            'data' => new PurchaseReturnResource($return),
        ]);
    }

    /**
     * Escalate rejection
     */
    public function escalateRejection(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $return = $this->purchaseReturnService->escalateRejection(
            $id,
            $request->get('reason'),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Return escalated to brand owner successfully',
            'data' => new PurchaseReturnResource($return),
        ]);
    }

    /**
     * Get return timeline
     */
    public function timeline($id): JsonResponse
    {
        $timeline = $this->purchaseReturnService->getReturnTimeline($id);

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }
}
