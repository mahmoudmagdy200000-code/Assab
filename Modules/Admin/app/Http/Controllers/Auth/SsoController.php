<?php

namespace Modules\Admin\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\SsoService;

/**
 * Public SSO sign-in callback (FE completion request §3.2). OIDC authorization-code
 * flow; the company is identified by the `companyId` carried through state.
 */
class SsoController extends AsabController
{
    public function __construct(private readonly SsoService $sso) {}

    /** POST /auth/sso/{provider}/callback (public) → standard auth response */
    public function callback(Request $request, string $provider): JsonResponse
    {
        return $this->run(function () use ($request, $provider) {
            if ($provider !== 'oidc') {
                throw new AsabException('SSO_PROVIDER_UNSUPPORTED', 'Only OIDC sign-in is wired today', 'مزود الهوية غير مدعوم حالياً', 422, ['provider' => $provider]);
            }
            $data = $request->validate([
                'companyId' => 'required|string',
                'code' => 'required|string',
                'redirectUri' => 'required|url',
            ]);

            return $this->ok($this->sso->handleOidcCallback($data['companyId'], $data['code'], $data['redirectUri']));
        });
    }
}
