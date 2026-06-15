<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CompanyPreferences;
use Modules\Admin\Models\CompanySettings;

/**
 * Company settings & preferences (COMPANY_DASHBOARD_API_SPEC.md §5.1.7).
 */
class SettingsController extends AsabController
{
    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $s = CompanySettings::firstOrCreate(['company_id' => $request->user()->company_id], ['legal_name' => '']);

            return $this->ok($this->present($s));
        });
    }

    public function update(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                // Doc body (COMPANY settings): {name, city, crNumber, email}. `name`/`city`
                // are aliases mapped to the existing legalName/displayName + primaryCity columns.
                'name' => 'sometimes|string|max:200', 'city' => 'sometimes|string|max:80',
                'legalName' => 'sometimes|string|max:200', 'displayName' => 'sometimes|string|max:200',
                'primaryCity' => 'sometimes|string|max:80', 'crNumber' => 'sometimes|nullable|string|max:32',
                'taxId' => 'sometimes|nullable|string|max:32', 'email' => 'sometimes|nullable|email',
                'phone' => 'sometimes|nullable|string|max:32', 'website' => 'sometimes|nullable|string|max:255',
                'addressLine' => 'sometimes|nullable|string', 'defaultCurrency' => 'sometimes|string|size:3',
                'defaultTimezone' => 'sometimes|string|max:64', 'defaultLanguage' => 'sometimes|string|size:2',
                'vatPercentage' => 'sometimes|integer|min:0|max:100', 'fiscalYearStart' => 'sometimes|string|max:8',
                'brandColor' => 'sometimes|nullable|string|max:16',
            ]);

            // Doc alias `name` -> legal_name + display_name (fall back to the old camelCase keys).
            $name = $data['name'] ?? $data['legalName'] ?? null;
            $city = $data['city'] ?? $data['primaryCity'] ?? null;

            $map = [
                'legalName' => 'legal_name', 'displayName' => 'display_name', 'primaryCity' => 'primary_city',
                'crNumber' => 'cr_number', 'taxId' => 'tax_id', 'email' => 'email', 'phone' => 'phone',
                'website' => 'website', 'addressLine' => 'address_line', 'defaultCurrency' => 'default_currency',
                'defaultTimezone' => 'default_timezone', 'defaultLanguage' => 'default_language',
                'vatPercentage' => 'vat_percentage', 'fiscalYearStart' => 'fiscal_year_start', 'brandColor' => 'brand_color',
            ];
            $attrs = ['updated_at' => now(), 'updated_by_id' => $request->user()->id];
            foreach ($map as $in => $col) {
                if (array_key_exists($in, $data)) {
                    $attrs[$col] = $data[$in];
                }
            }
            // Apply doc aliases last so an explicit `name`/`city` wins over the legacy keys.
            if ($name !== null) {
                $attrs['legal_name'] = $name;
                if (! array_key_exists('displayName', $data) && ! array_key_exists('display_name', $attrs)) {
                    $attrs['display_name'] = $name;
                }
            }
            if (array_key_exists('name', $data) && array_key_exists('displayName', $data)) {
                $attrs['display_name'] = $data['displayName'];
            }
            if ($city !== null) {
                $attrs['primary_city'] = $city;
            }

            $s = CompanySettings::firstOrCreate(['company_id' => $request->user()->company_id], ['legal_name' => $name ?? '']);
            $s->update($attrs);

            return $this->ok($this->present($s->fresh()));
        });
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['file' => 'required|file|image|max:2048']);
            $path = $request->file('file')->store('company-logos', 'public');
            $url = '/storage/'.$path;
            CompanySettings::where('company_id', $request->user()->company_id)->update(['logo_url' => $url, 'updated_at' => now()]);

            return $this->ok(['logoUrl' => $url]);
        });
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'notifyOnApproval' => 'sometimes|boolean', 'notifyOnRejection' => 'sometimes|boolean',
                'notifyOnLowStock' => 'sometimes|boolean', 'notifyOnSubscriptionExpiring' => 'sometimes|boolean',
                'autoReminderEnabled' => 'sometimes|boolean', 'reminderTriggerHour' => 'sometimes|string|max:8',
                'reminderRepeatHours' => 'sometimes|integer|min:1', 'posIntegrations' => 'sometimes|array',
                'deliveryAppIntegrations' => 'sometimes|array',
            ]);
            $map = [
                'notifyOnApproval' => 'notify_on_approval', 'notifyOnRejection' => 'notify_on_rejection',
                'notifyOnLowStock' => 'notify_on_low_stock', 'notifyOnSubscriptionExpiring' => 'notify_on_sub_expiring',
                'autoReminderEnabled' => 'auto_reminder_enabled', 'reminderTriggerHour' => 'reminder_trigger_hour',
                'reminderRepeatHours' => 'reminder_repeat_hours', 'posIntegrations' => 'pos_integrations',
                'deliveryAppIntegrations' => 'delivery_app_integrations',
            ];
            $attrs = [];
            foreach ($map as $in => $col) {
                if (array_key_exists($in, $data)) {
                    $attrs[$col] = $data[$in];
                }
            }
            $p = CompanyPreferences::firstOrCreate(['company_id' => $request->user()->company_id]);
            $p->update($attrs);

            return $this->ok(['companyId' => $p->company_id, 'updated' => array_keys($attrs)]);
        });
    }

    private function present(CompanySettings $s): array
    {
        return [
            'companyId' => $s->company_id,
            // Doc field names (superset) — mirror the persisted columns.
            'name' => $s->legal_name, 'city' => $s->primary_city,
            'legalName' => $s->legal_name, 'displayName' => $s->display_name,
            'logoEmoji' => $s->logo_emoji, 'logoUrl' => $s->logo_url, 'primaryCity' => $s->primary_city,
            'crNumber' => $s->cr_number, 'taxId' => $s->tax_id, 'email' => $s->email, 'phone' => $s->phone,
            'website' => $s->website, 'addressLine' => $s->address_line, 'defaultCurrency' => $s->default_currency,
            'defaultTimezone' => $s->default_timezone, 'defaultLanguage' => $s->default_language,
            'vatPercentage' => $s->vat_percentage, 'fiscalYearStart' => $s->fiscal_year_start, 'brandColor' => $s->brand_color,
        ];
    }
}
