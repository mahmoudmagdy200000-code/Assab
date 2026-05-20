<?php

namespace Modules\Supplier\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channels' => 'sometimes|array',
            'channels.*' => 'in:app,email,sms,whatsapp',
            'order_notifications' => 'sometimes|boolean',
            'payment_notifications' => 'sometimes|boolean',
            'system_notifications' => 'sometimes|boolean',
        ];
    }
}
