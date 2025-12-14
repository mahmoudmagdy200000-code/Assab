<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetPurchasingOfficerItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No validation needed - this endpoint doesn't receive any data
            // It returns items from purchasing officer's list
        ];
    }
}
