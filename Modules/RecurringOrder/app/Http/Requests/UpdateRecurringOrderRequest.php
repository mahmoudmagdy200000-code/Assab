<?php

namespace Modules\RecurringOrder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRecurringOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_name' => ['sometimes', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'uuid', 'exists:items,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'repeat_config' => ['nullable', 'array'],
            'scheduling_time_am' => ['nullable', 'date_format:H:i'],
            'scheduling_time_pm' => ['nullable', 'date_format:H:i'],
            'notification_options' => ['nullable', 'array'],
            'notification_options.*' => ['string', 'in:alert_24_hours_before,review_before_sending,send_automatically_without_review'],
            'smart_settings' => ['nullable', 'array'],
            'smart_settings.*' => ['string', 'in:auto_adjust_quantities_based_on_consumption,freeze_during_holidays_and_events,notify_when_prices_change'],
            'end_date' => ['nullable', 'date'],
            'end_type' => ['sometimes', 'string', 'in:repeat,date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $opts = $this->input('notification_options', []);
            if (is_array($opts) && in_array('review_before_sending', $opts) && in_array('send_automatically_without_review', $opts)) {
                $validator->errors()->add(
                    'notification_options',
                    'Cannot select both "Review before sending" and "Send automatically without review".'
                );
            }
        });
    }
}
