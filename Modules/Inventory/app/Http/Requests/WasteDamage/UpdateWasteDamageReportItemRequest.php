<?php

namespace Modules\Inventory\Http\Requests\WasteDamage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\CauseOfDamage;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReason;

class UpdateWasteDamageReportItemRequest extends FormRequest
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
            'problem_type' => ['sometimes', 'string', Rule::in(array_map(fn ($c) => $c->value, ProblemType::cases()))],
            'cause_of_damage' => [
                'nullable',
                'string',
                Rule::in(array_map(fn ($c) => $c->value, CauseOfDamage::cases())),
            ],
            'quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'reason' => ['sometimes', 'string', Rule::in(WasteDamageReason::values())],
            'justification_text' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'my_quantity_accountable' => ['nullable', 'numeric', 'min:0'],
            'responsible_employees' => ['nullable', 'array'],
            'responsible_employees.*.cashier_id' => ['required', 'uuid', 'exists:cashiers,id'],
            'responsible_employees.*.quantity_accountable' => ['required', 'numeric', 'min:0'],
        ];
    }
}
