<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\UserPasswordResetNotification;

class UserController extends AsabController
{
    private const ROLE_LABELS = [
        'accountant' => 'محاسب', 'head' => 'رئيس حسابات', 'branch' => 'مدير فرع',
        'procurement' => 'مدير مشتريات', 'supplier' => 'مورد', 'admin' => 'أدمن',
    ];

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = AsabUser::query()->with('roleAssignments');

            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($companyId = $request->query('companyId')) {
                $q->where('company_id', $companyId);
            }
            // `role` is the contract alias for `roleFilter` (Admin dashboard batch 1, Part C).
            if ($role = $request->query('roleFilter', $request->query('role'))) {
                $q->whereHas('roleAssignments', fn ($r) => $r->where('role_key', $role));
            }
            if ($brand = $request->query('brand')) {
                $q->whereHas('roleAssignments', fn ($r) => $r->whereJsonContains('brand_ids', $brand));
            }

            $p = $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'email' => 'required|email|max:191|unique:asab_users,email',
                'phone' => 'nullable|string|max:32',
                'role' => 'required|in:admin,head,accountant,branch,procurement,supplier',
                'companyId' => 'nullable|string',
                'brands' => 'nullable|array',
                'restaurants' => 'nullable|array',
                'branches' => 'nullable|array',
                'modules' => 'nullable|array',
                'scope' => 'nullable|in:all,brand,restaurant,branch',
                'reportsTo' => 'nullable|string',
                'status' => 'nullable|in:active,inactive',
                'sendLoginEmail' => 'sometimes|boolean',
            ]);

            // One temporary password: used for the account and (optionally) emailed
            // so the user can sign in (Admin dashboard batch 1, Part B).
            $temporaryPassword = Str::password(12);

            $user = DB::transaction(function () use ($data, $temporaryPassword) {
                $user = AsabUser::create([
                    'company_id' => $data['companyId'] ?? null,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => $temporaryPassword, // hashed by the model's 'hashed' cast
                    'avatar' => mb_substr($data['name'], 0, 1),
                    'status' => $data['status'] ?? 'active',
                    'reports_to_id' => $data['reportsTo'] ?? null,
                    'default_page' => $this->defaultPage($data['role']),
                ]);

                AsabUserRole::create([
                    'user_id' => $user->id,
                    'role_key' => $data['role'],
                    'scope' => $data['scope'] ?? 'all',
                    'brand_ids' => $data['brands'] ?? [],
                    'restaurant_ids' => $data['restaurants'] ?? [],
                    'branch_ids' => $data['branches'] ?? [],
                    'module_keys' => $data['modules'] ?? [],
                ]);

                return $user;
            });

            if ($data['sendLoginEmail'] ?? false) {
                $this->emailTemporaryPassword($user, $temporaryPassword);
            }

            return $this->created($this->present($user->load('roleAssignments')));
        });
    }

    /**
     * POST /admin/users/{id}/reset-password (Admin dashboard contract batch 1, A3).
     * Generates a temporary password, revokes existing sessions, and emails it —
     * the password is never returned in the response or logged (zero-trust).
     */
    public function resetPassword(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['sendEmail' => 'sometimes|boolean']);
            $user = AsabUser::findOrFail($id);
            $temporaryPassword = Str::password(12);

            DB::transaction(function () use ($user, $temporaryPassword) {
                $user->forceFill(['password' => $temporaryPassword])->save();
                $user->tokens()->delete();
            });

            $emailSent = ($data['sendEmail'] ?? true)
                ? $this->emailTemporaryPassword($user, $temporaryPassword)
                : false;

            return $this->ok(['ok' => true, 'emailSent' => $emailSent]);
        });
    }

    /** Best-effort temp-password email; a mail outage must not fail the request. */
    private function emailTemporaryPassword(AsabUser $user, string $temporaryPassword): bool
    {
        try {
            $user->notify(new UserPasswordResetNotification($temporaryPassword));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Temporary-password email failed: '.$e->getMessage());

            return false;
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $user = AsabUser::findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200',
                'phone' => 'sometimes|string|max:32',
                'status' => 'sometimes|in:active,inactive',
                'reportsTo' => 'sometimes|string',
            ]);
            DB::transaction(fn () => $user->update(array_filter([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? null,
                'reports_to_id' => $data['reportsTo'] ?? null,
            ], fn ($v) => $v !== null)));

            return $this->ok($this->present($user->fresh('roleAssignments')));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $user = AsabUser::findOrFail($id);
            DB::transaction(function () use ($user) {
                $user->tokens()->delete();
                $user->delete();
            });

            return $this->noContent();
        });
    }

    public function import(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['file' => 'required|file']);
            $file = $request->file('file');
            $rows = array_map('str_getcsv', file($file->getRealPath()));
            $header = array_map('trim', array_shift($rows) ?? []);

            $imported = 0;
            $skipped = 0;
            $errors = [];
            foreach ($rows as $i => $row) {
                $data = @array_combine($header, $row);
                $email = $data['email'] ?? $data['البريد'] ?? null;
                $name = $data['name'] ?? $data['الاسم'] ?? null;
                if (! $email || ! $name) {
                    $errors[] = ['row' => $i + 2, 'field' => 'email/name', 'message' => 'missing required fields'];

                    continue;
                }
                if (AsabUser::where('email', $email)->exists()) {
                    $skipped++;

                    continue;
                }
                $user = AsabUser::create([
                    'company_id' => $request->user()->company_id,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $data['phone'] ?? null,
                    'password' => str()->random(16),
                    'avatar' => mb_substr($name, 0, 1),
                    'status' => 'active',
                ]);
                $role = $data['role'] ?? 'accountant';
                AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all']);
                $imported++;
            }

            return $this->ok(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
        });
    }

    public function activate(string $id): JsonResponse
    {
        return $this->setStatus($id, 'active');
    }

    public function deactivate(string $id): JsonResponse
    {
        return $this->setStatus($id, 'inactive');
    }

    private function setStatus(string $id, string $status): JsonResponse
    {
        return $this->run(function () use ($id, $status) {
            $user = AsabUser::findOrFail($id);
            $user->update(['status' => $status]);

            return $this->ok($this->present($user->load('roleAssignments')));
        });
    }

    private function defaultPage(string $role): string
    {
        return [
            'admin' => 'admin-overview', 'head' => 'head-dashboard', 'accountant' => 'acc-dashboard',
            'branch' => 'branch-overview', 'procurement' => 'proc-overview', 'supplier' => 'sup-overview',
        ][$role] ?? 'admin-overview';
    }

    private function present(AsabUser $u): array
    {
        $assignment = $u->roleAssignments->first();
        $roleKey = $assignment->role_key ?? null;

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $roleKey ? (self::ROLE_LABELS[$roleKey] ?? $roleKey) : null,
            'roleKey' => $roleKey,
            'brands' => $assignment->brand_ids ?? [],
            'restaurants' => $assignment->restaurant_ids ?? [],
            'branches' => $assignment->branch_ids ?? [],
            'modules' => $assignment->module_keys ?? [],
            'scope' => $assignment->scope ?? 'all',
            'reportsTo' => $u->reports_to_id,
            'status' => $u->status,
            'lastLoginAt' => optional($u->last_login_at)->toIso8601String(),
            'createdAt' => optional($u->created_at)->toIso8601String(),
        ];
    }
}
