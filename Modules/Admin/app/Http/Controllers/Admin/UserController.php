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
        'brand-owner' => 'مالك العلامة التجارية',
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
            $rules = [
                'name' => 'required|string|max:200',
                'email' => 'required|email|max:191|unique:asab_users,email',
                'phone' => 'nullable|string|max:32',
                'role' => 'required|in:admin,head,accountant,branch,procurement,supplier,brand-owner',
                'companyId' => 'nullable|string',
                'brands' => 'nullable|array',
                'restaurants' => 'nullable|array',
                'branches' => 'nullable|array',
                'modules' => 'nullable|array',
                'scope' => 'nullable|in:all,brand,restaurant,branch',
                'reportsTo' => 'nullable|string',
                'status' => 'nullable|in:active,inactive',
                'sendLoginEmail' => 'sometimes|boolean',
            ];
            // Per-role assignment rules (client meeting): a branch manager runs
            // exactly ONE branch; an accountant is assigned at BRAND level.
            $role = $request->input('role');
            if ($role === 'branch') {
                $rules['branches'] = 'required|array|size:1';
            } elseif ($role === 'accountant') {
                $rules['brands'] = 'required|array|min:1';
            }
            $data = $request->validate($rules);

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

                AsabUserRole::create(array_merge([
                    'user_id' => $user->id,
                    'role_key' => $data['role'],
                    'module_keys' => $data['modules'] ?? [],
                ], $this->assignmentAttributes($data['role'], $data)));

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
            $user = AsabUser::with('roleAssignments')->findOrFail($id);
            $assignment = $user->roleAssignments->first();
            $roleKey = $assignment->role_key ?? null;

            // Role is immutable here; scope arrays are editable under the same
            // per-role rules as store() (branch = one branch, accountant = brands).
            $rules = [
                'name' => 'sometimes|string|max:200',
                'phone' => 'sometimes|string|max:32',
                'status' => 'sometimes|in:active,inactive',
                'reportsTo' => 'sometimes|string',
                'brands' => 'sometimes|array',
                'restaurants' => 'sometimes|array',
                'branches' => 'sometimes|array',
                'modules' => 'sometimes|array',
                'scope' => 'sometimes|in:all,brand,restaurant,branch',
            ];
            if ($roleKey === 'branch') {
                $rules['branches'] = 'sometimes|array|size:1';
            } elseif ($roleKey === 'accountant') {
                $rules['brands'] = 'sometimes|array|min:1';
            }
            $data = $request->validate($rules);

            DB::transaction(function () use ($user, $assignment, $roleKey, $data) {
                $user->update(array_filter([
                    'name' => $data['name'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'status' => $data['status'] ?? null,
                    'reports_to_id' => $data['reportsTo'] ?? null,
                ], fn ($v) => $v !== null));

                if ($assignment && ($updates = $this->assignmentUpdates($roleKey, $data)) !== []) {
                    $assignment->update($updates);
                }
            });

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
                // CSV carries no brand/branch columns; scoped roles fail closed
                // (empty ids -> resolver returns nothing) until an admin assigns,
                // instead of granting company-wide 'all' visibility.
                $scope = match ($role) {
                    'accountant' => 'brand',
                    'branch' => 'branch',
                    default => 'all',
                };
                AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => $scope]);
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

    /**
     * Role-forced assignment scope (client meeting): a branch manager runs
     * exactly ONE branch (scope=branch); an accountant is assigned at BRAND
     * level (scope=brand, branch/restaurant ids ignored). Other roles keep
     * the payload as-is (admin/head default scope 'all').
     */
    private function assignmentAttributes(string $role, array $data): array
    {
        return match ($role) {
            'branch' => [
                'scope' => 'branch',
                'brand_ids' => [],
                'restaurant_ids' => [],
                'branch_ids' => $data['branches'],
            ],
            'accountant' => [
                'scope' => 'brand',
                'brand_ids' => $data['brands'],
                'restaurant_ids' => [],
                'branch_ids' => [],
            ],
            default => [
                'scope' => $data['scope'] ?? 'all',
                'brand_ids' => $data['brands'] ?? [],
                'restaurant_ids' => $data['restaurants'] ?? [],
                'branch_ids' => $data['branches'] ?? [],
            ],
        };
    }

    /** Same per-role rules for partial (PATCH) assignment edits. */
    private function assignmentUpdates(?string $roleKey, array $data): array
    {
        $updates = [];
        if (array_key_exists('modules', $data)) {
            $updates['module_keys'] = $data['modules'];
        }

        // The tenant resolver ORs every non-empty id array regardless of the
        // scope string, so the forced arms must ZERO the foreign arrays too.
        if ($roleKey === 'branch') {
            if (array_key_exists('branches', $data)) {
                $updates['branch_ids'] = $data['branches'];
                $updates['brand_ids'] = [];
                $updates['restaurant_ids'] = [];
                $updates['scope'] = 'branch';
            }
        } elseif ($roleKey === 'accountant') {
            // Accountants are brand-level: branch/restaurant arrays are ignored.
            if (array_key_exists('brands', $data)) {
                $updates['brand_ids'] = $data['brands'];
                $updates['restaurant_ids'] = [];
                $updates['branch_ids'] = [];
                $updates['scope'] = 'brand';
            }
        } else {
            foreach (['brands' => 'brand_ids', 'restaurants' => 'restaurant_ids', 'branches' => 'branch_ids'] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $updates[$column] = $data[$key];
                }
            }
            if (array_key_exists('scope', $data)) {
                $updates['scope'] = $data['scope'];
            }
        }

        return $updates;
    }

    private function defaultPage(string $role): string
    {
        return [
            'admin' => 'admin-overview', 'head' => 'head-dashboard', 'accountant' => 'acc-dashboard',
            'branch' => 'branch-overview', 'procurement' => 'proc-overview', 'supplier' => 'sup-overview',
            'brand-owner' => 'brand-owner-dashboard',
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
