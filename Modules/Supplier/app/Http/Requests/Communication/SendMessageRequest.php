<?php

namespace Modules\Supplier\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'required|uuid|exists:branches,id',
            'order_id' => 'nullable|uuid|exists:purchase_orders,id',
            'message' => 'required|string|max:5000',
            'attachments' => 'nullable|array',
        ];
    }
}
