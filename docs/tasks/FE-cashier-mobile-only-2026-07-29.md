# FE Prompt — Cashiers become mobile-only + brand-scoped pickers (2026-07-29)

Copy everything below into the dashboard frontend task/agent.

---

## Context

Backend changed the ownership of cashier accounts. Cashiers are now created **only** in the mobile app by the branch manager. The dashboard no longer creates them — it reads them. Two dashboard surfaces are affected: the branch **Employees** screen and anything that posts an employee with a cashier role.

Envelopes are unchanged: list = `{ data: [...], meta: { page, pageSize, total, totalPages } }`, error = `{ error: { code, message, messageAr, details? }, requestId }`.

## 1. Remove "Add cashier" from the dashboard

- In the branch-manager **Employees** screen, the "Add employee" form must no longer offer `cashier` / `كاشير` / `أمين صندوق` as a selectable role. Remove it from the role dropdown.
- The email field on that form is gone from the API contract (it only existed to provision the mobile login). Drop it from the payload; `phone` is still accepted and now stored.
- If a cashier role is still submitted (free-text roles, old cached form), the API answers **422**:

```json
{ "error": {
    "code": "CASHIER_MOBILE_ONLY",
    "message": "Cashiers are added from the mobile app by the branch manager; they cannot be created here.",
    "messageAr": "يتم إضافة الكاشير من تطبيق الموبايل بواسطة مدير الفرع، ولا يمكن إضافته من لوحة التحكم."
} }
```

Show `messageAr` as an inline form error (do not treat it as a generic 500 toast).

Affected requests:

| Method | Endpoint | Change |
|---|---|---|
| POST | `/api/v1/company/me/branch/employees` | rejects cashier role; body: `name, role, salaryHalalas, shift?, nationalId?, hireDate?, phone?` (no `email`) |
| POST | `/api/v1/branch/employees` | same rule; body: `empNumber, name, role, monthlySalary, nationalId?, shiftType?, hireDate?, phone?` |
| POST | `/api/v1/admin/restaurants/{id}/upload/employees` | a cashier-role row fails with a per-row error in the importer report; the rest of the sheet still imports |

The success response no longer contains the `cashier: { provisioned, cashierId, emailSent, ... }` block — delete any UI that read it.

## 2. Employees list now shows the mobile-created cashiers

`GET /api/v1/company/me/branch/employees` (and `GET /api/v1/branch/employees`) returns dashboard employees **plus** the cashiers the branch manager created in the mobile app for that branch, merged and sorted by name. Query params unchanged: `search`, `status`, `page`, `pageSize` (max 100).

Row shape (new fields at the bottom):

```json
{
  "id": "uuid",
  "empNumber": "EMP-0007",        // null for a cashier with no dashboard record yet
  "name": "كاشير 1 — برجر بيت — فرع التحلية",
  "role": "cashier",
  "monthlySalary": 0,              // halalas; dashboard-entered — the mobile form has no salary field
  "shiftType": null,
  "nationalId": null,
  "hireDate": "2026-07-26",
  "status": "active",
  "email": "cashier1003@nakhat.sa",
  "phone": "0554101003",
  "source": "mobile",             // "mobile" | "dashboard"
  "cashierId": "uuid|null",       // the mobile cashiers.id when this person exists in the app
  "workingShifts": [
    { "id": "uuid", "name": "الرابع", "startTime": "08:00", "endTime": "16:00" }
  ],
  "addedBy": "مدير برجر بيت — فرع التحلية",
  "addedAt": "2026-07-26T19:52:40+03:00"
}
```

UI requirements:

1. Add a **source** indicator per row (`mobile` badge = "من التطبيق"). Rows with `source: "mobile"` are **not editable/deletable** from the dashboard — hide the row actions except salary entry (below).
2. Show `workingShifts` (name + `startTime`–`endTime`) in the row/detail — this is the "Shift Details" the manager sees in the app.
3. Show `addedBy` + `addedAt` as "أضيف بواسطة / بتاريخ".
4. `monthlySalary` comes back `0` for a mobile cashier because salary is entered on the dashboard, not in the app. Render `0` as an empty/"لم يحدد" state with a call-to-action to set the salary, not as a real `0 ر.س` figure.
5. `email` / `phone` may be present for mobile rows and null for dashboard-only rows — render conditionally.

## 3. Brand isolation on every branch picker

Branch lists are now scoped to the caller's own brand. A brand owner or branch manager no longer receives every brand's branches.

- `GET /api/v1/branches` and `GET /api/branch-manager/branches` — own brand only; both now accept `?search=` (server-side name filter) alongside `per_page`.
- `GET /api/brand-owner/branches`, `GET /api/brand-owner/dashboard/branches`, `GET /api/brand-owner/dashboard` — own brand only.
- An account not linked to a brand gets an **empty list** (fail-closed), not every branch. Render an explicit empty state ("لا توجد فروع مرتبطة بعلامتك التجارية") instead of an endless spinner.
- Posting a branch id outside the caller's brand returns **403** — surface the message, don't retry.

## 4. Shift config validation

`PATCH …/brands/{brandId}/shift-config` and `POST …/brands/{brandId}/shift-config/regenerate` now reject schedules longer than a day:

```json
{ "error": {
    "code": "SHIFT_SCHEDULE_OVERFLOW",
    "message": "4 shifts × 8h = 32h does not fit in a 24-hour day.",
    "messageAr": "عدد الورديات (4) × مدة الوردية (8 ساعة) = 32 ساعة، وهو أكبر من اليوم (24 ساعة)."
} }
```

Validate client-side before submitting: `numShifts * durationHours <= 24`. Show the remaining hours live in the form (e.g. 4 shifts → max 6h each). This was the cause of "dashboard says 4 shifts, the app shows 3": the 4th window wrapped onto the 1st.

## 5. Branch-manager workday = the whole day of shifts

`GET /api/branch-manager/workday/current` (mobile, but the same resource feeds any dashboard shift view) no longer assumes a fixed 09:00–17:00 / 8h manager shift. The window now comes from the branch's shift templates: start = first shift start, end = last shift end, duration = **sum** of the day's shift hours (3 × 8h → 24h).

New fields in `shift_progress` (and in `shift.shift_overview`):

```json
{ "start_time": "00:00", "end_time": "00:00",
  "number_of_hours": 3.5,     // elapsed
  "planned_hours": 24,        // sum of the branch's shift hours
  "shifts_count": 3,
  "progress_percentage": 14.6 }
```

Use `planned_hours` (not 8) as the denominator anywhere the UI draws the manager's shift progress.

## 6. Live shift board («مباشر») now actually has rows

`GET /api/v1/accountant/shifts/live` reads `asab_shifts` with status `active|late`, scoped to the accountant's assigned brands. It was always empty because a mobile shift only reached `asab_shifts` when it **closed**. A mobile shift now mirrors into the dashboard the moment the cashier **starts** it, and the close finishes that same row (same `id`, no duplicate).

Response is unchanged in shape:

```json
{ "active": [ { "id": "...", "branchId": "...", "status": "active", "cashierName": "...", "startedAt": "..." } ],
  "overdue": [ ... ],
  "kpis": { "openNow": 1, "closedToday": 0, "todaySalesHalalas": 0, "cashGapsPendingReview": 0 } }
```

Realtime: subscribe to `operations.brand.{brandId}` (and/or `reminders.branch.{branchId}`) and refresh the board on the `shift.started` event — payload `{ id, branchId, status, supervisor, startedAt, endedAt }`. Same channel already carries the later status changes.

Note for QA: shifts that were **already running** before this deploy have no mirror row and only appear when they close. New starts appear immediately.
