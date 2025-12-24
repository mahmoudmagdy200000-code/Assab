<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class RejectOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|in:insufficient_stock,quality_unavailable,capacity_limitations,pricing_conflict,delivery_schedule_not_feasible,other',
            'explanation' => 'required|string|max:1000',
            'rejection_type' => 'required|in:entire_order,partial_items',
        ];
    }
}

