<?php

namespace Modules\RecurringOrder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRecurringOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'order_name' => ['required', 'string', 'max:255'],
            'order_source_type' => ['required', 'string', 'in:direct_supplier,via_purchasing_officer'],
            'supplier_id' => ['required_if:order_source_type,direct_supplier', 'nullable', 'uuid', 'exists:suppliers,id'],
            'purchasing_officer_id' => ['required_if:order_source_type,via_purchasing_officer', 'nullable', 'uuid', 'exists:branch_managers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'repeat_frequency' => ['required', 'string', 'in:weekly,monthly,based_on_inventory'],
            'repeat_config' => ['nullable', 'array'],
            'repeat_config.repeat_days' => ['required_if:repeat_frequency,weekly', 'array'],
            'repeat_config.repeat_days.*' => ['integer', 'min:0', 'max:6'],
            'repeat_config.repeat_type' => ['required_if:repeat_frequency,monthly', 'nullable', 'string', 'in:by_date,by_pattern'],
            'repeat_config.dates' => ['required_if:repeat_config.repeat_type,by_date', 'array'],
            'repeat_config.dates.*' => ['integer', 'min:1', 'max:31'],
            'repeat_config.occurrence' => ['required_if:repeat_config.repeat_type,by_pattern', 'nullable', 'integer', 'min:1', 'max:5'],
            'repeat_config.day_of_week' => ['required_if:repeat_config.repeat_type,by_pattern', 'nullable', 'integer', 'min:0', 'max:6'],
            'repeat_config.level_ratio' => ['required_if:repeat_frequency,based_on_inventory', 'nullable', 'string', 'in:10,20,40,custom'],
            'repeat_config.custom_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scheduling_time_am' => ['nullable', 'date_format:H:i'],
            'scheduling_time_pm' => ['nullable', 'date_format:H:i'],
            'notification_options' => ['nullable', 'array'],
            'notification_options.*' => ['string', 'in:alert_24_hours_before,review_before_sending,send_automatically_without_review'],
            'smart_settings' => ['nullable', 'array'],
            'smart_settings.*' => ['string', 'in:auto_adjust_quantities_based_on_consumption,freeze_during_holidays_and_events,notify_when_prices_change'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'end_type' => ['required', 'string', 'in:repeat,date'],
        ];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $opts = $this->input('notification_options', []);
            if (in_array('review_before_sending', $opts) && in_array('send_automatically_without_review', $opts)) {
                $validator->errors()->add(
                    'notification_options',
                    'Cannot select both "Review before sending" and "Send automatically without review".'
                );
            }
            if ($this->input('end_type') === 'date' && !$this->filled('end_date')) {
                $validator->errors()->add('end_date', 'End date is required when end type is date.');
            }
        });
    }
}
