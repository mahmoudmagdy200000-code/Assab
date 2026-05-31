<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Setting;

class SettingsController extends AsabController
{
    private const DEFAULTS = [
        'notifications' => ['approvalEnabled' => true, 'subscriptionEnabled' => true, 'dailyReportsEnabled' => true],
        'backup' => ['dailyAuto' => true, 'weekly' => true, 'encryption' => true],
        'api' => ['erpConnection' => [], 'paymentGateway' => [], 'mobileApp' => []],
        'security' => ['twoFactor' => false, 'sessionDurationMinutes' => 120, 'passwordPolicy' => ['minLength' => 8]],
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
                'backup' => 'sometimes|array',
                'api' => 'sometimes|array',
                'security' => 'sometimes|array',
            ]);

            DB::transaction(function () use ($data, $companyId) {
                foreach ($data as $group => $payload) {
                    Setting::updateOrCreate(
                        ['company_id' => $companyId, 'group_key' => $group],
                        ['payload' => $payload],
                    );
                }
            });

            return $this->show($request);
        });
    }
}
