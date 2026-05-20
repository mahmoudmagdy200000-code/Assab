<?php

namespace Modules\Supplier\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'progress_update' => 'nullable|string|max:1000',
            'estimated_completion_time' => 'nullable|date|after:now',
        ];
    }
}
