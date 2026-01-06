<?php

namespace Modules\Supplier\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

class CompleteDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_photos' => 'required|array|min:1',
            'delivery_photos.*' => 'required|file|image|mimes:jpeg,jpg,png|max:5120',
        ];
    }
}
