<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMultipleOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Branches array (for internal transfers)
            'branches' => ['nullable', 'array'],
            'branches.*.branch_id' => ['required_with:branches', 'uuid', 'exists:branches,id'],
            'branches.*.priority' => ['nullable', 'string', 'in:high,normal'],
            'branches.*.items' => ['required_with:branches.*.branch_id', 'array', 'min:1'],
            'branches.*.items.*.item_id' => ['required', 'uuid', 'exists:branch_item,id'],
            'branches.*.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'branches.*.items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'branches.*.items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'branches.*.items.*.available_in_source' => ['nullable', 'numeric', 'min:0'],
            'branches.*.items.*.expiry_date' => ['nullable', 'date'],
            'branches.*.items.*.cooling_status' => ['nullable', 'boolean'],

            // Direct Supplier Orders array
            'direct_supplier' => ['nullable', 'array'],
            'direct_supplier.*.supplier_id' => ['required_with:direct_supplier', 'uuid', 'exists:purchase_suppliers,id'],
            'direct_supplier.*.quality_level' => ['required_with:direct_supplier', 'string', 'in:economy,standard,premium'],
            'direct_supplier.*.notification_channels' => ['required_with:direct_supplier', 'array', 'min:1'],
            'direct_supplier.*.notification_channels.*' => ['string', 'in:email,whatsapp,app,sms'],
            'direct_supplier.*.message' => ['nullable', 'string', 'max:1000'],
            'direct_supplier.*.items' => ['required_with:direct_supplier.*.supplier_id', 'array', 'min:1'],
            'direct_supplier.*.items.*.item_id' => ['required', 'uuid', 'exists:branch_item,id'],
            'direct_supplier.*.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'direct_supplier.*.items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'direct_supplier.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'direct_supplier.*.items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],

            // Purchasing Officer Orders array
            'purchase_officer' => ['nullable', 'array'],
            'purchase_officer.*.quality_level' => ['required_with:purchase_officer', 'string', 'in:economy,standard,premium'],
            'purchase_officer.*.processing_time' => ['required_with:purchase_officer', 'string', 'in:standard,urgent'],
            'purchase_officer.*.preferred_delivery_date' => ['required_with:purchase_officer', 'date', 'after:today'],
            'purchase_officer.*.latest_delivery_date' => ['required_with:purchase_officer', 'date', 'after_or_equal:purchase_officer.*.preferred_delivery_date'],
            'purchase_officer.*.special_instructions' => ['nullable', 'string', 'max:2000'],
            'purchase_officer.*.message' => ['nullable', 'string', 'max:1000'],
            'purchase_officer.*.items' => ['required_with:purchase_officer.*.quality_level', 'array', 'min:1'],
            'purchase_officer.*.items.*.item_id' => ['required', 'uuid', 'exists:branch_item,id'],
            'purchase_officer.*.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'purchase_officer.*.items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'purchase_officer.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'purchase_officer.*.items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
        ];
    }

    public function messages(): array
    {
        return [
            // Branches messages
            'branches.*.branch_id.required_with' => 'Branch ID is required for each branch entry.',
            'branches.*.branch_id.exists' => 'One or more selected branches do not exist.',
            'branches.*.items.required_with' => 'At least one item is required for each branch.',
            'branches.*.items.*.item_id.required' => 'Item ID is required for each item.',
            'branches.*.items.*.item_id.exists' => 'One or more selected items do not exist.',
            'branches.*.items.*.quantity.required' => 'Quantity is required for each item.',
            'branches.*.items.*.quantity.min' => 'Quantity must be greater than 0.',

            // Direct Supplier messages
            'direct_supplier.*.supplier_id.required_with' => 'Supplier ID is required for each direct supplier order.',
            'direct_supplier.*.supplier_id.exists' => 'One or more selected suppliers do not exist.',
            'direct_supplier.*.quality_level.required_with' => 'Quality level is required for each direct supplier order.',
            'direct_supplier.*.notification_channels.required_with' => 'At least one notification method is required for each direct supplier order.',
            'direct_supplier.*.items.required_with' => 'At least one item is required for each direct supplier order.',
            'direct_supplier.*.items.*.item_id.required' => 'Item ID is required for each item.',
            'direct_supplier.*.items.*.item_id.exists' => 'One or more selected items do not exist.',
            'direct_supplier.*.items.*.quantity.required' => 'Quantity is required for each item.',
            'direct_supplier.*.items.*.quantity.min' => 'Quantity must be greater than 0.',
            'direct_supplier.*.items.*.unit_price.required' => 'Unit price is required for each item.',
            'direct_supplier.*.items.*.unit_price.min' => 'Unit price must be greater than or equal to 0.',

            // Purchasing Officer messages
            'purchase_officer.*.quality_level.required_with' => 'Quality level is required for each purchasing officer order.',
            'purchase_officer.*.processing_time.required_with' => 'Processing time is required for each purchasing officer order.',
            'purchase_officer.*.preferred_delivery_date.required_with' => 'Preferred delivery date is required for each purchasing officer order.',
            'purchase_officer.*.preferred_delivery_date.after' => 'Preferred delivery date must be in the future.',
            'purchase_officer.*.latest_delivery_date.required_with' => 'Latest delivery date is required for each purchasing officer order.',
            'purchase_officer.*.latest_delivery_date.after_or_equal' => 'Latest delivery date must be on or after the preferred date.',
            'purchase_officer.*.items.required_with' => 'At least one item is required for each purchasing officer order.',
            'purchase_officer.*.items.*.item_id.required' => 'Item ID is required for each item.',
            'purchase_officer.*.items.*.item_id.exists' => 'One or more selected items do not exist.',
            'purchase_officer.*.items.*.quantity.required' => 'Quantity is required for each item.',
            'purchase_officer.*.items.*.quantity.min' => 'Quantity must be greater than 0.',
            'purchase_officer.*.items.*.unit_price.required' => 'Unit price is required for each item.',
            'purchase_officer.*.items.*.unit_price.min' => 'Unit price must be greater than or equal to 0.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->all();

            // At least one order type must be provided
            $hasBranches = !empty($data['branches']) && is_array($data['branches']) && count($data['branches']) > 0;
            $hasDirectSupplier = !empty($data['direct_supplier']) && is_array($data['direct_supplier']) && count($data['direct_supplier']) > 0;
            $hasPurchaseOfficer = !empty($data['purchase_officer']) && is_array($data['purchase_officer']) && count($data['purchase_officer']) > 0;

            if (!$hasBranches && !$hasDirectSupplier && !$hasPurchaseOfficer) {
                $validator->errors()->add(
                    'orders',
                    'At least one order type must be provided (branches, direct_supplier, or purchase_officer).'
                );
            }
        });
    }
}
