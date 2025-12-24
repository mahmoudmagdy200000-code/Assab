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
        return [
            'reason' => 'required|string|in:traffic,vehicle_issues,bad_weather,loading_delays,other',
            'explanation' => 'required|string|max:1000',
            'updated_eta' => 'required|date|after:now',
        ];
    }
}

