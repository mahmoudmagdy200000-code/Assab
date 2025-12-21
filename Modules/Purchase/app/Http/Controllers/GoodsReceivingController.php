<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Http\Requests\CreateInvoiceRequest;
use Modules\Purchase\Http\Requests\ReceiveGoodsRequest;
use Modules\Purchase\Http\Requests\ReceiveWithoutOrderRequest;
use Modules\Purchase\Http\Requests\VarianceActionRequest;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Services\GoodsReceiptService;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\VarianceService;
use Modules\Purchase\Transformers\GoodsReceiptResource;
use Modules\Purchase\Transformers\VarianceResource;

class GoodsReceivingController extends BaseController
{
    public function __construct(
        private readonly GoodsReceiptService $receiptService,
        private readonly PurchaseOrderService $orderService,
        private readonly VarianceService $varianceService
    ) {}

    /**
     * Get in-progress receipts list
     * 
     * @group Goods Receiving
     */
    public function inProgress(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $receipts = $this->receiptService->getInProgressReceipts($branchId, $request->get('per_page', 15));
            
            // Data is already transformed in service, pass paginator directly
            return $this->paginatedResponse(
                $receipts,
                'In-progress receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching in-progress receipts');
        }
    }

    /**
     * Get orders ready for receiving
     * 
     * @group Goods Receiving
     */
    public function ordersForReceiving(Request $request): JsonResponse
    {
        try {
            $filters = [
                'branch_id' => auth()->user()->branch_id,
                'type' => $request->get('type'),
            ];
            
            $orders = $this->orderService->getOrdersForReceiving($filters, $request->get('per_page', 15));
            
            return $this->paginatedResponse(
                $orders,
                'Orders for receiving retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching orders for receiving');
        }
    }

    /**
     * Start receiving an order
     * 
     * @group Goods Receiving
     */
    public function startReceiving(string $orderId): JsonResponse
    {
        try {
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($orderId, $userBranchId);
            
            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }
            
            if (!$order->can_receive) {
                return $this->errorResponse('Order cannot be received in current status', 400);
            }
            
            $receipt = $this->receiptService->startReceiving($order, auth()->id());
            
            return $this->createdResponse(
                new GoodsReceiptResource($receipt),
                'Receiving started successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'starting receiving');
        }
    }

    /**
     * Update delivery details
     * 
     * @group Goods Receiving
     */
    public function updateDeliveryDetails(Request $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $receipt = $this->receiptService->setDeliveryDetails($receipt, $request->all());
            
            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Delivery details updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating delivery details');
        }
    }

    /**
     * Inspect an item
     * 
     * @group Goods Receiving
     */
    public function inspectItem(Request $request, string $receiptId, string $itemId): JsonResponse
    {
        try {
            $item = GoodsReceiptItem::where('goods_receipt_id', $receiptId)
                ->where('id', $itemId)
                ->first();
            
            if (!$item) {
                return $this->notFoundResponse('Item not found');
            }
            
            $item = $this->receiptService->inspectItem($item, $request->all());
            
            return $this->successResponse(
                $item,
                'Item inspected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'inspecting item');
        }
    }

    /**
     * Add unlisted item (gift)
     * 
     * @group Goods Receiving
     */
    public function addUnlistedItem(Request $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $item = $this->receiptService->addUnlistedItem($receipt, $request->all());
            
            return $this->createdResponse(
                $item,
                'Unlisted item added successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'adding unlisted item');
        }
    }

    /**
     * Set document type
     * 
     * @group Goods Receiving
     */
    public function setDocumentType(Request $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $type = DocumentType::from($request->document_type);
            $this->receiptService->setDocumentType($receipt, $type);
            
            return $this->successResponse(
                new GoodsReceiptResource($receipt->fresh()),
                'Document type set successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'setting document type');
        }
    }

    /**
     * Create invoice
     * 
     * @group Goods Receiving
     */
    public function createInvoice(CreateInvoiceRequest $request, string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $invoice = $this->receiptService->createInvoice($receipt, $request->validated());
            
            return $this->createdResponse(
                $invoice,
                'Invoice created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating invoice');
        }
    }

    /**
     * Complete inspection
     * 
     * @group Goods Receiving
     */
    public function completeInspection(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $receipt = $this->receiptService->completeInspection($receipt);
            
            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Inspection completed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'completing inspection');
        }
    }

    /**
     * Save receipt as draft
     * 
     * @group Goods Receiving
     */
    public function saveDraft(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $receipt = $this->receiptService->saveDraft($receipt);
            
            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Receipt saved as draft successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'saving draft');
        }
    }

    /**
     * Get draft receipts
     * 
     * @group Goods Receiving
     */
    public function drafts(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $receipts = $this->receiptService->getDraftReceipts($branchId, $request->get('per_page', 15));
            
            return $this->paginatedResponse(
                GoodsReceiptResource::collection($receipts),
                'Draft receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching draft receipts');
        }
    }

    /**
     * Delete draft
     * 
     * @group Goods Receiving
     */
    public function deleteDraft(string $id): JsonResponse
    {
        try {
            $receipt = GoodsReceipt::find($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            $success = $this->receiptService->deleteDraft($receipt);
            
            if (!$success) {
                return $this->errorResponse('Cannot delete non-draft receipt', 400);
            }
            
            return $this->deletedResponse('Draft deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting draft');
        }
    }

    /**
     * Get missing goods receipts (with variances)
     * 
     * @group Goods Receiving
     */
    public function missingGoods(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $receipts = $this->receiptService->getMissingGoodsReceipts($branchId, $request->get('per_page', 15));
            
            return $this->paginatedResponse(
                GoodsReceiptResource::collection($receipts),
                'Missing goods receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching missing goods receipts');
        }
    }

    /**
     * Get completed receipts
     * 
     * @group Goods Receiving
     */
    public function completed(Request $request): JsonResponse
    {
        try {
            $branchId = auth()->user()->branch_id;
            $receipts = $this->receiptService->getCompletedReceipts($branchId, $request->get('per_page', 15));
            
            return $this->paginatedResponse(
                GoodsReceiptResource::collection($receipts),
                'Completed receipts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching completed receipts');
        }
    }

    /**
     * Handle variance action
     * 
     * @group Goods Receiving
     */
    public function handleVariance(VarianceActionRequest $request, string $varianceId): JsonResponse
    {
        try {
            $variance = $this->varianceService->getVarianceDetails($varianceId);
            
            if (!$variance) {
                return $this->notFoundResponse('Variance not found');
            }
            
            $action = $request->action;
            
            match($action) {
                'accept' => $this->varianceService->acceptVariance($variance),
                'compensatory_order' => $this->varianceService->createCompensatoryOrder($variance, $request->validated()),
                'deduct_from_invoice' => $this->varianceService->deductFromInvoice(
                    $variance,
                    $request->amount,
                    $request->reason,
                    $request->notes
                ),
            };
            
            return $this->successResponse(
                new VarianceResource($variance->fresh()),
                'Variance action processed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'handling variance');
        }
    }

    /**
     * Receive goods without prior order
     * 
     * @group Goods Receiving
     */
    public function receiveWithoutOrder(ReceiveWithoutOrderRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['branch_id'] = auth()->user()->branch_id;
            $data['received_by'] = auth()->id();
            
            $receipt = $this->receiptService->receiveWithoutOrder($data);
            
            return $this->createdResponse(
                new GoodsReceiptResource($receipt),
                'Goods received successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'receiving goods without order');
        }
    }

    /**
     * Get receipt details
     * 
     * @group Goods Receiving
     */
    public function show(string $id): JsonResponse
    {
        try {
            $receipt = $this->receiptService->getReceiptDetails($id);
            
            if (!$receipt) {
                return $this->notFoundResponse('Receipt not found');
            }
            
            return $this->successResponse(
                new GoodsReceiptResource($receipt),
                'Receipt details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching receipt details');
        }
    }
}

