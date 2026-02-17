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
            'order_name' => ['nullable', 'string', 'max:255'],
            'direct_supplier' => ['nullable', 'array'],
            'direct_supplier.supplier_id' => ['required_with:direct_supplier', 'string', 'exists:suppliers,id'],
            'direct_supplier.notification_channels' => ['nullable', 'array'],
            'direct_supplier.notification_channels.*' => ['string', 'in:email,whatsapp,sms'],
            'direct_supplier.message' => ['nullable', 'string', 'max:2000'],
            'direct_supplier.items' => ['required_with:direct_supplier', 'array', 'min:1'],
            'direct_supplier.items.*.item_id' => ['required', 'string', 'exists:items,id'],
            'direct_supplier.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'direct_supplier.items.*.quality' => ['nullable', 'string', 'max:50'],

            'purchase_officer' => ['nullable', 'array'],
            'purchase_officer.purchasing_officer_id' => ['required_with:purchase_officer', 'string', 'exists:branch_managers,id'],
            'purchase_officer.items' => ['required_with:purchase_officer', 'array', 'min:1'],
            'purchase_officer.items.*.item_id' => ['required', 'string', 'exists:items,id'],
            'purchase_officer.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'purchase_officer.items.*.quality' => ['nullable', 'string', 'max:50'],
            'purchase_officer.items.*.preferred_delivery_date' => ['nullable', 'date'],
            'purchase_officer.items.*.latest_delivery_date' => ['nullable', 'date', 'after_or_equal:purchase_officer.items.*.preferred_delivery_date'],
            'purchase_officer.items.*.special_instructions' => ['nullable', 'string', 'max:1000'],

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
            'scheduling_time_am' => ['nullable'],
            'scheduling_time_pm' => ['nullable'],
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

    protected function prepareForValidation(): void
    {
        $am = $this->input('scheduling_time_am');
        $pm = $this->input('scheduling_time_pm');
        if ($am && $this->isDateTimeString($am)) {
            $this->merge(['scheduling_time_am' => $this->extractTime($am)]);
        }
        if ($pm && $this->isDateTimeString($pm)) {
            $this->merge(['scheduling_time_pm' => $this->extractTime($pm)]);
        }
    }

    private function isDateTimeString(?string $value): bool
    {
        if (!$value) {
            return false;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $value) === 1;
    }

    private function extractTime(string $value): string
    {
        try {
            $dt = \Carbon\Carbon::parse($value);
            return $dt->format('H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasDirect = $this->filled('direct_supplier');
            $hasOfficer = $this->filled('purchase_officer');
            if (!$hasDirect && !$hasOfficer) {
                $validator->errors()->add(
                    'direct_supplier',
                    'Either direct_supplier or purchase_officer must be provided.'
                );
                $validator->errors()->add(
                    'purchase_officer',
                    'Either direct_supplier or purchase_officer must be provided.'
                );
            }
            if ($hasDirect && $hasOfficer) {
                $validator->errors()->add(
                    'direct_supplier',
                    'Provide only one of direct_supplier or purchase_officer per request.'
                );
            }
            $opts = $this->input('notification_options', []);
            if (is_array($opts) && in_array('review_before_sending', $opts) && in_array('send_automatically_without_review', $opts)) {
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
