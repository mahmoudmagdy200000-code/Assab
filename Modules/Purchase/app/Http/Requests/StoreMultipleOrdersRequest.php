<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'branches.*.priority' => ['nullable', 'string'], // Accept any string value
            'branches.*.justification' => ['nullable', 'string', 'max:1000'],
            'branches.*.items' => ['required_with:branches.*.branch_id', 'array', 'min:1'],
            'branches.*.items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'branches.*.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            // Optional fields for branches items (not in the required format but allowed)
            'branches.*.items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'branches.*.items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'branches.*.items.*.available_in_source' => ['nullable', 'numeric', 'min:0'],
            'branches.*.items.*.expiry_date' => ['nullable', 'date'],
            'branches.*.items.*.cooling_status' => ['nullable', 'boolean'],

            // Direct Supplier Orders array
            'direct_supplier' => ['nullable', 'array'],
            'direct_supplier.*.supplier_id' => ['required_with:direct_supplier', 'uuid', 'exists:suppliers,id'],
            'direct_supplier.*.quality_level' => ['nullable', 'string', 'in:economy,standard,premium'],
            'direct_supplier.*.notification_channels' => ['required_with:direct_supplier', 'array', 'min:1'],
            'direct_supplier.*.notification_channels.*' => ['string'], // Accept any string value
            'direct_supplier.*.message' => ['nullable', 'string', 'max:1000'],
            'direct_supplier.*.items' => ['required_with:direct_supplier.*.supplier_id', 'array', 'min:1'],
            'direct_supplier.*.items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'direct_supplier.*.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'direct_supplier.*.items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            // Optional fields for direct_supplier items (not in the required format but allowed)
            'direct_supplier.*.items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'direct_supplier.*.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],

            // Purchasing Officer Orders array
            'purchase_officer' => ['nullable', 'array'],
            'purchase_officer.*.items' => ['required_with:purchase_officer', 'array', 'min:1'],
            'purchase_officer.*.items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'purchase_officer.*.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'purchase_officer.*.items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'purchase_officer.*.items.*.preferred_delivery_date' => ['nullable', 'date'],
            'purchase_officer.*.items.*.latest_delivery_date' => ['nullable', 'date'],
            'purchase_officer.*.items.*.special_instructions' => ['nullable', 'string', 'max:2000'],
            // Optional fields for purchase_officer items (not in the required format but allowed)
            'purchase_officer.*.items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'purchase_officer.*.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            // Optional fields at purchase_officer level (not in the required format but allowed for backward compatibility)
            'purchase_officer.*.quality_level' => ['nullable', 'string', 'in:economy,standard,premium'],
            'purchase_officer.*.processing_time' => ['nullable', 'string', 'in:standard,urgent'],
            'purchase_officer.*.preferred_delivery_date' => ['nullable', 'date'],
            'purchase_officer.*.latest_delivery_date' => ['nullable', 'date'],
            'purchase_officer.*.special_instructions' => ['nullable', 'string', 'max:2000'],
            'purchase_officer.*.message' => ['nullable', 'string', 'max:1000'],

            // Draft flag
            'is_draft' => ['sometimes', 'boolean'],
            // Emergency flag: when true, order status is set to emergency
            'is_emergency' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Backward-compatibility alias used by some clients.
        if (! $this->has('purchase_officer') && $this->has('purchasing_officer')) {
            $this->merge([
                'purchase_officer' => $this->input('purchasing_officer'),
            ]);
        }
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
            'branches.*.items.*.quality.in' => 'Quality must be one of: economy, standard, or premium.',

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
            'direct_supplier.*.items.*.quality.in' => 'Quality must be one of: economy, standard, or premium.',
            'direct_supplier.*.quality_level.in' => 'Quality level must be one of: economy, standard, or premium.',

            // Purchasing Officer messages
            'purchase_officer.*.items.required_with' => 'At least one item is required for each purchasing officer order.',
            'purchase_officer.*.items.*.item_id.required' => 'Item ID is required for each item.',
            'purchase_officer.*.items.*.item_id.exists' => 'One or more selected items do not exist.',
            'purchase_officer.*.items.*.quantity.required' => 'Quantity is required for each item.',
            'purchase_officer.*.items.*.quantity.min' => 'Quantity must be greater than 0.',
            'purchase_officer.*.items.*.quality.in' => 'Quality must be one of: economy, standard, or premium.',
            'purchase_officer.*.quality_level.in' => 'Quality level must be one of: economy, standard, or premium.',
            'purchase_officer.*.items.*.preferred_delivery_date.date' => 'Preferred delivery date must be a valid date.',
            'purchase_officer.*.items.*.latest_delivery_date.date' => 'Latest delivery date must be a valid date.',
            'purchase_officer.*.items.*.special_instructions.max' => 'Special instructions must not exceed 2000 characters.',
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
            $hasBranches = ! empty($data['branches']) && is_array($data['branches']) && count($data['branches']) > 0;
            $hasDirectSupplier = ! empty($data['direct_supplier']) && is_array($data['direct_supplier']) && count($data['direct_supplier']) > 0;
            $hasPurchaseOfficer = ! empty($data['purchase_officer']) && is_array($data['purchase_officer']) && count($data['purchase_officer']) > 0;

            if (! $hasBranches && ! $hasDirectSupplier && ! $hasPurchaseOfficer) {
                $validator->errors()->add(
                    'orders',
                    'At least one order type must be provided (branches, direct_supplier, or purchase_officer).'
                );
            }
        });
    }
}
