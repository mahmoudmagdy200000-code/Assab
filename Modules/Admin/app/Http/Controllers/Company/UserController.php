<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabRole;
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

            $cu->update(array_filter([
                'role_key' => $data['roleKey'] ?? null, 'brand_id' => $data['brandId'] ?? $cu->brand_id, 'branch_id' => $data['branchId'] ?? $cu->branch_id,
            ], fn ($v) => $v !== null));
            if (isset($data['name']) || isset($data['phone'])) {
                $cu->user?->update(array_filter(['name' => $data['name'] ?? null, 'phone' => $data['phone'] ?? null], fn ($v) => $v !== null));
            }
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
            if ($cu->status === 'active' && $cu->role_key === 'company-admin' && $this->isLastAdmin($cu)) {
                throw new AsabException('LAST_ACTIVE_ADMIN', 'Cannot disable the last admin', 'لا يمكن إيقاف آخر أدمن نشط', 409);
            }
            $new = $cu->status === 'active' ? 'inactive' : 'active';
            $cu->update(['status' => $new]);
            if ($new === 'inactive') {
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
            $cu->delete();

            return $this->noContent();
        });
    }

    public function resendInvite(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $cu = CompanyUser::where('company_id', $request->user()->company_id)->findOrFail($id);
            if ($cu->status !== 'invited') {
                throw new AsabException('NOT_INVITED', 'User is not in invited state', 'المستخدم ليس بحالة دعوة', 409);
            }

            return $this->ok(['resent' => true], 202);
        });
    }

    private function isLastAdmin(CompanyUser $cu): bool
    {
        return CompanyUser::where('company_id', $cu->company_id)->where('role_key', 'company-admin')
            ->where('status', 'active')->where('id', '!=', $cu->id)->doesntExist();
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
        return [
            'id' => $inv->id, 'email' => $inv->email, 'name' => $inv->name, 'roleKey' => $inv->role_key,
            'brandId' => $inv->brand_id, 'branchId' => $inv->branch_id, 'status' => $inv->status,
            'token' => $inv->token, 'expiresAt' => optional($inv->expires_at)->toIso8601String(),
        ];
    }
}
