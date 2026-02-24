<?php

namespace Modules\Inventory\Http\Requests\WasteDamage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\CauseOfDamage;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReason;

class StoreWasteDamageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assigned_to_type' => ['required', 'string', Rule::in(['personal', 'staff'])],
            'assigned_to_id' => [
                'required_if:assigned_to_type,staff',
                'nullable',
                'uuid',
                'exists:cashiers,id',
            ],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'items.*.purchase_order_item_id' => ['nullable', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.problem_type' => ['required', 'string', Rule::in(array_map(fn ($c) => $c->value, ProblemType::cases()))],
            'items.*.cause_of_damage' => [
                'nullable',
                'string',
                Rule::in(array_map(fn ($c) => $c->value, CauseOfDamage::cases())),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.reason' => ['required', 'string', Rule::in(WasteDamageReason::values())],
            'items.*.justification_text' => ['nullable', 'string', 'max:2000'],
            'items.*.photo' => ['nullable', 'image', 'max:5120'],
            'items.*.price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.my_quantity_accountable' => ['nullable', 'numeric', 'min:0'],
            'items.*.responsible_employees' => ['nullable', 'array'],
            'items.*.responsible_employees.*.cashier_id' => ['required', 'uuid', 'exists:cashiers,id'],
            'items.*.responsible_employees.*.quantity_accountable' => ['required', 'numeric', 'min:0'],
        ];
    }
}
