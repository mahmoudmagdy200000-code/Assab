<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'goods_receipt_id' => 'nullable|exists:goods_receipts,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'return_date' => 'nullable|date',
            'required_action' => 'required|in:replacement,cash_refund,credit_future',
            'status' => 'nullable|in:draft,pending',
            'additional_notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.return_quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|in:KG,PK,L',
            'items.*.quality_reason' => 'required|in:excellent,normal,poor',
            'items.*.return_amount' => 'required|numeric|min:0',
            'items.*.uploaded_files' => 'nullable|array',
            'items.*.uploaded_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
            'items.*.notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required for return',
            'items.min' => 'At least one item is required for return',
            'required_action.required' => 'Required action must be specified',
        ];
    }
}
