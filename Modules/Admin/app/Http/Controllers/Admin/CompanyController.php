<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Notifications\SubscriptionReminderNotification;
use Modules\Admin\Notifications\UserPasswordResetNotification;
use Modules\Admin\Services\AuditService;
use Modules\Admin\Services\CompanyProvisioningService;
use Modules\Admin\Services\CredentialMailer;
use Modules\Admin\Services\CredentialSyncService;
use Modules\Admin\Services\NotificationService;
use Modules\Branch\Models\Branch;

class CompanyController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = AsabCompany::query();

            if ($search = $request->query('search')) {
                $q->where('name', 'like', "%{$search}%");
            }
            $filter = $request->query('filter');
            if ($filter && $filter !== 'all') {
                in_array($filter, ['Basic', 'Professional', 'Enterprise'], true)
                    ? $q->where('plan', $filter)
                    : $q->where('status', $filter);
            }
            // Explicit plan/status params (contract batch 1, Part C) — alongside `filter`.
            if (($plan = $request->query('plan')) && $plan !== 'all') {
                $q->where('plan', $plan);
            }
            if (($status = $request->query('status')) && $status !== 'all') {
                $q->where('status', $status);
            }

            $p = $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $counts = $this->buildCounts(collect($p->items())->pluck('id')->all());

            return $this->paginated($p, array_map(fn ($c) => $this->present($c, $counts), $p->items()));
        });
    }

    /**
     * POST /admin/companies — create a company (FE completion request §1.4).
     * Side-effects: auto-create the company-admin account + welcome email, and
     * (for annual billing) provision the first invoice.
     */
    public function store(Request $request, CompanyProvisioningService $provisioning): JsonResponse
    {
        return $this->run(function () use ($request, $provisioning) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'logo' => 'nullable|string|max:255',
                'contactName' => 'nullable|string|max:200',
                'contactEmail' => 'nullable|email|max:191',
                'contactPhone' => 'nullable|string|max:32',
                'city' => 'nullable|string|max:80',
                'plan' => 'required|in:Basic,Professional,Enterprise',
                'billingCycle' => 'sometimes|in:monthly,annual',
                'branchesLimitOverride' => 'sometimes|integer|min:1|max:1000',
                'modules' => 'nullable|array',
                'modules.*' => 'string',
                'adminEmail' => 'nullable|email|max:191',
            ]);

            $adminEmail = $data['contactEmail'] ?? $data['adminEmail'] ?? null;
            $billingCycle = $data['billingCycle'] ?? 'monthly';

            [$company, $adminUser] = DB::transaction(function () use ($data, $provisioning, $adminEmail, $billingCycle) {
                $company = AsabCompany::create([
                    'name' => $data['name'],
                    'logo' => $data['logo'] ?? null,
                    'contact_name' => $data['contactName'] ?? null,
                    'contact_email' => $data['contactEmail'] ?? null,
                    'contact_phone' => $data['contactPhone'] ?? null,
                    'city' => $data['city'] ?? null,
                    'plan' => $data['plan'],
                    'status' => 'trial',
                    'max_branches' => $data['branchesLimitOverride'] ?? $this->planLimit($data['plan'], 'branches'),
                    'max_users' => $this->planLimit($data['plan'], 'users'),
                    'modules' => $data['modules'] ?? [],
                    'admin_email' => $adminEmail,
                    'start_date' => now(),
                    'next_billing' => $billingCycle === 'annual' ? now()->addYear() : now()->addMonth(),
                ]);

                $adminUser = $adminEmail
                    ? $provisioning->createAdminUser($company, $adminEmail, $data['contactName'] ?? null, $data['contactPhone'] ?? null)
                    : null;

                if ($billingCycle === 'annual') {
                    $provisioning->createAnnualInvoice($company, $data['plan']);
                }

                return [$company, $adminUser];
            });

            return $this->created(array_merge($this->present($company), [
                'adminUserId' => $adminUser?->id,
            ]));
        });
    }

    public function show(string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present(AsabCompany::findOrFail($id))));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $company = AsabCompany::findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200',
                'contactName' => 'sometimes|string|max:200',
                'contactEmail' => 'sometimes|email|max:191',
                'contactPhone' => 'sometimes|string|max:32',
                'city' => 'sometimes|string|max:80',
                'plan' => 'sometimes|in:Basic,Professional,Enterprise',
                'modules' => 'sometimes|array',
            ]);

            DB::transaction(function () use ($company, $data) {
                $company->fill(array_filter([
                    'name' => $data['name'] ?? null,
                    'contact_name' => $data['contactName'] ?? null,
                    'contact_email' => $data['contactEmail'] ?? null,
                    'contact_phone' => $data['contactPhone'] ?? null,
                    'city' => $data['city'] ?? null,
                    'plan' => $data['plan'] ?? null,
                ], fn ($v) => $v !== null));
                if (isset($data['modules'])) {
                    $company->modules = $data['modules'];
                }
                $company->save();
            });

            return $this->ok($this->present($company->fresh()));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            AsabCompany::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    public function suspend(string $id): JsonResponse
    {
        return $this->setStatus($id, 'suspended');
    }

    public function activate(string $id): JsonResponse
    {
        return $this->setStatus($id, 'active');
    }

    private function setStatus(string $id, string $status): JsonResponse
    {
        return $this->run(function () use ($id, $status) {
            $company = AsabCompany::findOrFail($id);
            $company->update(['status' => $status]);

            // Notify the company's dashboard in real time when it is suspended (spec §8).
            if ($status === 'suspended') {
                $sub = \Modules\Admin\Models\CompanySubscription::withoutGlobalScopes()->where('company_id', $company->id)->first();
                if ($sub) {
                    app(\Modules\Admin\Services\RealtimeBroadcaster::class)->subscriptionSuspended($sub);
                }
            }

            return $this->ok($this->present($company));
        });
    }

    public function upgrade(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['plan' => 'required|in:Basic,Professional,Enterprise']);
            $company = AsabCompany::findOrFail($id);
            $company->update([
                'plan' => $data['plan'],
                'max_branches' => $this->planLimit($data['plan'], 'branches'),
                'max_users' => $this->planLimit($data['plan'], 'users'),
            ]);

            return $this->ok($this->present($company->fresh()));
        });
    }

    public function modules(string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok(['modules' => AsabCompany::findOrFail($id)->modules ?? []]));
    }

    public function updateModules(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['modules' => 'required|array']);
            $company = AsabCompany::findOrFail($id);
            $company->update(['modules' => $data['modules']]);

            return $this->ok(['modules' => $company->modules]);
        });
    }

    public function usage(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $company = AsabCompany::findOrFail($id);
            $branchCount = $this->companyBranchCount($company->id);
            $userCount = \Modules\Admin\Models\AsabUser::where('company_id', $company->id)->count();

            return $this->ok([
                'branches' => ['used' => $branchCount, 'max' => $company->max_branches],
                'users' => ['used' => $userCount, 'max' => $company->max_users],
            ]);
        });
    }

    /**
     * POST /admin/companies/{id}/admin/reset-password (contract batch 1, A6).
     * Resets the company-admin's password and emails it — never returns plaintext.
     */
    public function resetAdminPassword(Request $request, CredentialSyncService $credentials, CredentialMailer $mailer, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $credentials, $mailer, $id) {
            $data = $request->validate(['notify' => 'sometimes|boolean']);
            $company = AsabCompany::findOrFail($id);
            $admin = $this->resolveCompanyAdmin($company);
            if (! $admin) {
                throw new AsabException('NOT_FOUND', 'Company has no admin account', 'لا يوجد حساب مدير لهذه الشركة', 404);
            }

            $temporaryPassword = Str::password(12);
            $resetAt = now();

            DB::transaction(function () use ($admin, $temporaryPassword, $credentials) {
                $admin->forceFill(['password' => $temporaryPassword])->save();
                $admin->tokens()->delete();
                $credentials->pushToMobile($admin, forceReset: true);
            });

            $emailSent = ($data['notify'] ?? true)
                ? $mailer->send($admin, new UserPasswordResetNotification($temporaryPassword))
                : false;

            return $this->ok([
                'ok' => true,
                'emailedTo' => $admin->email,
                'emailSent' => $emailSent,
                'resetAt' => $resetAt->toIso8601String(),
            ]);
        });
    }

    /**
     * POST /admin/companies/{id}/impersonate (contract batch 1, A7) — security-sensitive.
     * Mints a short-lived (30-min), no-refresh Sanctum token as the company-admin
     * and writes an explicit audit entry. Admin-only (enforced by route middleware).
     */
    public function impersonate(Request $request, AuditService $audit, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $audit, $id) {
            $company = AsabCompany::findOrFail($id);
            $admin = $this->resolveCompanyAdmin($company);
            if (! $admin) {
                throw new AsabException('NOT_FOUND', 'Company has no admin account', 'لا يوجد حساب مدير لهذه الشركة', 404);
            }

            $expiresAt = now()->addMinutes(30);
            $token = $admin->createToken('impersonation', ['*'], $expiresAt)->plainTextToken;

            $audit->record(
                'impersonate',
                $request->user(),
                'company',
                $company->id,
                'Admin impersonated company-admin '.$admin->email,
                [],
                ['targetUserId' => $admin->id],
                $request,
            );

            return $this->ok([
                'token' => $token,
                'expiresAt' => $expiresAt->toIso8601String(),
                'userId' => $admin->id,
            ]);
        });
    }

    /**
     * POST /admin/companies/{id}/send-reminder (contract batch 1, A8).
     * Dispatches a renewal reminder to the company-admin over the chosen channels.
     */
    public function sendReminder(Request $request, NotificationService $notifications, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $notifications, $id) {
            $data = $request->validate([
                'channels' => 'required|array|min:1',
                'channels.*' => 'in:email,inApp',
                'message' => 'sometimes|nullable|string|max:500',
            ]);

            $company = AsabCompany::findOrFail($id);
            $channels = array_values(array_unique($data['channels']));
            $message = $data['message'] ?? 'تذكير بتجديد اشتراك '.$company->name;
            $sentAt = now();

            if (in_array('inApp', $channels, true)) {
                $notifications->pushToRole($company->id, 'company-admin', 'subscription.reminder', 'تذكير بالتجديد', $message);
            }
            if (in_array('email', $channels, true)) {
                $admin = $this->resolveCompanyAdmin($company);
                if ($admin) {
                    try {
                        $admin->notify(new SubscriptionReminderNotification($company->name, $message));
                    } catch (\Throwable $e) {
                        Log::warning('Reminder email failed: '.$e->getMessage());
                    }
                }
            }

            return $this->ok(['ok' => true, 'sentAt' => $sentAt->toIso8601String(), 'channels' => $channels]);
        });
    }

    /** Resolve a company's admin user — by role first, then admin_email fallback. */
    private function resolveCompanyAdmin(AsabCompany $company): ?AsabUser
    {
        $user = AsabUser::where('company_id', $company->id)
            ->whereHas('roleAssignments', fn ($r) => $r->where('role_key', 'company-admin'))
            ->first();

        if (! $user && $company->admin_email) {
            $user = AsabUser::where('email', $company->admin_email)->first();
        }

        return $user;
    }

    /**
     * Aggregate brand/restaurant/user/branch counts for a set of companies in one
     * grouped query each, so present() never queries per row (contract batch 1, Part B).
     *
     * @param  array<int, string>  $companyIds
     * @return array{brands:array, restaurants:array, users:array, branches:array}
     */
    private function buildCounts(array $companyIds): array
    {
        if (empty($companyIds)) {
            return ['brands' => [], 'restaurants' => [], 'users' => [], 'branches' => []];
        }

        $countBy = fn ($query, string $column) => $query->whereIn($column, $companyIds)
            ->selectRaw("{$column} as cid, count(*) as c")->groupBy($column)->pluck('c', 'cid')->all();

        $branches = [];
        try {
            $branches = $countBy(Branch::query(), 'asab_company_id');
        } catch (\Throwable $e) {
            $branches = [];
        }

        return [
            'brands' => $countBy(AsabBrand::query(), 'company_id'),
            'restaurants' => $countBy(AsabRestaurant::query(), 'company_id'),
            'users' => $countBy(AsabUser::query(), 'company_id'),
            'branches' => $branches,
        ];
    }

    private function companyBranchCount(string $companyId): int
    {
        try {
            return \Modules\Branch\Models\Branch::where('asab_company_id', $companyId)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function planLimit(string $plan, string $kind): int
    {
        $map = [
            'Basic' => ['branches' => 5, 'users' => 15],
            'Professional' => ['branches' => 20, 'users' => 60],
            'Enterprise' => ['branches' => 100, 'users' => 300],
        ];

        return $map[$plan][$kind] ?? 5;
    }

    /**
     * @param  array{brands:array, restaurants:array, users:array, branches:array}|null  $counts
     *                                                                                            Precomputed count maps (from buildCounts) — when omitted, computed for this
     *                                                                                            single company so single-resource endpoints stay correct without N+1 on lists.
     */
    private function present(AsabCompany $c, ?array $counts = null): array
    {
        $counts ??= $this->buildCounts([$c->id]);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'logo' => $c->logo,
            'contactName' => $c->contact_name,
            'contactEmail' => $c->contact_email,
            'contactPhone' => $c->contact_phone,
            'city' => $c->city,
            'plan' => $c->plan,
            'status' => $c->status,
            'brands' => (int) ($counts['brands'][$c->id] ?? 0),
            'restaurants' => (int) ($counts['restaurants'][$c->id] ?? 0),
            'users' => (int) ($counts['users'][$c->id] ?? 0),
            'usedBranches' => (int) ($counts['branches'][$c->id] ?? 0),
            'maxBranches' => $c->max_branches,
            'maxUsers' => $c->max_users,
            'monthlyRevenue' => $c->monthly_revenue,
            'daysLeft' => $c->next_billing ? (int) now()->diffInDays($c->next_billing, false) : null,
            'startDate' => optional($c->start_date)->toIso8601String(),
            'nextBilling' => optional($c->next_billing)->toIso8601String(),
            'modules' => $c->modules ?? [],
            'adminEmail' => $c->admin_email,
            'createdAt' => optional($c->created_at)->toIso8601String(),
        ];
    }
}
