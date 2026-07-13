# FE Wiring — T14 Company Portal (بوابة الشركة / Company-Admin)

> Backend module status: ✅ ready — 77 endpoints live; the 8 audited behavior gaps were fixed 2026-07-13 (this doc details those; the other 69 were already correct — see the audit `docs/tasks/T14-company-portal.md` §1 inventory + §6 notes for their contracts).
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`).
> Company-admin block: `asab.tenant + asab.role:company-admin + asab.idempotency + asab.audit`. Support block: all 5 company roles. Admin review block: `asab.role:admin`.
> Envelopes (`AsabResponse`): single = bare object; lists = `{data, meta?}`; paginated = `{data, meta:{page,pageSize,total,totalPages}}`. Errors: `{error:{code,message,messageAr,details}, requestId}` + status.
> Money: integer **halalas**. Secrets (API key, webhook secret) shown once on create.

## What changed this pass (wire these)

| Gap | Endpoint(s) | New behavior |
|---|---|---|
| T14.1 | `POST /company/me/branches`; `GET/POST /admin/branch-requests*` | Tenant add-branch is now **pending review** |
| T14.2 | `POST /company/invitations`, `POST /company/invitations/{id}/resend` | Invitation **email** delivery + real resend; token no longer in API |
| T14.3 | `POST /company/invitations/accept` | Cross-tenant email **409** guard |
| T14.4 | `GET /company/me/subscription`, `GET /company/me/dashboard` | Real **storage** quota |
| T14.5 | `PUT /company/me/sso` | Enterprise gate on the **live subscription** plan |
| T14.6 | `POST /company/me/branches/{id}/transfer-manager` | Membership validation + scope sync |

---

## 1. Add-branch pending review (CMP-4, T14.1)

`POST /company/me/branches` · Role: `company-admin`

Body: `{ restaurantId, name, city, managerUserId?, managerName?, address?, phone?, targetHalalas? }`.

Response `201`:

```json
{ "id": "…", "name": "فرع جديد", "city": null, "status": "pending_review", "reviewStatus": "pending_review", "messageAr": "سيظهر بعد مراجعة الإدارة" }
```

- The branch is created **inactive + `pending_review`** and does not go live until a platform admin approves it. It still consumes a branch quota slot (can't spam requests).
- In `GET /company/me/brands` (org tree), each branch node carries **`reviewStatus`** (`pending_review | approved | rejected`). Render pending branches with a badge; they are not `status:"active"` yet.

### Platform-admin review queue · Role: `admin`

- `GET /admin/branch-requests?status=pending_review|approved|rejected|all` → `{ data:[{ …branch, reviewStatus, reviewNote }] }`.
- `POST /admin/branch-requests/{id}/approve` → flips `reviewStatus:"approved"` + `status:"active"`, notifies the company-admin (`branch.approved`). Response includes `reviewStatus:"approved"`.
- `POST /admin/branch-requests/{id}/reject` — body `{ reason }` (required) → `reviewStatus:"rejected"` + `reviewNote`, branch stays inactive, notifies company-admin (`branch.rejected`).
- A non-pending id on approve/reject → **404** (the queue only matches `pending_review`).

## 2. Invitations — email + resend (CMP-3, T14.2)

- `POST /company/invitations` (canonical) / `POST /company/me/users` (alias) — unchanged body; now **queues `CompanyInvitationMail`** to the invitee with the accept link `…/accept-invitation?token=…`. The invitation object **no longer exposes `token`** in any response (list or create) — delivery is by email only.
- `POST /company/invitations/{id}/resend` — **new** — regenerates the token, extends `expires_at` +7d, re-queues the email. Response `202 { resent:true, expiresAt }`. Non-pending invitation → **409** `INVALID_INVITATION`.
- `POST /company/me/users/{id}/resend-invite` — now resolves the member's pending invitation and delegates (same 202); no pending invitation → 409.
- FE: the invite modal's «إرسال دعوة» now truly emails. Don't render a token. Use `GET /company/invitations?status=pending` for the pending list.

## 3. Accept invitation — cross-tenant guard (T14.3)

`POST /company/invitations/accept` (public). If the invitation email already belongs to an AsabUser in **another company**, returns **409** `EMAIL_IN_OTHER_COMPANY` and mutates nothing (no company/password overwrite). A fresh email still creates the hashed-password user and returns `{ accessToken, refreshToken, … }`. An established account's password is never overwritten on accept.

## 4. Storage quota (CMP-2, T14.4)

- `GET /company/me/subscription` → `usage.storage = { used, max }` (GB; `max: null` = unlimited) alongside brands/restaurants/branches/users.
- `GET /company/me/dashboard` → `quotas.storage = { usedGb, maxGb }` — **now real** (summed from the company's namespaced attachment bytes; no longer hardcoded 0).
- Meter renders «used / max GB»; unlimited plan → «/∞».

## 5. SSO enterprise gate (T14.5)

`PUT /company/me/sso` now gates on the **live `CompanySubscription` plan code** (`enterprise`), not the legacy `asab_companies.plan` string. A company self-upgraded to Enterprise via the portal can now configure SSO; any non-enterprise plan → **403** `PLAN_REQUIRED` (`details.plan` = current plan code). Key the upgrade prompt off the code.

## 6. Transfer manager (T14.6)

`POST /company/me/branches/{id}/transfer-manager` · body `{ newManagerUserId, note? }`.

- `newManagerUserId` must be an **active member of the same company** → else **422** `INVALID_ROLE_SCOPE`.
- On success (one transaction): sets the branch's `asab_manager_user_id` + `manager` label, moves the member's `CompanyUser.branch_id`, and syncs their `branch` role assignment `branch_ids` — so the new manager's data scope (ResolveTenant) follows the branch.

## 7. Support channels seed (T14.7)

`GET /company/me/support/channels` — seeded chat hours corrected to «يومياً 9ص - 9م» (SRS 9am–9pm).

---

## Reference — unchanged surface (already ✅)

The remaining endpoints were correct before this pass; contracts are enumerated in `docs/tasks/T14-company-portal.md` §1 (77-row inventory with method/path/role/behavior) and §6 (FE notes: quota chips, module three-state mapping, error codes, secrets-shown-once, async export flow, realtime channels, screen mapping CMP-1…CMP-8). Key groups: dashboard #8–9 · subscription/plans #3,#10–16 · users/invitations #5–7,#17–22 · org #23–33 · modules #34–35 · billing #36–48 · settings/API-keys/SSO/webhooks #49–65 · support #66–76 · audit #77.

## Enums / codes added this pass

- Branch `reviewStatus`: `pending_review` قيد المراجعة · `approved` معتمد · `rejected` مرفوض.
- New error codes: `EMAIL_IN_OTHER_COMPANY` (409, accept), `INVALID_INVITATION` (409, resend non-pending), `INVALID_ROLE_SCOPE` (422, transfer-manager), `PLAN_REQUIRED` (403, SSO — now vs live plan).

## Test accounts

- `company-admin` (company-scoped), platform `admin` (no company). SSO test needs the company's `CompanySubscription.plan.code === 'enterprise'`.
