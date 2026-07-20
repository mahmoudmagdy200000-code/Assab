<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\UserPasswordResetNotification;
use Modules\Admin\Services\CredentialMailer;
use Modules\Admin\Services\CredentialSyncService;
use Modules\Admin\Services\IdentityMapService;
use Modules\Admin\Services\Provisioning\LegacyProvisionerRegistry;

class UserController extends AsabController
{
    private const ROLE_LABELS = [
        'accountant' => 'محاسب', 'head' => 'رئيس حسابات', 'branch' => 'مدير فرع',
        'procurement' => 'مدير مشتريات', 'supplier' => 'مورد', 'admin' => 'أدمن',
        'brand-owner' => 'مالك العلامة التجارية',
    ];

    /**
     * Why an accountant's restaurants/scope are refused here rather than dropped.
     * `prohibited` still permits an absent or EMPTY value, so a client sending
     * `restaurants: []` is unaffected — only a non-empty assignment 422s.
     */
    private const ACCOUNTANT_SCOPE_MESSAGES = [
        'restaurants.prohibited' => 'An accountant is assigned at brand level. Assign restaurants via PATCH /admin/accountants/{id}/assignments.',
        'scope.prohibited' => "An accountant's scope is always 'brand'.",
    ];

    public function __construct(
        private readonly CredentialMailer $mailer,
        private readonly CredentialSyncService $credentials,
        private readonly LegacyProvisionerRegistry $provisioners,
        private readonly IdentityMapService $identity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            // reportsTo is presented as {id, name}; without the eager load that
            // is one extra query per row.
            $q = AsabUser::query()->with(['roleAssignments', 'reportsTo:id,name']);

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
                // asab_users.reports_to_id is a uuid column with no FK, so this
                // rule is the only thing between a display name typed by the
                // client and a permanently unresolvable head reference.
                'reportsTo' => ['nullable', 'uuid', Rule::exists('asab_users', 'id')->whereNull('deleted_at')],
                'status' => 'nullable|in:active,inactive',
                'sendLoginEmail' => 'sometimes|boolean',
            ];
            // Per-role assignment rules (client meeting): a branch manager runs
            // exactly ONE branch; an accountant is assigned at BRAND level.
            $role = $request->input('role');
            if ($role === 'branch') {
                $rules['branches'] = 'required|array|size:1';
                // branch_managers.branch_id is a real FK from here on: an id that
                // does not exist would surface as a 500 rather than a 422.
                $rules['branches.*'] = 'uuid|exists:branches,id';
                // companyId is de-facto required here — BranchManagerProvisioner
                // fails closed on a companyless user — but it stays out of the
                // rules on purpose: the published contract says that case answers
                // BRANCH_NOT_IN_COMPANY, and a `required` rule would silently
                // switch the code to VALIDATION_ERROR. The provisioner names the
                // field in error.details instead.
            } elseif ($role === 'accountant') {
                $rules['brands'] = 'required|array|min:1';
                // assignmentAttributes() hard-forces scope=brand and zeroes
                // restaurant_ids, so accepting these returned a 201 echoing
                // "restaurants": [] that read as saved. Reject instead —
                // restaurant scope belongs to the distribution screen
                // (PATCH /admin/accountants/{id}/assignments).
                $rules['restaurants'] = 'prohibited';
                $rules['scope'] = 'prohibited';
            } elseif ($role === 'supplier') {
                // Names WHICH supplier this login owns. Required so an admin can
                // never blind-reset an unrelated mobile account by typing an
                // email that happens to collide.
                $rules['supplierId'] = 'required|uuid|exists:asab_suppliers,id';
            }
            $data = $request->validate($rules, self::ACCOUNTANT_SCOPE_MESSAGES);

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

                // Roles that also exist in the mobile world get the SAME password
                // written to their legacy table, so the one email below opens both
                // (client requirement). Dashboard-only roles resolve to null.
                $this->provisioners->for($data['role'])?->provision($user, $data, $temporaryPassword);

