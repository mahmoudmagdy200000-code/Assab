<?php

namespace Modules\Supplier\Http\Requests\QualityAssurance;

use Illuminate\Foundation\Http\FormRequest;

class LogIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'order_id' => 'nullable|uuid|exists:purchase_orders,id',
            'product_id' => 'nullable|uuid|exists:supplier_products,id',
            'incident_type' => 'required|string',
            'severity' => 'required|string|in:low,medium,high,critical',
            'resolution_steps' => 'nullable|array',
            'file_path' => 'nullable|string',
            'file_name' => 'nullable|string',
        ];
    }
}

