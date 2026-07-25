<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;

/**
 * Platform-admin supplier directory (FE wiring 2026-07-17 §1).
 *
 * POST /admin/users requires a supplierId for role=supplier and pins the login
 * email to the supplier's contact_email (SUPPLIER_EMAIL_MISMATCH) — but the only
 * supplier list carrying contact_email, GET /company/me/suppliers, names just the
 * five company roles in its allow-list, so an admin reading it gets WRONG_ROLE.
 * This is the admin-side read that feeds that picker.
 *
 * Cross-company by design: the BelongsToTenant global scope no-ops for admin, so
 * companyId is an explicit narrowing filter rather than an implicit scope.
 */
class SupplierController extends AsabController
{
    /** GET /admin/suppliers?companyId=&search=&status=&page=&pageSize= */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'companyId' => 'nullable|uuid',
                'search' => 'nullable|string|max:191',
                'status' => 'nullable|in:active,inactive',
            ]);

            $q = AsabSupplier::query()
                ->select(['id', 'company_id', 'name', 'contact_email', 'status', 'user_id', 'is_external'])
                ->orderBy('name');

            if (isset($data['companyId'])) {
                $q->where('company_id', $data['companyId']);
            }
            if (isset($data['status'])) {
                $q->where('status', $data['status']);
            }
            if (isset($data['search'])) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$data['search']}%")
                    ->orWhere('contact_email', 'like', "%{$data['search']}%"));
            }

            // Same clamp as the company-portal list, plus a floor: perPage 0
            // makes LengthAwarePaginator divide by zero building lastPage.
            $perPage = max(1, min((int) $request->query('pageSize', 50), 100));
            $p = $q->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

            return $this->paginated($p, array_map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                // The email POST /admin/users MUST send for role=supplier. null
                // means no login is possible until an admin sets contact_email —
                // assertLoginEmailMatches() treats null as a mismatch.
                'contactEmail' => $s->contact_email,
                'companyId' => $s->company_id,
                'status' => $s->status,
                'isExternal' => (bool) $s->is_external,
                // Already provisioned. A second login for the same supplier is
                // what SUPPLIER_LOGIN_AMBIGUOUS rejects later — let the picker
                // grey these out instead of failing at submit.
                'hasLogin' => $s->user_id !== null,
            ], $p->items()));
        });
    }
}