                return $user;
            });

            // Delivery defaults ON (BACKEND_API_SPEC.md §users.store): an account
            // whose password was never sent is unusable until an admin resets it.
            $emailSent = ($data['sendLoginEmail'] ?? true)
                ? $this->emailTemporaryPassword($user, $temporaryPassword)
                : false;

            return $this->created($this->present($user->load(['roleAssignments', 'reportsTo:id,name'])) + ['emailSent' => $emailSent]);
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
                // No-op unless this user has a linked mobile account; when they
                // do, the emailed password must open both worlds.
                $this->credentials->pushToMobile($user, forceReset: true);
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
        return $this->mailer->send($user, new UserPasswordResetNotification($temporaryPassword));
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
                // nullable: array_filter() below already makes an explicit null a
                // no-op, so keep that contract rather than 422-ing on it.
                'reportsTo' => ['sometimes', 'nullable', 'uuid', Rule::exists('asab_users', 'id')->whereNull('deleted_at')],
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
                // Same contract as store(): brand-level here, restaurants via
                // the distribution endpoints.
                $rules['restaurants'] = 'prohibited';
                $rules['scope'] = 'prohibited';
            }
            $data = $request->validate($rules, self::ACCOUNTANT_SCOPE_MESSAGES);

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

            return $this->ok($this->present($user->fresh(['roleAssignments', 'reportsTo:id,name'])));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $user = AsabUser::findOrFail($id);
            DB::transaction(function () use ($user) {
                $user->tokens()->delete();
                // Removing the dashboard user must also close the mobile login
                // it provisioned; asab_users alone cannot reach the legacy guards.
                $this->credentials->disableOnMobile($user);
                // ...and drop its identity links, or the legacy row stays claimed
                // by a deleted user and re-creating the account 422s as ambiguous.
                $this->identity->releaseDashboard($user->id);
                $user->delete();
            });

            return $this->noContent();
        });
    }

    public function import(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $validated = $request->validate([
                'file' => 'required|file',
                'sendLoginEmail' => 'sometimes|boolean',
            ]);
            $file = $request->file('file');
            $rows = array_map('str_getcsv', file($file->getRealPath()));
            $header = array_map('trim', array_shift($rows) ?? []);
            $sendLoginEmail = $validated['sendLoginEmail'] ?? true;

            $imported = 0;
            $skipped = 0;
            $emailFailed = 0;
            $errors = [];
            foreach ($rows as $i => $row) {
                $data = @array_combine($header, $row);
                $email = $data['email'] ?? $data['البريد'] ?? null;
                $name = $data['name'] ?? $data['الاسم'] ?? null;
                if (! $email || ! $name) {
                    $errors[] = ['row' => $i + 2, 'field' => 'email/name', 'message' => 'missing required fields'];

                    continue;
                }
                // withTrashed: asab_users.email is uniquely indexed regardless of
                // deleted_at, so a soft-deleted row still owns the address.
                if (AsabUser::withTrashed()->where('email', $email)->exists()) {
                    $skipped++;

                    continue;
                }
                $temporaryPassword = Str::password(12);
                $user = AsabUser::create([
                    'company_id' => $request->user()->company_id,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $data['phone'] ?? null,
                    'password' => $temporaryPassword, // hashed by the model's 'hashed' cast
                    'avatar' => mb_substr($name, 0, 1),
                    'status' => 'active',
                ]);
                $role = $data['role'] ?? 'accountant';
                // CSV carries no brand/branch columns; scoped roles fail closed
                // (empty ids -> resolver returns nothing) until an admin assigns,
                // instead of granting company-wide 'all' visibility. For the same
                // reason this path must NOT run the legacy provisioners: with no
                // supplierId/branches column there is nothing to link a mobile
                // login to, and a half-provisioned one is worse than none.
                $scope = match ($role) {
                    'accountant' => 'brand',
                    'branch' => 'branch',
                    default => 'all',
                };
                AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => $scope]);
                $imported++;

                if ($sendLoginEmail && ! $this->emailTemporaryPassword($user, $temporaryPassword)) {
                    $emailFailed++;
                }
            }

            return $this->ok([
                'imported' => $imported,
                'skipped' => $skipped,
                'emailFailed' => $emailFailed,
                'errors' => $errors,
            ]);
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

            DB::transaction(function () use ($user, $status) {
                $user->update(['status' => $status]);

                // Deactivating has to reach the mobile login too. Reactivating
                // deliberately does NOT: the legacy row may have been disabled
                // for its own reasons, which this flag does not know about.
                if ($status === 'inactive') {
                    $user->tokens()->delete();
                    $this->credentials->disableOnMobile($user);
                }
            });

            return $this->ok($this->present($user->load(['roleAssignments', 'reportsTo:id,name'])));
        });
    }

    /**
     * Role-forced assignment scope (client meeting): a branch manager runs
     * exactly ONE branch (scope=branch); an accountant is assigned at BRAND
     * level (scope=brand). Other roles keep the payload as-is (admin/head
     * default scope 'all').
     *
     * The zeroed arrays below stay as defence even though the callers now 422
     * on a non-empty accountant `restaurants`: the tenant resolver ORs every
     * non-empty id array regardless of the scope string, so a stale array left
     * behind would silently widen access.
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
            // A platform admin owns everything; there is nothing to assign. The
            // tenant resolver ORs any non-empty id array regardless of the scope
            // string, so an array left over from the wizard would NARROW an
            // account that is supposed to be unrestricted.
            'admin' => [
                'scope' => 'all',
                'brand_ids' => [],
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
            // {id, name} rather than the bare uuid: every consumer of this field
            // renders the head's name, and the uuid alone forced a second lookup.
            'reportsTo' => $u->reportsTo ? ['id' => $u->reportsTo->id, 'name' => $u->reportsTo->name] : null,
            'status' => $u->status,
            'lastLoginAt' => optional($u->last_login_at)->toIso8601String(),
            'createdAt' => optional($u->created_at)->toIso8601String(),
        ];
    }
}
