<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\PurchaseOrderItem;

class CreateInventorySessionRequest extends FormRequest
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
     * item_id is accepted as either: purchase_order_items.id (from getClosedOrderItems) or items.id (from getBranchItems).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assigned_to_type' => ['required', 'string', 'in:personal,staff'],
            'assigned_to_id' => ['required_if:assigned_to_type,staff', 'uuid', 'exists:cashiers,id'],
            'inventory_date' => ['required_if:assigned_to_type,personal', 'nullable', 'date'],
            'start_time' => ['required_if:assigned_to_type,personal', 'nullable', 'date_format:Y-m-d H:i:s'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => [
                'required_with:items',
                'uuid',
                function (string $_attribute, mixed $value, \Closure $fail): void {
                    $manager = auth()->user();
                    if (! $manager || ! $manager->branch_id) {
                        $fail(__('Branch is required.'));

                        return;
                    }
                    $branchId = $manager->branch_id;

                    $existsAsPurchaseOrderItem = PurchaseOrderItem::where('id', $value)
                        ->whereHas('purchaseOrder', function ($q) use ($branchId): void {
                            $q->where('branch_id', $branchId)->where('status', OrderStatus::CLOSED);
                        })
                        ->exists();

                    if ($existsAsPurchaseOrderItem) {
                        return;
                    }

                    $existsAsBranchItem = BranchItem::where('branch_id', $branchId)
                        ->where('item_id', $value)
                        ->exists();

                    if ($existsAsBranchItem) {
                        return;
                    }

                    $fail(__('One or more selected items do not exist.'));
                },
            ],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to_type.required' => 'The assignment type is required.',
            'assigned_to_type.in' => 'The assignment type must be either personal or staff.',
            'assigned_to_id.required_if' => 'Employee ID is required when assignment type is staff.',
            'assigned_to_id.exists' => 'The selected employee does not exist.',
            'inventory_date.required_if' => 'The inventory date is required when assignment type is personal.',
            'inventory_date.date' => 'The inventory date must be a valid date.',
            'start_time.required_if' => 'The start time is required when assignment type is personal.',
            'start_time.date_format' => 'The start time must be in the format Y-m-d H:i:s.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
            'items.array' => 'Items must be an array.',
            'items.*.item_id.required_with' => 'Item ID is required for each item.',
            'items.*.item_id.exists' => 'One or more selected items do not exist.',
            'items.*.quantity.required_with' => 'Quantity is required for each item.',
            'items.*.quantity.numeric' => 'Quantity must be a number.',
            'items.*.quantity.min' => 'Quantity must be at least 0.',
            'items.*.notes.max' => 'Item notes may not be greater than 1000 characters.',
        ];
    }
}
