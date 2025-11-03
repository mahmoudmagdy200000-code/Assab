<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_type' => 'required|in:direct_supplier,purchasing_officer,internal_transfer,multiple_sources',
            'supplier_id' => 'required_if:order_type,direct_supplier|exists:suppliers,id',
            'purchasing_officer_id' => 'required_if:order_type,purchasing_officer|exists:users,id',
            'transfer_from_branch_id' => 'required_if:order_type,internal_transfer|exists:branches,id',
            'priority' => 'nullable|in:high,normal,low',
            'delivery_date' => 'nullable|date',
            'latest_delivery_date' => 'nullable|date|after_or_equal:delivery_date',
            'special_instructions' => 'nullable|string|max:2000',
            'message' => 'nullable|string|max:1000',
            'notification_methods' => 'nullable|array',
            'notification_methods.*' => 'in:email,whatsapp,app,sms',
            'status' => 'nullable|in:draft,pending',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|in:KG,PK,L',
            'items.*.quality' => 'nullable|in:economy,standard,premium',
            'items.*.rate' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'order_type.required' => 'Order type is required',
            'items.required' => 'At least one item is required',
            'items.min' => 'At least one item is required',
            'supplier_id.required_if' => 'Supplier is required for direct supplier orders',
            'purchasing_officer_id.required_if' => 'Purchasing officer is required',
            'transfer_from_branch_id.required_if' => 'Transfer from branch is required',
        ];
    }
}
