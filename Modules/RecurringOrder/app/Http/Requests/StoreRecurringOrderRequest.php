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
            'direct_supplier.notification_channels.*' => ['string', 'in:email,whatsapp,sms,app,in_app,in-app,in_app_notification,in-app-notification,inApp,push,velit'],
            'direct_supplier.message' => ['nullable', 'string', 'max:2000'],
            'direct_supplier.items' => ['required_with:direct_supplier', 'array', 'min:1'],
            'direct_supplier.items.*.item_id' => ['required', 'string', 'exists:items,id'],
            'direct_supplier.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'direct_supplier.items.*.quality' => ['nullable', 'string', 'max:50'],
            'direct_supplier.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],

            'purchase_officer' => ['nullable', 'array'],
            'purchase_officer.purchasing_officer_id' => ['required_with:purchase_officer', 'string', 'exists:branch_managers,id'],
            'purchase_officer.items' => ['required_with:purchase_officer', 'array', 'min:1'],
            'purchase_officer.items.*.item_id' => ['required', 'string', 'exists:items,id'],
            'purchase_officer.items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'purchase_officer.items.*.quality' => ['nullable', 'string', 'max:50'],
            'purchase_officer.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_officer.items.*.preferred_delivery_date' => ['nullable', 'date'],
            'purchase_officer.items.*.latest_delivery_date' => ['nullable', 'date'],
            'purchase_officer.items.*.special_instructions' => ['nullable', 'string', 'max:1000'],

            'repeat_frequency' => ['required', 'string', 'in:weekly,monthly,based_on_inventory'],
            'repeat_config' => ['nullable', 'array'],
            'repeat_config.repeat_days' => ['required_if:repeat_frequency,weekly', 'array'],
            'repeat_config.repeat_days.*' => ['integer', 'min:1', 'max:7'], // API: 1=Sunday .. 7=Saturday
            'repeat_config.repeat_type' => ['required_if:repeat_frequency,monthly', 'nullable', 'string', 'in:by_date,by_pattern'],
            'repeat_config.dates' => ['required_if:repeat_config.repeat_type,by_date', 'array'],
            'repeat_config.dates.*' => ['integer', 'min:1', 'max:31'],
            'repeat_config.occurrence' => ['required_if:repeat_config.repeat_type,by_pattern', 'nullable', 'integer', 'min:1', 'max:5'],
            'repeat_config.day_of_week' => ['required_if:repeat_config.repeat_type,by_pattern', 'nullable', 'integer', 'min:1', 'max:7'], // API: 1=Sunday .. 7=Saturday
            'repeat_config.level_ratio' => ['required_if:repeat_frequency,based_on_inventory', 'nullable', 'string', 'in:10,20,40,custom'],
            'repeat_config.custom_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scheduling_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'meridiem' => ['required_with:scheduling_time', 'string', 'in:am,pm'],
            'scheduling_time_am' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'scheduling_time_pm' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
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
        $this->normalizePurchaseOfficerId();
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
            $hasDirect = $this->filled('direct_supplier');
            $hasOfficer = $this->filled('purchase_officer');
            if (! $hasDirect && ! $hasOfficer) {
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
            if ($this->input('end_type') === 'date' && ! $this->filled('end_date')) {
                $validator->errors()->add('end_date', 'End date is required when end type is date.');
            }
            $this->validateLatestDeliveryDates($validator);
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

    /**
     * Allow branch_manager_id or officer_id as alias for purchasing_officer_id.
     */
    private function normalizePurchaseOfficerId(): void
    {
        $po = $this->input('purchase_officer');
        if (! is_array($po)) {
            return;
        }
        $id = $po['purchasing_officer_id'] ?? $po['branch_manager_id'] ?? $po['officer_id'] ?? null;
        if ($id !== null) {
            $po['purchasing_officer_id'] = $id;
            $this->merge(['purchase_officer' => $po]);
        }
    }

    /**
     * Ensure latest_delivery_date >= preferred_delivery_date per item when both are present.
     */
    private function validateLatestDeliveryDates(Validator $validator): void
    {
        $items = $this->input('purchase_officer.items', []);
        foreach ($items as $i => $item) {
            $preferred = $item['preferred_delivery_date'] ?? null;
            $latest = $item['latest_delivery_date'] ?? null;
            if ($preferred && $latest && strtotime($latest) < strtotime($preferred)) {
                $validator->errors()->add(
                    "purchase_officer.items.{$i}.latest_delivery_date",
                    'latest_delivery_date must be on or after preferred_delivery_date for this item.'
                );
            }
        }
    }
}
