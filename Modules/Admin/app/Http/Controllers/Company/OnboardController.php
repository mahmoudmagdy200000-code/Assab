<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyInvitation;
use Modules\Admin\Models\CompanyModule;
use Modules\Admin\Models\CompanyPreferences;
use Modules\Admin\Models\CompanySettings;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Plan;
use Modules\Admin\Services\AuthService;

/**
 * Company onboarding + invitation acceptance (COMPANY_DASHBOARD_API_SPEC.md §4.2).
 */
class OnboardController extends AsabController
{
    public function __construct(private readonly AuthService $auth) {}

    /** Bootstrap initial structure for a freshly-created company-admin. */
    public function onboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $data = $request->validate([
                'legalName' => 'required|string|max:200', 'primaryCity' => 'required|string|max:80',
                'crNumber' => 'sometimes|nullable|string|max:32', 'taxId' => 'sometimes|nullable|string|max:32',
                'contactEmail' => 'required|email', 'contactPhone' => 'sometimes|nullable|string|max:32',
                'logoEmoji' => 'sometimes|nullable|string|max:16',
                'initialBrands' => 'sometimes|array', 'initialBrands.*.name' => 'required_with:initialBrands|string',
                'initialBrands.*.abbr' => 'required_with:initialBrands|string', 'initialBrands.*.color' => 'required_with:initialBrands|string',
                'selectedPlanCode' => 'required|in:basic,professional,enterprise', 'billingCycle' => 'required|in:monthly,annual',
            ]);

            $plan = Plan::where('code', $data['selectedPlanCode'])->firstOrFail();

            $result = DB::transaction(function () use ($companyId, $data, $plan) {
                $company = AsabCompany::findOrFail($companyId);
                $company->update(['name' => $data['legalName'], 'city' => $data['primaryCity'], 'logo' => $data['logoEmoji'] ?? '🏢', 'contact_email' => $data['contactEmail']]);

                CompanySettings::updateOrCreate(['company_id' => $companyId], [
                    'legal_name' => $data['legalName'], 'primary_city' => $data['primaryCity'], 'logo_emoji' => $data['logoEmoji'] ?? '🏢',
                    'cr_number' => $data['crNumber'] ?? null, 'tax_id' => $data['taxId'] ?? null, 'email' => $data['contactEmail'],
                    'phone' => $data['contactPhone'] ?? null, 'updated_at' => now(),
                ]);
                CompanyPreferences::firstOrCreate(['company_id' => $companyId]);

                foreach ($plan->modules_included ?? [] as $m) {
                    CompanyModule::updateOrCreate(['company_id' => $companyId, 'module_key' => $m], ['is_active' => true, 'is_in_plan' => true]);
                }

                foreach ($data['initialBrands'] ?? [] as $b) {
                    AsabBrand::create(['company_id' => $companyId, 'name' => $b['name'], 'abbr' => $b['abbr'], 'color' => $b['color'], 'status' => 'active']);
                }

                $sub = CompanySubscription::updateOrCreate(['company_id' => $companyId], [
                    'plan_id' => $plan->id, 'status' => 'trial', 'billing_cycle' => $data['billingCycle'],
                    'current_period_start' => now(), 'current_period_end' => now()->addDays(14),
                    'trial_ends_at' => now()->addDays(14), 'start_date' => now(), 'days_remaining' => 14, 'auto_renew' => true,
                ]);

                return ['company' => $company->fresh(), 'subscription' => $sub];
            });

            return $this->ok([
                'company' => ['id' => $result['company']->id, 'name' => $result['company']->name, 'city' => $result['company']->city],
                'subscription' => ['id' => $result['subscription']->id, 'status' => $result['subscription']->status, 'planId' => $result['subscription']->plan_id],
                'nextStep' => 'add_payment_method',
            ]);
        });
    }

    /** Public — accept an invitation token and create/activate the user. */
    public function acceptInvitation(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt): JsonResponse
    {
        return $this->run(function () use ($request, $rt) {
            $data = $request->validate([
                'token' => 'required|string', 'name' => 'required|string|max:200',
                'password' => 'required|string|min:8', 'phone' => 'sometimes|nullable|string|max:32',
            ]);

            $inv = CompanyInvitation::where('token', $data['token'])->first();
            if (! $inv || $inv->status !== 'pending') {
                throw new AsabException('INVALID_INVITATION', 'Invitation invalid or used', 'الدعوة غير صالحة أو مستخدمة', 422);
            }
            if ($inv->expires_at->isPast()) {
                $inv->update(['status' => 'expired']);
                throw new AsabException('INVITATION_EXPIRED', 'Invitation expired', 'انتهت صلاحية الدعوة', 422);
            }

            // Zero-trust: an email already registered to a DIFFERENT company must
            // never be re-homed (updateOrCreate would silently overwrite its
            // company_id + password = cross-tenant account takeover).
            $existing = AsabUser::where('email', $inv->email)->first();
            if ($existing && $existing->company_id !== null && $existing->company_id !== $inv->company_id) {
                throw new AsabException('EMAIL_IN_OTHER_COMPANY', 'Email is registered to another company', 'البريد مسجل في شركة أخرى', 409);
            }
            // Never overwrite an established account's password on accept.
            $preservePassword = $existing && $existing->company_id !== null;

            $result = DB::transaction(function () use ($inv, $data, $preservePassword) {
                $user = AsabUser::updateOrCreate(['email' => $inv->email], array_merge([
                    'company_id' => $inv->company_id, 'name' => $data['name'], 'avatar' => mb_substr($data['name'], 0, 1),
                    'phone' => $data['phone'] ?? null, 'status' => 'active',
                    'default_page' => $this->defaultPageFor($inv->role_key),
                ], $preservePassword ? [] : ['password' => $data['password']]));
                AsabUserRole::updateOrCreate(['user_id' => $user->id, 'role_key' => $inv->role_key], [
                    'scope' => $inv->branch_id ? 'branch' : ($inv->brand_id ? 'brand' : 'all'),
                    'brand_ids' => $inv->brand_id ? [$inv->brand_id] : [], 'restaurant_ids' => [], 'branch_ids' => $inv->branch_id ? [$inv->branch_id] : [], 'module_keys' => [],
                ]);
                CompanyUser::updateOrCreate(['company_id' => $inv->company_id, 'user_id' => $user->id], [
                    'role_key' => $inv->role_key, 'brand_id' => $inv->brand_id, 'branch_id' => $inv->branch_id,
                    'status' => 'active', 'invited_by_id' => $inv->invited_by_id, 'accepted_at' => now(),
                ]);
                $inv->update(['status' => 'accepted', 'accepted_at' => now()]);

                return $user;
            });

            $member = CompanyUser::where('company_id', $inv->company_id)->where('user_id', $result->id)->first();
            if ($member) {
                $rt->userLifecycle($inv->company_id, 'joined', $member);
            }

            $tokens = $this->auth->issueTokens($result);

            return $this->ok([
                'user' => ['id' => $result->id, 'name' => $result->name, 'email' => $result->email],
                'accessToken' => $tokens['accessToken'], 'refreshToken' => $tokens['refreshToken'],
                'companyId' => $result->company_id, 'defaultPage' => $result->default_page,
            ]);
        });
    }

    private function defaultPageFor(string $roleKey): string
    {
        return ['company-admin' => 'ca-dashboard', 'head' => 'head-dashboard', 'accountant' => 'acc-dashboard',
            'branch' => 'branch-overview', 'procurement' => 'proc-overview'][$roleKey] ?? 'ca-dashboard';
    }
}
