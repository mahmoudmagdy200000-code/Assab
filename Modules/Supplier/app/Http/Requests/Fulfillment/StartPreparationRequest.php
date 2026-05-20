<?php

namespace Modules\Supplier\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

class StartPreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|uuid|exists:purchase_orders,id',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|uuid|exists:purchase_order_items,id',
            'items.*.file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ];
    }
}
