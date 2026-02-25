<?php

namespace Modules\Inventory\Http\Requests\WasteDamage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\CauseOfDamage;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReason;

class StoreWasteDamageReportItemRequest extends FormRequest
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
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'purchase_order_item_id' => ['nullable', 'uuid', 'exists:purchase_order_items,id'],
            'problem_type' => ['required', 'string', Rule::in(array_map(fn ($c) => $c->value, ProblemType::cases()))],
            'cause_of_damage' => [
                'nullable',
                'string',
                Rule::in(array_map(fn ($c) => $c->value, CauseOfDamage::cases())),
                Rule::requiredIf($this->input('problem_type') === ProblemType::DAMAGE->value),
            ],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', Rule::in(WasteDamageReason::values())],
            'justification_text' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'my_quantity_accountable' => ['nullable', 'numeric', 'min:0'],
            'responsible_employees' => [
                Rule::requiredIf(fn () => in_array(
                    $this->input('cause_of_damage'),
                    [CauseOfDamage::ME_AND_OR_OTHER_EMPLOYEES->value, CauseOfDamage::MIXED_FACTORS->value],
                    true
                )),
                'nullable',
                'array',
            ],
            'responsible_employees.*.cashier_id' => ['required', 'uuid', 'exists:cashiers,id'],
            'responsible_employees.*.quantity_accountable' => ['required', 'numeric', 'min:0'],
        ];
    }
}
