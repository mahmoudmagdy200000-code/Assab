<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCompensatoryOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
            'source' => ['nullable', 'string', 'max:100'],
            'deadline' => ['required', 'date', 'after:today'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'deadline.required' => 'Delivery urgency deadline is required.',
            'deadline.after' => 'Deadline must be in the future.',
            'photos.*.image' => 'Photo evidence must be an image file.',
            'photos.*.max' => 'Each photo must not exceed 5MB.',
        ];
    }
}
