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
            'items.*.item_id' => ['required_with:items', 'string', 'exists:items,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.quality' => ['nullable', 'string', 'max:50'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.preferred_delivery_date' => ['nullable', 'date'],
            'items.*.latest_delivery_date' => ['nullable', 'date'],
            'items.*.special_instructions' => ['nullable', 'string', 'max:1000'],
            'repeat_config' => ['nullable', 'array'],
            'repeat_config.repeat_days' => ['sometimes', 'array'],
            'repeat_config.repeat_days.*' => ['integer', 'min:1', 'max:7'], // API: 1=Sunday .. 7=Saturday
            'repeat_config.day_of_week' => ['sometimes', 'integer', 'min:1', 'max:7'], // API: 1=Sunday .. 7=Saturday
            'scheduling_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'meridiem' => ['required_with:scheduling_time', 'string', 'in:am,pm'],
            'scheduling_time_am' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'scheduling_time_pm' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'notification_options' => ['nullable', 'array'],
            'notification_options.*' => ['string', 'in:alert_24_hours_before,review_before_sending,send_automatically_without_review'],
            'smart_settings' => ['nullable', 'array'],
            'smart_settings.*' => ['string', 'in:auto_adjust_quantities_based_on_consumption,freeze_during_holidays_and_events,notify_when_prices_change'],
            'end_date' => ['nullable', 'date'],
            'end_type' => ['sometimes', 'string', 'in:repeat,date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $time = $this->input('scheduling_time');
        $meridiem = $this->input('meridiem');
        if ($time && in_array($meridiem, ['am', 'pm'], true)) {
            $parts = explode(':', $time);
            $hour = isset($parts[0]) ? (int) $parts[0] : 0;
            $min = isset($parts[1]) ? (int) $parts[1] : 0;
            if ($meridiem === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }
            $hour = $hour % 24;
            $time24 = sprintf('%02d:%02d', $hour, $min);
            if ($meridiem === 'am') {
                $this->merge(['scheduling_time_am' => $time24, 'scheduling_time_pm' => null]);
            } else {
                $this->merge(['scheduling_time_pm' => $time24, 'scheduling_time_am' => null]);
            }
        }
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

    /**
     * Convert API day numbers (1-7) to internal storage (0-6). Sunday=1 -> 0, Saturday=7 -> 6.
     */
    public function passedValidation(): void
    {
        $this->normalizeRepeatConfigDaysToZeroBased();
    }

    private function normalizeRepeatConfigDaysToZeroBased(): void
    {
        $config = $this->input('repeat_config');
        if (! is_array($config)) {
            return;
        }
        $updated = false;
        if (isset($config['repeat_days']) && is_array($config['repeat_days'])) {
            $config['repeat_days'] = array_values(array_map(fn ($d) => max(0, min(6, (int) $d - 1)), $config['repeat_days']));
            $updated = true;
        }
        if (isset($config['day_of_week']) && is_numeric($config['day_of_week'])) {
            $config['day_of_week'] = max(0, min(6, (int) $config['day_of_week'] - 1));
            $updated = true;
        }
        if ($updated) {
            $this->merge(['repeat_config' => $config]);
        }
    }
}
