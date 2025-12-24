<?php

namespace Modules\Supplier\Http\Requests\RecurringOrders;

use Illuminate\Foundation\Http\FormRequest;

class ModifyScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule' => 'required|array',
            'frequency' => 'required|string|in:daily,weekly,monthly',
        ];
    }
}

