# PKG-1 / BRC-04b — Flutter compatibility note (2026-10-12)

Status: backend change LOCAL ONLY (not committed, not deployed). **No production deployment before the Flutter migration below ships or an approved transition plan exists.** No Flutter code was changed in this package.

## Affected client calls (AS-IS in the app)
| Old call (Flutter@b245348, per the 2026-10-12 reconciliation audit H-07 / §F.1 #8 — client file/line not re-read in this session: UNVERIFIED here) | New server behaviour |
|---|---|
| `POST /api/v1/cashier/my-shifts/{shift}/responsibility/approve` (cashier approve-responsibility cubit) | 409 `LEGACY_RESPONSIBILITY_APPROVAL_RETIRED`, nothing written |
| `POST /api/v1/cashier/my-shifts/{shift}/responsibility/reject` | unchanged (200) |
| `GET …/my-shifts/{shift}/responsibility/details` | unchanged |
| Manager `POST /api/v1/branch-manager/shifts/{shift}/responsibility/approve` (Dashboard has no caller; mobile manager only) | 409, same code |

## Replacement (TD-7)
`POST /api/v1/shift-reports/{shift}/liability/respond` — header `Idempotency-Key: <uuid>` (required); body `{ "version": 1, "response": "accepted" | "objected", "reason": "<required if objected>" }`; 200 `{success, message, data:{cashier_shift_id, version, response}}`. `version` is the current allocation version from the allocation read model; a stale version is a 409.
Preceding steps are already live: manager/cashier `liability/allocate` (+ `confirm`), final manager decision `liability/approve` (posts the shortage exactly once). Employee objection is recorded and does not stop the handover (Rules v3.2).

## UI migration requirements
1. Replace the approve action with `liability/respond {response:'accepted'}`; the reject action with `{response:'objected', reason}` (reason mandatory; the old reject endpoint still works but writes the legacy status only).
2. Generate one `Idempotency-Key` per user action and reuse it on retry.
3. Show the server `code` for 409 (`EMPLOYEE_RESPONSE_ALREADY_RECORDED`, stale version) — do not recompute eligibility client-side.
4. Remove any wording implying that approving charges the cashier immediately: the charge happens at manager approval.
5. Until migrated, the old "approve" button returns 409; ship client and server together.
