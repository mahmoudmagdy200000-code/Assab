<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\OwnerPaymentFormService;

class OwnerPaymentFormController extends BaseController
{
    public function __construct(
        private OwnerPaymentFormService $service
    ) {}

    /**
     * POST /api/brand-owner/payment-form (multipart/form-data)
     */
    public function store(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (! ($actor instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can submit the owner payment form', 403);
        }

        $method = strtolower((string) $request->input('preferredReceiptMethod'));

        $validator = Validator::make($request->all(), [
            'recipientEmployeeId' => 'required|string|exists:branch_managers,id',
            'amount' => 'required|numeric|min:0|max:1000000',
            'preferredReceiptMethod' => 'required|in:cash_handover,bank_transfer,Cash Handover,Bank Transfer',
            'handoverDate' => 'nullable|date|required_if:preferredReceiptMethod,cash_handover|required_if:preferredReceiptMethod,Cash Handover',
            'transferDate' => 'nullable|date|required_if:preferredReceiptMethod,bank_transfer|required_if:preferredReceiptMethod,Bank Transfer',
            'purpose' => 'nullable|string|max:500',
            'note' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $normalized = match ($method) {
                'cash_handover', 'cash handover' => 'Cash Handover',
                'bank_transfer', 'bank transfer' => 'Bank Transfer',
                default => null,
            };

            $custodyRequest = $this->service->submit($actor, [
                'recipient_employee_id' => $request->input('recipientEmployeeId'),
                'amount' => $request->input('amount'),
                'preferred_receipt_method' => $normalized,
                'handover_date' => $request->input('handoverDate'),
                'transfer_date' => $request->input('transferDate'),
                'purpose' => $request->input('purpose'),
                'note' => $request->input('note'),
                'attachments' => $request->file('attachments', []),
            ]);

            return $this->createdResponse([
                'requestId' => $custodyRequest->id,
                'status' => strtolower((string) $custodyRequest->status),
                'submittedAt' => $custodyRequest->created_at?->toIso8601String(),
            ], 'Owner payment form submitted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
