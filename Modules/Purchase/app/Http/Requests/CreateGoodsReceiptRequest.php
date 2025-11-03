<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'driver_name' => 'required|string|max:255',
            'driver_contact' => 'required|string|max:20',
            'vehicle_number' => 'required|string|max:50',
            'arrival_time' => 'required|date',
            'document_type' => 'required|in:invoice,delivery_note,receipt_without_document',
            'invoice_number' => 'required_if:document_type,invoice|string|max:100',
            'invoice_date' => 'required_if:document_type,invoice|date',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'amount_before_tax' => 'required|numeric|min:0',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'nullable|exists:purchase_order_items,id',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity_ordered' => 'nullable|numeric|min:0',
            'items.*.quantity_received' => 'required|numeric|min:0',
            'items.*.unit' => 'required|in:KG,PK,L',
            'items.*.quality' => 'nullable|in:normal,excellent,poor',
            'items.*.temperature' => 'nullable|numeric',
            'items.*.expiration_date' => 'nullable|date',
            'items.*.photo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'items.*.notes' => 'nullable|string|max:1000',
            'items.*.is_gift' => 'nullable|boolean',
            'items.*.price_per_unit' => 'nullable|numeric|min:0',
            'items.*.reason_for_addition' => 'nullable|string|max:500',
            'items.*.variance_action' => 'nullable|in:accept_variance,create_compensatory_order,deduct_from_invoice',
            'items.*.variance_notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required',
            'items.min' => 'At least one item is required',
            'invoice_number.required_if' => 'Invoice number is required for invoice type',
            'invoice_date.required_if' => 'Invoice date is required for invoice type',
        ];
    }
}
