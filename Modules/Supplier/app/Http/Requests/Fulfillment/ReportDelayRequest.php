<?php

namespace Modules\Supplier\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

class ReportDelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'message' => 'required|string|max:1000',
            'new_expected_delivery_date_type' => 'required|string|in:today,custom',
            'photo' => 'nullable|file|image|mimes:jpeg,jpg,png|max:5120',
        ];

        // If type is today, require new_time
        if ($this->input('new_expected_delivery_date_type') === 'today') {
            $rules['new_time'] = 'required|date_format:H:i';
        }

        // If type is custom, require new_time and new_date
        if ($this->input('new_expected_delivery_date_type') === 'custom') {
            $rules['new_time'] = 'required|date_format:H:i';
            $rules['new_date'] = 'required|date|after_or_equal:today';
        }

        return $rules;
    }
}
