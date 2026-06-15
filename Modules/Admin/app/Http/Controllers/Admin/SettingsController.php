<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Setting;

class SettingsController extends AsabController
{
    /**
     * Per-bucket defaults. Doc keys (BACKEND/ADMIN spec §8.x) are primary; old keys
     * are mirrored alongside for back-compat so existing readers keep working.
     */
    private const DEFAULTS = [
        'notifications' => [
            'approvalNotifications' => true, 'subscriptionAlerts' => true, 'dailyPerformanceReports' => true,
            // back-compat aliases
            'approvalEnabled' => true, 'subscriptionEnabled' => true, 'dailyReportsEnabled' => true,
        ],
        'backup' => [
            'dailyAutoBackup' => true, 'weeklyBackup' => true, 'dataEncryption' => true,
            // back-compat aliases
            'dailyAuto' => true, 'weekly' => true, 'encryption' => true,
        ],
        'api' => [
            'erpConnection' => false, 'paymentGatewayConnection' => false, 'mobileAppInterface' => false,
        ],
        'security' => [
            'twoFactorAuthRequired' => false, 'sessionDurationMinutes' => 120, 'passwordPolicyEnabled' => true,
            // back-compat aliases
            'twoFactor' => false,
        ],
    ];

    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $stored = Setting::where('company_id', $companyId)->orWhereNull('company_id')->get()->keyBy('group_key');

            $out = [];
            foreach (self::DEFAULTS as $group => $default) {
                $out[$group] = array_replace_recursive($default, optional($stored->get($group))->payload ?? []);
            }

            return $this->ok($out);
        });
    }

    public function update(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $data = $request->validate([
                'notifications' => 'sometimes|array',
                'notifications.approvalNotifications' => 'sometimes|boolean',
                'notifications.subscriptionAlerts' => 'sometimes|boolean',
                'notifications.dailyPerformanceReports' => 'sometimes|boolean',

                'backup' => 'sometimes|array',
                'backup.dailyAutoBackup' => 'sometimes|boolean',
                'backup.weeklyBackup' => 'sometimes|boolean',
                'backup.dataEncryption' => 'sometimes|boolean',

                'api' => 'sometimes|array',
                'api.erpConnection' => 'sometimes|boolean',
                'api.paymentGatewayConnection' => 'sometimes|boolean',
                'api.mobileAppInterface' => 'sometimes|boolean',

                'security' => 'sometimes|array',
                'security.twoFactorAuthRequired' => 'sometimes|boolean',
                'security.sessionDurationMinutes' => 'sometimes|integer|min:1',
                'security.passwordPolicyEnabled' => 'sometimes|boolean',
            ]);

            DB::transaction(function () use ($data, $companyId) {
                foreach ($data as $group => $payload) {
                    // Deep-merge the incoming bucket over what's already stored so a partial
                    // update doesn't wipe sibling keys.
                    $existing = optional(
                        Setting::where('company_id', $companyId)->where('group_key', $group)->first()
                    )->payload ?? [];
                    $merged = array_replace_recursive($existing, (array) $payload);

                    Setting::updateOrCreate(
                        ['company_id' => $companyId, 'group_key' => $group],
                        ['payload' => $merged],
                    );
                }
            });

            return $this->show($request);
        });
    }
}
