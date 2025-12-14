<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetDirectSupplierItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // No validation needed - this endpoint doesn't receive any data
        // It returns all items from user's branch with supplier prices
        return [];
    }
}
