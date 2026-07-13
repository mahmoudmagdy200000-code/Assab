<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\PlanLimitService;
use Modules\Admin\Services\SsoService;

/**
 * Company SSO configuration (FE completion request §3.2). Enterprise plan only.
 */
class SsoController extends AsabController
{
    public function __construct(
        private readonly SsoService $sso,
        private readonly PlanLimitService $limits,
    ) {}

    /** GET /company/me/sso */
    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $cfg = $this->sso->get($request->user()->company_id);

            return $this->ok($cfg ? $this->sso->present($cfg) : ['enabled' => false, 'provider' => null]);
        });
    }

    /** PUT /company/me/sso */
    public function update(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $this->assertEnterprise($request);
            $data = $request->validate([
                'provider' => 'required|in:saml,oidc',
                'enabled' => 'sometimes|boolean',
                'metadataUrl' => 'sometimes|nullable|url|max:500',
                'metadata' => 'sometimes|nullable|string',
                'entityId' => 'sometimes|nullable|string|max:255',
                'x509cert' => 'sometimes|nullable|string',
                'oidcIssuer' => 'required_if:provider,oidc|nullable|url|max:500',
                'oidcClientId' => 'required_if:provider,oidc|nullable|string|max:255',
                'oidcClientSecret' => 'sometimes|nullable|string|max:500',
                'defaultRole' => 'sometimes|in:accountant,head,branch,procurement',
            ]);

            $cfg = $this->sso->upsert($request->user()->company_id, $data);

            return $this->ok($this->sso->present($cfg));
        });
    }

    /** DELETE /company/me/sso → 204 (disable SSO, fall back to password) */
    public function destroy(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $this->sso->disable($request->user()->company_id);

            return $this->noContent();
        });
    }

    private function assertEnterprise(Request $request): void
    {
        // Gate on the LIVE subscription plan code (a portal self-upgrade only
        // mutates CompanySubscription; the legacy asab_companies.plan string is
        // platform-admin-set and would never unlock SSO after a self-upgrade).
        $plan = $this->limits->planFor($request->user()->company_id);
        if (($plan?->code) !== 'enterprise') {
            throw new AsabException('PLAN_REQUIRED', 'SSO requires the Enterprise plan', 'يتطلب تسجيل الدخول الموحد خطة Enterprise', 403, ['plan' => $plan?->code]);
        }
    }
}
