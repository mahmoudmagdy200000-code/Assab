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
            'message' => 'nullable|string|max:1000',
            'quality_documents' => 'nullable|array',
            'quality_documents.*.type' => 'required|string',
            'quality_documents.*.title' => 'required|string',
            'quality_documents.*.file_path' => 'required|string',
            'quality_documents.*.file_name' => 'required|string',
        ];
    }
}

