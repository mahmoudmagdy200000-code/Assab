<?php

namespace Modules\Supplier\Http\Requests\DeliveryProof;

use Illuminate\Foundation\Http\FormRequest;

class CreateDeliveryProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_name' => 'required|string|max:255',
            'recipient_signature' => 'required|file|image|mimes:jpeg,jpg,png|max:5120',
            'delivery_photos' => 'required|array|min:1',
            'delivery_photos.*' => 'required|file|image|mimes:jpeg,jpg,png|max:5120',
            'condition_confirmation' => 'required|string|max:2000',
        ];
    }
}

