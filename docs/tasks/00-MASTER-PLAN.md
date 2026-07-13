# ASAB Dashboard — Master Build Plan

> Source: [ASAB_DASHBOARD_SRS.md](../ASAB_DASHBOARD_SRS.md) · Routes ground truth:
> `Modules/Admin/routes/api.php` · Branch: `asab-admin-backend`
> Owner: backend. Each finished module ships with an **FE wiring doc** (see protocol below).

---

## How this plan works

- The SRS is split into **16 module workstreams** (T01–T16). Each has its own task file
  in `docs/tasks/` with: audited endpoint inventory (what already exists in code),
  gaps, ordered tasks, acceptance criteria, and required tests.
- **Verify-first rule:** most endpoints already exist. A task is only "build" if the
  audit proved the code doesn't cover the SRS requirement. Never re-implement what works.
- **Definition of Done (per module):**
  1. All tasks in the module file checked.
  2. Pest feature tests green (`composer test`, `-d memory_limit=1024M`).
  3. `./vendor/bin/pint` clean on touched files.
  4. **FE wiring doc delivered** at `docs/fe-wiring/FE-T##-<module>.md`
     (protocol below) — this is the handoff artifact for the frontend team.
  5. Status flipped in the board below.

## FE Wiring Doc Protocol (deliverable per module)

File: `docs/fe-wiring/FE-T##-<module>.md` — template: [_TEMPLATE.md](../fe-wiring/_TEMPLATE.md).
Every FE doc MUST contain, per endpoint:

1. Method + full path (with `/api/v1` base) + required role(s) + middleware notes
   (tenant header, idempotency key).
2. Request: path params, query params, body JSON with field types + validation rules.
3. Response: real JSON example (from seeded data or test). **Actual ASAB envelopes
   (`AsabResponse` — NOT the `{success, message, data, meta}` shape in older docs):**
   success = bare object or `{data: [...], meta: {...}}` for paginated lists;
   error = `{error: {code, message, ...}, requestId}` with the HTTP status.
   Binary downloads (Excel/PDF/CSV) stream with no envelope.
4. Enums used (key + Arabic label exactly as the UI shows them).
5. Pagination/filter/sort contract for list endpoints.
6. Screen mapping: which prototype screen/component each endpoint feeds.

## Module Board

| # | Module file | Scope (SRS) | Depends on | Status | FE doc |
|---|---|---|---|---|---|
| T01 | [T01-foundation-auth-shared.md](T01-foundation-auth-shared.md) | Auth, 2FA, sessions, attachments, notifications bell, lookups, user prefs | — | ⬜ | ⬜ |
| T02 | [T02-admin-core.md](T02-admin-core.md) | §6 ADM-1..4, ADM-7, ADM-8 (companies/brands/restaurants/branches/packages/users/distribution/permissions/audit/uploads) | T01 | ⬜ | ⬜ |
| T03 | [T03-operations-pipeline.md](T03-operations-pipeline.md) | §5 lifecycle, origin, rollup state machine, approve/reject/final/correction, pipeline widget | T01 | ✅ | ✅ [FE-T03](../fe-wiring/FE-T03-operations-pipeline.md) |
| T04 | [T04-accountant-dashboard-sales.md](T04-accountant-dashboard-sales.md) | §7 ACC-0, ACC-1 (dashboard, inbox, sales reconciliation, variance→employee allocation, day completeness) | T03 | ✅ | ✅ [FE-T04](../fe-wiring/FE-T04-accountant-dashboard-sales.md) |
| T05 | [T05-expenses-fixed-assets.md](T05-expenses-fixed-assets.md) | §7 ACC-2 + assets (multi-invoice, توثيق, convert-to-asset wizard, drafts, register) | T03 | ✅ | ✅ [FE-T05](../fe-wiring/FE-T05-expenses-fixed-assets.md) |
| T06 | [T06-purchases-accountant.md](T06-purchases-accountant.md) | §7 ACC-3 (3-way match, توثيق, line edit, returns) | T03 | ✅ | ✅ [FE-T06](../fe-wiring/FE-T06-purchases-accountant.md) |
| T07 | [T07-inventory-waste.md](T07-inventory-waste.md) | §7 ACC-4, ACC-5 (daily/monthly, flags loop, daily-list push, waste classification+allocation) | T03 | ✅ | ✅ [FE-T07](../fe-wiring/FE-T07-inventory-waste.md) |
| T08 | [T08-shifts.md](T08-shifts.md) | §7 ACC-6 + BRM-5 + MOB-1.6 cashier bridge (live board, brand config, close flow, history, approval chain) | T03 | ✅ | ✅ [FE-T08](../fe-wiring/FE-T08-shifts.md) |
| T09 | [T09-employees-cash-custody.md](T09-employees-cash-custody.md) | §7 ACC-7, ACC-8 + HEAD-4 (ledger, movements, settle; custody, replenish, txn approve) | T03 | ✅ | ✅ [FE-T09](../fe-wiring/FE-T09-employees-cash-custody.md) |
| T10 | [T10-head-erp.md](T10-head-erp.md) | §8 HEAD-1..3, HEAD-5 + §14.3 + ADM-6 admin ERP screen (grouped final approval, performance, ERP batches/export/post) | T03,T04 | ✅ | ✅ [FE-T10](../fe-wiring/FE-T10-head-erp.md) |
| T11 | [T11-procurement.md](T11-procurement.md) | §9 PRC-1..3 (requests, consolidation+savings, PO pipeline, items, suppliers) | T01 | ⬜ | ⬜ |
| T12 | [T12-branch-manager.md](T12-branch-manager.md) | §11 BRM-1..4, 6, 7 (BRM-5 → T08) (overview, daily upload, master data, counts, purchase requests, settings) | T03 | ⬜ | ⬜ |
| T13 | [T13-supplier.md](T13-supplier.md) | §10 SUP-1..2 — behind `FEATURE_ASAB_SUPPLIER_PORTAL` (hidden v1) | T11 | ✅ | ✅ [FE-T13](../fe-wiring/FE-T13-supplier.md) |
| T14 | [T14-company-portal.md](T14-company-portal.md) | §12 CMP-1..8 (subscription, users/invites, org, modules, billing, settings, support, API keys/SSO/webhooks) | T01 | ✅ | ✅ [FE-T14](../fe-wiring/FE-T14-company-portal.md) |
| T15 | [T15-reports-distribution.md](T15-reports-distribution.md) | §14.2 RPT-1..4 + ADM-5 + report catalog/builder + HEAD-7/ACC-10 downloads + BRO-1.2 owner delivery | T10 | 🟡 | ✅ [FE-T15](../fe-wiring/FE-T15-reports-distribution.md) |
| T16 | [T16-notifications-reminders-realtime.md](T16-notifications-reminders-realtime.md) | §14.1 triggers matrix + ACC-9/HEAD-6 reminders+rules, broadcasting/live counters | T03 | ⬜ | ⬜ |

