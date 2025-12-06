<?php

namespace Modules\PurchaseHistory\Http\Requests\ReceiveGoods;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceDeductionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
