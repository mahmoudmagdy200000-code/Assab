<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Mail\CompanyInvitationMail;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRole;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyInvitation;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\PlanLimitService;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Company user management + invitations (COMPANY_DASHBOARD_API_SPEC.md §5.1.3 / §4.2).
 */
class UserController extends AsabController
{
    private const ROLES = ['company-admin', 'head', 'accountant', 'branch', 'procurement'];

    private const ROLE_LABELS = [
        'company-admin' => 'مدير الشركة', 'head' => 'رئيس الحسابات', 'accountant' => 'محاسب',
        'branch' => 'مدير الفرع', 'procurement' => 'مدير المشتريات',
    ];

    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly NotificationService $notifications,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $q = CompanyUser::where('company_id', $companyId)->with('user');
            if ($role = $request->query('roleKey')) {
                $q->where('role_key', $role);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($brand = $request->query('brandId')) {
                $q->where('brand_id', $brand);
            }
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('role_key', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")));
            }
            $page = $q->orderByDesc('created_at')->paginate(min((int) $request->query('pageSize', 20), 100));

            $roles = AsabRole::pluck('name_ar', 'key');
            $items = collect($page->items())->map(fn (CompanyUser $cu) => $this->present($cu, $roles))->all();

            $activeCount = CompanyUser::where('company_id', $companyId)->where('status', 'active')->count();
            $maxUsers = $this->limits->planFor($companyId)?->max_users;

            return $this->paginated($page, $items, ['activeCount' => $activeCount, 'maxUsers' => $maxUsers]);
        });
    }

    public function invite(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $data = $request->validate([
                'email' => 'required|email', 'name' => 'sometimes|string|max:200',
                'roleKey' => 'required|in:'.implode(',', self::ROLES),
                'brandId' => 'sometimes|nullable|string', 'branchId' => 'sometimes|nullable|string',
            ]);

            if ($data['roleKey'] === 'branch' && empty($data['branchId'])) {
                throw new AsabException('INVALID_ROLE_SCOPE', 'branchId required for branch role', 'يلزم تحديد الفرع لمدير الفرع', 422);
            }
            if ($data['roleKey'] === 'accountant' && empty($data['brandId'])) {
                throw new AsabException('INVALID_ROLE_SCOPE', 'brandId required for accountant role', 'يلزم تحديد العلامة التجارية للمحاسب', 422);
            }
            $this->assertBrandInCompany($data['brandId'] ?? null, $companyId);
            if (CompanyUser::where('company_id', $companyId)->whereHas('user', fn ($u) => $u->where('email', $data['email']))->exists()) {
                throw new AsabException('USER_ALREADY_MEMBER', 'Email already a member', 'البريد عضو بالفعل في الشركة', 409);
            }
            $this->limits->assertCanAdd($companyId, 'users');

            $inv = CompanyInvitation::create([
                'company_id' => $companyId, 'email' => $data['email'], 'name' => $data['name'] ?? null,
                'role_key' => $data['roleKey'], 'brand_id' => $data['brandId'] ?? null, 'branch_id' => $data['branchId'] ?? null,
                'token' => Str::random(64), 'status' => 'pending', 'invited_by_id' => $request->user()->id,
                'expires_at' => now()->addDays(7), 'created_at' => now(),
            ]);
            $this->sendInvitationMail($inv);
            $this->notifications->push($request->user()->id, 'user.invited', 'تم إرسال دعوة', $data['email']);
            $this->rt->userInvited($companyId, $inv);

            return $this->created($this->presentInvite($inv));
        });
    }

    public function invitations(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = CompanyInvitation::where('company_id', $request->user()->company_id);
            if ($status = $request->query('status')) {
                $q->whereIn('status', explode(',', $status));
            }

            return $this->listResponse($q->orderByDesc('created_at')->get()->map([$this, 'presentInvite'])->all());
        });
    }

    public function revokeInvitation(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $inv = CompanyInvitation::where('company_id', $request->user()->company_id)->findOrFail($id);
            $inv->update(['status' => 'revoked']);

            return $this->noContent();
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $cu = CompanyUser::where('company_id', $request->user()->company_id)->with('user')->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200', 'roleKey' => 'sometimes|in:head,accountant,branch,procurement,company-admin',
                'brandId' => 'sometimes|nullable|string', 'branchId' => 'sometimes|nullable|string', 'phone' => 'sometimes|string|max:32',
            ]);

            if (isset($data['roleKey']) && $data['roleKey'] === 'branch' && empty($data['branchId']) && empty($cu->branch_id)) {
                throw new AsabException('INVALID_ROLE_SCOPE', 'branchId required for branch role', 'يلزم تحديد الفرع', 422);
            }
            if (isset($data['roleKey']) && $cu->role_key === 'company-admin' && $data['roleKey'] !== 'company-admin' && $this->isLastAdmin($cu)) {
                throw new AsabException('LAST_ADMIN_CANNOT_DEMOTE', 'Cannot demote the last admin', 'لا يمكن تنزيل آخر أدمن', 409);
            }
            $this->assertBrandInCompany($data['brandId'] ?? null, $request->user()->company_id);

            $effectiveRole = $data['roleKey'] ?? $cu->role_key;

            DB::transaction(function () use ($cu, $data, $effectiveRole) {
                $cu->update(array_filter([
                    'role_key' => $data['roleKey'] ?? null, 'brand_id' => $data['brandId'] ?? $cu->brand_id, 'branch_id' => $data['branchId'] ?? $cu->branch_id,
                ], fn ($v) => $v !== null));
                if (isset($data['name']) || isset($data['phone'])) {
                    $cu->user?->update(array_filter(['name' => $data['name'] ?? null, 'phone' => $data['phone'] ?? null], fn ($v) => $v !== null));
                }
                // A brand reassignment must reach the effective data scope:
                // ResolveTenant reads asab_user_roles, not company_users.
                // Stale branch/restaurant ids are cleared too — the resolver
                // ORs every non-empty id array regardless of scope.
                if (! empty($data['brandId']) && $effectiveRole === 'accountant') {
                    // updateOrCreate: a member just role-changed to accountant may
                    // have no accountant row yet — create it so the scope lands.
                    AsabUserRole::updateOrCreate(
                        ['user_id' => $cu->user_id, 'role_key' => 'accountant'],
                        [
                            'scope' => 'brand',
                            'brand_ids' => [$data['brandId']],
                            'restaurant_ids' => [],
                            'branch_ids' => [],
                        ]
                    );
                }
            });
            if (isset($data['roleKey'])) {
                $this->rt->userLifecycle($request->user()->company_id, 'role_changed', $cu->fresh());
            }

            return $this->ok($this->present($cu->fresh('user'), AsabRole::pluck('name_ar', 'key')));
        });
    }

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $cu = CompanyUser::where('company_id', $request->user()->company_id)->findOrFail($id);
            if ($cu->user_id === $request->user()->id) {
                throw new AsabException('CANNOT_DISABLE_SELF', 'Cannot disable yourself', 'لا يمكنك إيقاف حسابك', 409);
            }
            if ($cu->status === 'archived') {
                throw new AsabException('CANNOT_TOGGLE_ARCHIVED', 'Cannot toggle status of archived member', 'لا يمكن تعديل حالة عضو مؤرشف بواسطة التبديل السريع', 409);
            }
            if ($cu->status === 'active' && $cu->role_key === 'company-admin' && $this->isLastAdmin($cu)) {
                throw new AsabException('LAST_ACTIVE_ADMIN', 'Cannot disable the last admin', 'لا يمكن إيقاف آخر أدمن نشط', 409);
            }
            $new = $cu->status === 'active' ? 'inactive' : 'active';
            $cu->update(['status' => $new]);
            if ($new === 'inactive') {
                $this->revokeCompanyUserSessions($cu->user_id, $cu->company_id);
                $this->rt->userLifecycle($request->user()->company_id, 'suspended', $cu->fresh());
            }

            return $this->ok(['id' => $cu->id, 'status' => $new]);
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $cu = CompanyUser::where('company_id', $request->user()->company_id)->findOrFail($id);
            if ($cu->role_key === 'company-admin' && $this->isLastAdmin($cu)) {
                throw new AsabException('LAST_ACTIVE_ADMIN', 'Cannot remove the last admin', 'لا يمكن حذف آخر أدمن', 409);
            }
            $cu->update(['status' => 'archived']);
            $this->revokeCompanyUserSessions($cu->user_id, $cu->company_id);
            $this->rt->userLifecycle($request->user()->company_id, 'archived', $cu->fresh());

            return $this->noContent();
        });
    }

    /**
     * S1-12 atomic session and token revocation following the strict lock hierarchy:
     * Lock 1: User -> Lock 2: Sessions -> Lock 3: Tokens
     * Scoped to company_id, protecting independent accounts/companies.
     */
    private function revokeCompanyUserSessions(string $userId, string $companyId): void
    {
        DB::transaction(function () use ($userId, $companyId) {
            // Lock 1: User
            $user = AsabUser::where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                return;
            }

            // Lock 2: Sessions for this company
            $sessions = AdminSession::where('user_id', $userId)
                ->where('company_id', $companyId)
                ->whereNull('revoked_at')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $tokenIdsToDelete = [];

            foreach ($sessions as $session) {
                $session->update(['revoked_at' => now()]);
                if ($session->current_access_token_id) {
                    $tokenIdsToDelete[] = $session->current_access_token_id;
                }
                if ($session->current_refresh_token_id) {
                    $tokenIdsToDelete[] = $session->current_refresh_token_id;
                }
                if ($session->legacy_access_token_id) {
                    $tokenIdsToDelete[] = $session->legacy_access_token_id;
                }
            }

            $sessionIds = $sessions->pluck('id')->all();
            if (! empty($sessionIds)) {
                $familyTokenIds = PersonalAccessToken::whereIn('session_id', $sessionIds)
                    ->pluck('id')
                    ->all();
                $tokenIdsToDelete = array_merge($tokenIdsToDelete, $familyTokenIds);
            }

            // Lock 3: Delete family-linked tokens in deterministic ascending order
            if (! empty($tokenIdsToDelete)) {
                $tokenIdsToDelete = array_values(array_unique($tokenIdsToDelete));
                sort($tokenIdsToDelete);
                PersonalAccessToken::whereIn('id', $tokenIdsToDelete)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->each->delete();
            }

            // Pre-Implementation Correction 2:
            // Delete Legacy tokens (session_id = null) for this user to prevent replay.
            // Uses both morph relation and direct tokenable_id to support any model morph mapping.
            $user->tokens()->whereNull('session_id')->lockForUpdate()->get()->each->delete();

            PersonalAccessToken::where('tokenable_id', $userId)
                ->whereNull('session_id')
                ->lockForUpdate()
                ->get()
                ->each->delete();
        });
    }

    /** POST /company/invitations/{id}/resend — regenerate token + expiry, re-email. */
    public function resend(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $inv = CompanyInvitation::where('company_id', $request->user()->company_id)->findOrFail($id);
            if ($inv->status !== 'pending') {
                throw new AsabException('INVALID_INVITATION', 'Only pending invitations can be resent', 'لا يمكن إعادة إرسال دعوة غير معلّقة', 409);
            }
            $inv->update(['token' => Str::random(64), 'expires_at' => now()->addDays(7)]);
            $this->sendInvitationMail($inv);

            return $this->ok(['resent' => true, 'expiresAt' => optional($inv->expires_at)->toIso8601String()], 202);
        });
    }

    /** POST /company/me/users/{id}/resend-invite — resolves the member's pending invitation and delegates. */
    public function resendInvite(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $cu = CompanyUser::where('company_id', $request->user()->company_id)->with('user')->findOrFail($id);
            if ($cu->status !== 'invited') {
                throw new AsabException('NOT_INVITED', 'User is not in invited state', 'المستخدم ليس بحالة دعوة', 409);
            }
            $inv = CompanyInvitation::where('company_id', $cu->company_id)
                ->where('email', $cu->user?->email)->where('status', 'pending')->latest('created_at')->first();
            if (! $inv) {
                throw new AsabException('INVALID_INVITATION', 'No pending invitation for this member', 'لا توجد دعوة معلّقة لهذا العضو', 409);
            }
            $inv->update(['token' => Str::random(64), 'expires_at' => now()->addDays(7)]);
            $this->sendInvitationMail($inv);

            return $this->ok(['resent' => true], 202);
        });
    }

    /** CMP-3 — email the invitee the accept link (queued mailable). */
    private function sendInvitationMail(CompanyInvitation $inv): void
    {
        $companyName = AsabCompany::find($inv->company_id)?->name ?? 'ASAB';
        $roleLabel = self::ROLE_LABELS[$inv->role_key] ?? $inv->role_key;
        $acceptUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/accept-invitation?token='.$inv->token;
        Mail::to($inv->email)->send(new CompanyInvitationMail($companyName, $roleLabel, $acceptUrl));
    }

    private function isLastAdmin(CompanyUser $cu): bool
    {
        return CompanyUser::where('company_id', $cu->company_id)->where('role_key', 'company-admin')
            ->where('status', 'active')->where('id', '!=', $cu->id)->doesntExist();
    }

    /** brandId feeds asab_user_roles.brand_ids (effective tenant scope) — never trust it raw. */
    private function assertBrandInCompany(?string $brandId, string $companyId): void
    {
        if ($brandId !== null && $brandId !== ''
            && AsabBrand::where('id', $brandId)->where('company_id', $companyId)->doesntExist()) {
            throw new AsabException('INVALID_ROLE_SCOPE', 'brandId does not belong to this company', 'العلامة التجارية لا تتبع هذه الشركة', 422);
        }
    }

    private function present(CompanyUser $cu, $roles): array
    {
        $u = $cu->user;

        return [
            'id' => $cu->id, 'userId' => $cu->user_id, 'name' => $u?->name, 'email' => $u?->email,
            'avatar' => $u?->avatar ?? ($u ? mb_substr($u->name, 0, 1) : null),
            'role' => ['key' => $cu->role_key, 'nameAr' => $roles[$cu->role_key] ?? $cu->role_key, 'nameEn' => $cu->role_key],
            'brand' => $cu->brand_id ? ['id' => $cu->brand_id] : null, 'branch' => $cu->branch_id ? ['id' => $cu->branch_id] : null,
            'branchLabel' => $cu->branch_id ? '—' : '—', 'status' => $cu->status,
            'lastSeenAt' => optional($cu->last_seen_at)->toIso8601String(),
            'lastSeenLabel' => $cu->last_seen_at ? $cu->last_seen_at->diffForHumans() : '—',
        ];
    }

    public function presentInvite(CompanyInvitation $inv): array
    {
        // token is intentionally NOT exposed — it is delivered only by email (CMP-3).
        return [
            'id' => $inv->id, 'email' => $inv->email, 'name' => $inv->name, 'roleKey' => $inv->role_key,
            'brandId' => $inv->brand_id, 'branchId' => $inv->branch_id, 'status' => $inv->status,
            'expiresAt' => optional($inv->expires_at)->toIso8601String(),
        ];
    }
}
