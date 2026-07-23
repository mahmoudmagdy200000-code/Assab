<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabBrandPackage;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Notifications\UserPasswordResetNotification;
use Modules\Admin\Services\AccountantScopeService;
use Modules\Admin\Services\AsabSubscriptionService;
use Modules\Admin\Services\BrandCompanyResolver;
use Modules\Admin\Services\BrandOwnerProvisioningService;
use Modules\Admin\Services\CredentialMailer;
use Modules\Admin\Services\CredentialSyncService;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Branch\Models\Branch;

class BrandController extends AsabController
{
    public function __construct(private readonly AccountantScopeService $scope) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = AsabBrand::query();
            if ($companyId = $request->query('companyId')) {
                $q->where('company_id', $companyId);
            }
            $brands = $q->orderBy('name')->get();

            return $this->listResponse($brands->map(fn ($b) => $this->present($b, true))->all());
        });
    }

    public function store(
        Request $request,
        BrandOwnerProvisioningService $ownerProvisioning,
        BrandCompanyResolver $companyResolver,
        CredentialMailer $mailer,
    ): JsonResponse {
        return $this->run(function () use ($request, $ownerProvisioning, $companyResolver, $mailer) {
            // WS6: legacy Arabic aliases map to package codes before validating
            // against the asab_brand_packages catalog (silver/gold/platinum keep
            // working via the seeded rows / legacy fallback).
            if ($request->filled('plan')) {
                $request->merge(['plan' => AsabBrandPackage::resolveCode((string) $request->input('plan'))]);
            }
            $data = $request->validate([
                // Company selection was removed from Add Brand: every brand now
                // auto-creates a company of its own (resolveFor(null, …)). Any
                // companyId the client still sends is ignored, not honoured.
                'name' => 'required|string|max:120',
                'abbr' => 'nullable|string|max:8',
                'color' => 'nullable|string|max:16',
                // Doc §1.2: owner is the brand owner's display NAME (persisted to the
                // brand `owner` attribute, previously never set). ownerEmail stays.
                'owner' => 'nullable|string|max:191',
                'ownerEmail' => 'nullable|email|max:191',
                'plan' => ['nullable', 'string', 'max:32', AsabBrandPackage::codeRule()],
                'modules' => 'nullable|array',
            ]);

            // B-A6: when an ownerEmail is supplied, provision/link a brand-owner login
            // inside the same transaction so a conflict (422) or failure rolls back the
            // brand too. The welcome mail is sent only after the commit — see below.
            [$brand, $owner] = DB::transaction(function () use ($data, $ownerProvisioning, $companyResolver) {
                $brand = AsabBrand::create([
                    'company_id' => $companyResolver->resolveFor(null, $data['name']),
                    'name' => $data['name'],
                    'abbr' => $data['abbr'] ?? null,
                    'color' => $data['color'] ?? null,
                    'owner' => $data['owner'] ?? null,
                    'owner_email' => $data['ownerEmail'] ?? null,
                    'plan' => $data['plan'] ?? null,
                    'sub_status' => 'active',
                    'modules' => $data['modules'] ?? [],
                    'status' => 'active',
                ]);

                $owner = null;
                if (! empty($data['ownerEmail'])) {
                    $owner = $ownerProvisioning->provision($brand, $data['ownerEmail'], $data['owner'] ?? null);
                    $brand->update(['owner_user_id' => $owner['user']->id]);
                }

                return [$brand, $owner];
            });

            $emailSent = $owner !== null && $mailer->send($owner['user'], $owner['notification']);

            return $this->created(array_merge($this->present($brand->fresh()), [
                'ownerUserId' => $owner['user']->id ?? null,
                'emailSent' => $emailSent,
            ]));
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $brand = AsabBrand::findOrFail($id);
            if ($request->filled('plan')) {
                $request->merge(['plan' => AsabBrandPackage::resolveCode((string) $request->input('plan'))]);
            }
            $data = $request->validate([
                'name' => 'sometimes|string|max:120',
                'abbr' => 'sometimes|string|max:8',
                'color' => 'sometimes|string|max:16',
                'plan' => ['sometimes', 'string', 'max:32', AsabBrandPackage::codeRule()],
                'modules' => 'sometimes|array',
            ]);
            DB::transaction(fn () => $brand->update(array_filter([
                'name' => $data['name'] ?? null,
                'abbr' => $data['abbr'] ?? null,
                'color' => $data['color'] ?? null,
                'plan' => $data['plan'] ?? null,
                'modules' => $data['modules'] ?? null,
            ], fn ($v) => $v !== null)));

            return $this->ok($this->present($brand->fresh()));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            AsabBrand::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    /**
     * POST /admin/brands/{brandId}/auto-reminder — toggle the brand-level
     * auto-reminder switch (FE completion request §1.2). The toggle lives on the
     * brand row in AdminSubscriptions, not on a subscription id.
     */
    public function autoReminder(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $brandId) {
            $data = $request->validate(['enabled' => 'required|boolean']);
            $brand = AsabBrand::findOrFail($brandId);
            $brand->update(['auto_reminder_enabled' => $data['enabled']]);

            return $this->ok([
                'brandId' => $brand->id,
                'enabled' => (bool) $brand->auto_reminder_enabled,
                'updatedAt' => optional($brand->updated_at)->toIso8601String(),
            ]);
        });
    }

    /**
     * POST /admin/brands/{brandId}/subscription/renew — extend the brand's
     * subscription by N months (Admin dashboard contract batch 1, A1). The brand
     * row carries its own subscription state (sub_status/expires/days_left), the
     * same fields the Overview tree reads, so renewal lives here (not on a
     * subscription id), mirroring autoReminder().
     */
    public function renewSubscription(Request $request, AsabSubscriptionService $subs, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $subs, $brandId) {
            $data = $request->validate(['months' => 'sometimes|integer|min:1|max:36']);
            $brand = AsabBrand::findOrFail($brandId);
            $renewal = $subs->computeRenewal($brand->expires, (int) ($data['months'] ?? 12));

            DB::transaction(fn () => $brand->update([
                'sub_status' => 'active',
                'expires' => $renewal['expires'],
                'days_left' => $renewal['daysLeft'],
            ]));

            return $this->ok($this->presentSubscription($brand->fresh()));
        });
    }

    /**
     * POST /admin/brands/{brandId}/subscription/activate — reactivate an expired
     * brand (Admin dashboard contract batch 1, A2). Starts a fresh 12-month term
     * when the brand has no future expiry, otherwise just flips the status.
     */
    public function activateSubscription(AsabSubscriptionService $subs, string $brandId): JsonResponse
    {
        return $this->run(function () use ($subs, $brandId) {
            $brand = AsabBrand::findOrFail($brandId);

            $payload = ['sub_status' => 'active'];
            if (! $brand->expires || $brand->expires->isPast()) {
                $renewal = $subs->computeRenewal(null, 12);
                $payload['expires'] = $renewal['expires'];
                $payload['days_left'] = $renewal['daysLeft'];
            } else {
                $payload['days_left'] = (int) now()->diffInDays($brand->expires);
            }

            DB::transaction(fn () => $brand->update($payload));

            return $this->ok($this->presentSubscription($brand->fresh()));
        });
    }

    /**
     * POST /admin/brands/{brandId}/owner/reset-password (B-A6) — regenerate the
     * brand owner's password and email it. Never returns plaintext. Mirrors
     * CompanyController@resetAdminPassword; locates the owner via owner_user_id
     * (falling back to owner_email) since brand ownership is not a company role.
     */
    public function resetOwnerPassword(Request $request, CredentialSyncService $credentials, CredentialMailer $mailer, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $credentials, $mailer, $brandId) {
            $data = $request->validate(['notify' => 'sometimes|boolean']);
            $brand = AsabBrand::findOrFail($brandId);
            $owner = $this->resolveBrandOwner($brand);
            if (! $owner) {
                throw new AsabException('NOT_FOUND', 'Brand has no owner account', 'لا يوجد حساب مالك لهذه العلامة التجارية', 404);
            }

            $temporaryPassword = Str::password(12);
            $resetAt = now();

            DB::transaction(function () use ($owner, $temporaryPassword, $credentials) {
                $owner->forceFill(['password' => $temporaryPassword])->save();
                $owner->tokens()->delete();
                // The mobile app authenticates against brand_owners, which this
                // write cannot reach; without the push the emailed password
                // would open the dashboard only.
                $credentials->pushToMobile($owner, forceReset: true);
            });

            $emailSent = ($data['notify'] ?? true)
                ? $mailer->send($owner, new UserPasswordResetNotification($temporaryPassword))
                : false;

            return $this->ok([
                'ok' => true,
                'emailedTo' => $owner->email,
                'emailSent' => $emailSent,
                'resetAt' => $resetAt->toIso8601String(),
            ]);
        });
    }

    /** Resolve a brand's owner user — by stored owner_user_id first, then owner_email. */
    private function resolveBrandOwner(AsabBrand $b): ?AsabUser
    {
        if ($b->owner_user_id && ($user = AsabUser::find($b->owner_user_id))) {
            return $user;
        }

        if ($b->owner_email) {
            return AsabUser::where('email', $b->owner_email)->first();
        }

        return null;
    }

    /** Brand subscription response shape (A1/A2). */
    private function presentSubscription(AsabBrand $b): array
    {
        return [
            'brandId' => $b->id,
            'subStatus' => $b->sub_status,
            'daysLeft' => $b->days_left,
            'expiresAt' => optional($b->expires)->toIso8601String(),
        ];
    }

    private function present(AsabBrand $b, bool $withChildren = false): array
    {
        // Modules are a fixed catalog (SRS §2.3 «الموديولات التسعة»); a brand with
        // no explicit subset is granted the full set, so the module widget shows
        // the real count instead of 0. `modules` stays as stored for compat.
        $effectiveModules = ! empty($b->modules) ? $b->modules : ModuleCatalog::keys();

        $data = [
            'id' => $b->id,
            'companyId' => $b->company_id,
            'name' => $b->name,
            'abbr' => $b->abbr,
            'color' => $b->color,
            'owner' => $b->owner,
            'ownerEmail' => $b->owner_email,
            'ownerUserId' => $b->owner_user_id,
            'plan' => $b->plan,
            'subStatus' => $b->sub_status,
            'daysLeft' => $b->days_left,
            'modules' => $b->modules ?? [],
            'moduleCount' => count($effectiveModules),
            'status' => $b->status,
        ];

        if ($withChildren) {
            $restaurants = AsabRestaurant::where('brand_id', $b->id)->orderBy('name')->get();

            // One query for all branches under this brand's restaurants (no per-row N+1).
            $branchesByRestaurant = Branch::whereIn('asab_restaurant_id', $restaurants->pluck('id'))
                ->orderBy('name')->get(['id', 'name', 'manager', 'asab_restaurant_id'])
                ->groupBy('asab_restaurant_id');

            // Real accountant coverage per restaurant (brand-scoped assignments),
            // computed once — the stored accountant_count is not maintained.
            $accountantCounts = $this->scope->accountantCounts($restaurants);

            $data['restaurants'] = $restaurants->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'city' => $r->city,
                'status' => $r->status,
                'accountants' => $accountantCounts[$r->id] ?? 0,
                'branches' => ($branchesByRestaurant[$r->id] ?? collect())
                    ->map(fn ($br) => ['id' => $br->id, 'name' => $br->name, 'manager' => $br->manager])
                    ->values()->all(),
            ])->all();
        }

        return $data;
    }
}
