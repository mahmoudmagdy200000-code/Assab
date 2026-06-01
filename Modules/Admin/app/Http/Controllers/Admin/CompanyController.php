<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabCompany;

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

            $p = $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map([$this, 'present'], $p->items()));
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'logo' => 'nullable|string|max:255',
                'contactName' => 'nullable|string|max:200',
                'contactEmail' => 'nullable|email|max:191',
                'contactPhone' => 'nullable|string|max:32',
                'city' => 'nullable|string|max:80',
                'plan' => 'required|in:Basic,Professional,Enterprise',
                'modules' => 'nullable|array',
                'adminEmail' => 'nullable|email|max:191',
            ]);

            $company = DB::transaction(fn () => AsabCompany::create([
                'name' => $data['name'],
                'logo' => $data['logo'] ?? null,
                'contact_name' => $data['contactName'] ?? null,
                'contact_email' => $data['contactEmail'] ?? null,
                'contact_phone' => $data['contactPhone'] ?? null,
                'city' => $data['city'] ?? null,
                'plan' => $data['plan'],
                'status' => 'trial',
                'max_branches' => $this->planLimit($data['plan'], 'branches'),
                'max_users' => $this->planLimit($data['plan'], 'users'),
                'modules' => $data['modules'] ?? [],
                'admin_email' => $data['adminEmail'] ?? null,
                'start_date' => now(),
                'next_billing' => now()->addMonth(),
            ]));

            return $this->created($this->present($company));
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

    private function present(AsabCompany $c): array
    {
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
            'maxBranches' => $c->max_branches,
            'maxUsers' => $c->max_users,
            'monthlyRevenue' => $c->monthly_revenue,
            'startDate' => optional($c->start_date)->toIso8601String(),
            'nextBilling' => optional($c->next_billing)->toIso8601String(),
            'modules' => $c->modules ?? [],
            'adminEmail' => $c->admin_email,
            'createdAt' => optional($c->created_at)->toIso8601String(),
        ];
    }
}
