<?php

namespace Modules\BranchManagers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Body for POST /branch-manager/price-comparisons/{id}/orders.
 *
 * Every field is optional: with an empty body the recommended source from the
 * saved comparison is used. A client may override the source, quantity, or
 * notification channels. camelCase keys from the client are accepted and
 * normalised to the snake_case the order service expects.
 */
class CreateOrderFromComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof BranchManager && $user->branch_id !== null;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['nullable', 'string', 'in:direct_supplier,via_purchasing_officer,internal_transfer'],
            'source_id' => ['nullable', 'string', 'uuid'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'notification_channels' => ['nullable', 'array', 'min:1'],
            'notification_channels.*' => ['string', 'max:50'],
            'message' => ['nullable', 'string', 'max:1000'],
            'is_draft' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_type.in' => 'source_type must be one of: direct_supplier, via_purchasing_officer, internal_transfer.',
            'source_id.uuid' => 'source_id must be a valid UUID.',
            'quantity.min' => 'quantity must be greater than 0.',
        ];
    }

    /**
     * Accept camelCase keys from the client and map them to the snake_case
     * keys used by the validation rules and the order service.
     */
    protected function prepareForValidation(): void
    {
        $mapped = [];

        foreach ([
            'source_type' => 'sourceType',
            'source_id' => 'sourceId',
            'notification_channels' => 'notificationChannels',
            'is_draft' => 'isDraft',
        ] as $snake => $camel) {
            $value = $this->input($snake, $this->input($camel));
            if ($value !== null) {
                $mapped[$snake] = $value;
            }
        }

        if (! empty($mapped)) {
            $this->merge($mapped);
        }
    }

    /**
     * Validated override set passed to the price-comparison service.
     */
    public function overrides(): array
    {
        return $this->validated();
    }
}
