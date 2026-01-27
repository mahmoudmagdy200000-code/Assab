<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartReceivingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Note: items.*.item_id and variance.items.*.item_id must be the order line id
     * (order details response "id"), NOT the catalog item_id.
     */
    public function rules(): array
    {
        $orderId = $this->route('orderId');
        $orderLineExists = Rule::exists('purchase_order_items', 'id')
            ->where('purchase_order_id', $orderId);

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', $orderLineExists],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['required', 'string', 'in:excellent,normal,poor'],
            'items.*.temperature' => ['nullable', 'numeric'],
            'items.*.expiration_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],

            // Document type (optional)
            'document_type' => ['nullable', 'string', 'in:invoice,delivery_note,receipt_without_document'],

            // Invoice data (required if document_type is invoice)
            'invoice_data' => ['required_if:document_type,invoice', 'array'],
            'invoice_data.invoice_number' => ['required_with:invoice_data', 'string', 'max:100'],
            'invoice_data.invoice_date' => ['required_with:invoice_data', 'date'],
            'invoice_data.due_date' => ['nullable', 'date', 'after_or_equal:invoice_data.invoice_date'],
            'invoice_data.payment_terms' => ['nullable', 'string', 'max:100'],
            'invoice_data.photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'invoice_data.notes' => ['nullable', 'string', 'max:1000'],

            // Delivery note data (optional if document_type is delivery_note)
            'delivery_note_data' => ['nullable', 'array'],
            'delivery_note_data.file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // Variances (optional object with single action for all items with variance)
            'variance' => ['nullable', 'array'],
            'variance.action' => ['required_with:variance', 'string', 'in:accept,compensatory_order,deduct_from_invoice'],
            'variance.note' => ['nullable', 'string', 'max:1000'],
            'variance.photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],

            // Items for compensatory order (required if action is compensatory_order)
            'variance.items' => ['required_if:variance.action,compensatory_order', 'array', 'min:1'],
            'variance.items.*.item_id' => ['required', 'uuid', $orderLineExists],

            // Deduct from invoice data (optional)
            'variance.deduct_data' => ['nullable', 'array'],
            'variance.deduct_data.amount' => ['nullable', 'numeric', 'min:0.01'],
            'variance.deduct_data.reason' => ['nullable', 'string', 'in:short_quantity,damaged_quality'],
            'variance.deduct_data.notes' => ['nullable', 'string', 'max:1000'],

            // Unlisted items (gifts from supplier)
            'unlisted_items' => ['nullable', 'array'],
            'unlisted_items.*.item_id' => ['nullable', 'uuid', 'exists:items,id'],
            'unlisted_items.*.item_name' => ['required_with:unlisted_items', 'string', 'max:255'],
            'unlisted_items.*.unit' => ['required_with:unlisted_items', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'unlisted_items.*.quantity' => ['required_with:unlisted_items', 'numeric', 'min:0.001'],
            'unlisted_items.*.quality' => ['required_with:unlisted_items', 'string', 'in:excellent,normal,poor'],
            'unlisted_items.*.price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'unlisted_items.*.temperature' => ['nullable', 'numeric'],
            'unlisted_items.*.expiration_date' => ['nullable', 'date', 'after_or_equal:today'],
            'unlisted_items.*.photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'unlisted_items.*.reason' => ['nullable', 'string', 'max:500'],
            'unlisted_items.*.supplier_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Items array is required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required.',
            'items.*.item_id.required' => 'Item ID is required for each item.',
            'items.*.item_id.uuid' => 'Item ID must be a valid UUID.',
            'items.*.item_id.exists' => 'The selected item does not exist in the order. Use the order line "id" from order details, not "item_id".',
            'items.*.quantity_received.required' => 'Quantity received is required for each item.',
            'items.*.quantity_received.numeric' => 'Quantity received must be a number.',
            'items.*.quantity_received.min' => 'Quantity received must be at least 0.',
            'items.*.quality.required' => 'Quality is required for each item.',
            'items.*.quality.in' => 'Quality must be excellent, normal, or poor.',
            'items.*.temperature.numeric' => 'Temperature must be a number.',
            'items.*.expiration_date.date' => 'Expiration date must be a valid date.',
            'items.*.expiration_date.after_or_equal' => 'Expiration date must be today or later.',
            'items.*.photo.image' => 'Photo must be an image file.',
            'items.*.photo.mimes' => 'Photo must be a jpg, jpeg, or png file.',
            'items.*.photo.max' => 'Photo must not exceed 5MB.',
            'items.*.notes.max' => 'Notes must not exceed 1000 characters.',

            // Document type
            'document_type.in' => 'Document type must be invoice, delivery_note, or receipt_without_document.',

            // Invoice data
            'invoice_data.required_if' => 'Invoice data is required when document type is invoice.',
            'invoice_data.invoice_number.required_with' => 'Invoice number is required.',
            'invoice_data.invoice_date.required_with' => 'Invoice date is required.',
            'invoice_data.due_date.after_or_equal' => 'Due date must be on or after invoice date.',
            'invoice_data.photo.image' => 'Invoice photo must be an image file.',
            'invoice_data.photo.mimes' => 'Invoice photo must be a jpg, jpeg, or png file.',
            'invoice_data.photo.max' => 'Invoice photo must not exceed 10MB.',

            // Delivery note
            'delivery_note_data.file.mimes' => 'Delivery note file must be a PDF or image.',
            'delivery_note_data.file.max' => 'Delivery note file must not exceed 10MB.',

            // Variance
            'variance.action.required_with' => 'Action is required for variance.',
            'variance.action.in' => 'Action must be accept, compensatory_order, or deduct_from_invoice.',
            'variance.photo.image' => 'Variance photo must be an image file.',
            'variance.photo.mimes' => 'Variance photo must be a jpg, jpeg, or png file.',
            'variance.photo.max' => 'Variance photo must not exceed 5MB.',

            // Compensatory order items
            'variance.items.required_if' => 'Items list is required when action is compensatory_order.',
            'variance.items.min' => 'At least one item is required for compensatory orders.',
            'variance.items.*.item_id.required' => 'Item ID is required for each item in compensatory order.',
            'variance.items.*.item_id.uuid' => 'Item ID must be a valid UUID.',
            'variance.items.*.item_id.exists' => 'The selected item does not exist in the order. Use the order line "id" from order details, not "item_id".',

            // Deduct from invoice
            'variance.deduct_data.amount.numeric' => 'Deduction amount must be a number.',
            'variance.deduct_data.amount.min' => 'Deduction amount must be at least 0.01.',
            'variance.deduct_data.reason.in' => 'Reason must be short_quantity or damaged_quality.',

            // Unlisted items
            'unlisted_items.*.item_id.exists' => 'The selected item does not exist.',
            'unlisted_items.*.item_name.required_with' => 'Item name is required for unlisted items.',
            'unlisted_items.*.unit.required_with' => 'Unit of measurement is required for unlisted items.',
            'unlisted_items.*.unit.in' => 'Unit must be kg, pk, unit, box, liter, or piece.',
            'unlisted_items.*.quantity.required_with' => 'Quantity is required for unlisted items.',
            'unlisted_items.*.quantity.min' => 'Quantity must be at least 0.001.',
            'unlisted_items.*.quality.required_with' => 'Quality is required for unlisted items.',
            'unlisted_items.*.quality.in' => 'Quality must be excellent, normal, or poor.',
            'unlisted_items.*.price_per_unit.numeric' => 'Price per unit must be a number.',
            'unlisted_items.*.temperature.numeric' => 'Temperature must be a number.',
            'unlisted_items.*.expiration_date.date' => 'Expiration date must be a valid date.',
            'unlisted_items.*.expiration_date.after_or_equal' => 'Expiration date must be today or later.',
            'unlisted_items.*.photo.image' => 'Photo must be an image file.',
            'unlisted_items.*.photo.mimes' => 'Photo must be a jpg, jpeg, or png file.',
            'unlisted_items.*.photo.max' => 'Photo must not exceed 5MB.',
        ];
    }
}