Status legend: ⬜ not started · 🟡 in progress · ✅ done (FE doc sent) · 🔵 blocked

### §13 traceability (Brand Owner & Mobile touchpoints — no separate file)

| SRS id | Owner |
|---|---|
| BRO-1.1 owner credentials on brand create | T02 (B-A6, already built) |
| BRO-1.2 owner receives monthly reports | T15.3 |
| BRO-1.3 legacy BrandOwner mobile portal | out of scope (untouched legacy) |
| MOB-1.1 receive mobile operations (م1) | T03 / T12.3 |
| MOB-1.2 push daily-count item lists | T07 (daily-list save + notify) |
| MOB-1.3 flagged-items notifications | T07.9 |
| MOB-1.4 rejection notifies branch manager | T03 (reject flow) + T16 matrix |
| MOB-1.5 supplier accept/reject from mobile | T13 (legacy supplier portal already live) |
| MOB-1.6 cashier shift-close → م1 bridge | **T08.12** |

## Build order (senior recommendation)

```
Wave 1 (foundation):   T01 → T03            (everything hangs off pipeline + auth)
Wave 2 (money core):   T04 → T05 → T06      (accountant review loop = product core)
Wave 3 (ops):          T07 → T08 → T09
Wave 4 (approval top): T10 → T15
Wave 5 (procurement):  T11 → T12 → T13
Wave 6 (portal+polish):T14 → T16
```

Rationale: the accountant loop (waves 1–2) is the demo-critical path — branch submits →
accountant reviews → head approves → ERP. Everything else decorates that spine.
T02 (admin core) is already largely built; schedule its verification pass in parallel
with Wave 2 whenever FE needs those endpoints first.

## Cross-cutting rules (apply to every task)

- Controllers thin → Services → Repositories; no facades in services (CLAUDE.md).
- `DB::transaction` around approve+allocate+ledger-post chains.
- `final-approved` operations immutable — reject mutation with domain exception (409).
- Idempotency middleware on all mutations that FE may retry.
- Every list endpoint: pagination + filters per SRS; tenant-scoped queries only.
- Every mutation audited; every status label returned as **key + Arabic label** pair.
- Tests: happy path + role denial + tenant isolation + edge (locked op, over-allocation).

## Deferred backlog

- **DEFERRED (T09.8) — auto-salary-deduction job.** ASAB v1 has no salary-run, so the
  «سيتم خصم الرصيد السالب من الراتب القادم» rule ships as (a) the statement's
  `autoDeductFromSalary` flag (true when the employee's standing balance < 0), and
  (b) the corrected payroll export math (net = salary − Σdebits + Σcredits). An
  automated monthly command that posts `SAL-` deduction movements is **not built**
  — add it when a payroll/salary-run module lands. No dead scheduler code was added.
