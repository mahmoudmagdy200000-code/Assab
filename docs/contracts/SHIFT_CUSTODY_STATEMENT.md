# Shift Custody Statement API Contract (P2 / G2)

**Version:** 1.0.0  
**Date:** 2026-11-11  
**Status:** Canonical Approved Read-Only Specification  
**Authority:** Mahmoud — Final Acceptance Authority  
**Author:** Antigravity (Principal Engineer)  
**Consumer:** Dashboard (`CompanyDashboard.tsx` employee accounts tab «عهدة الشيفتات / Shift Custody»)  
**Provider:** Backend `Modules/Admin` (`GET /company/me/employees/{id}/shift-custody-statement`)

---

## 1. Overview & Business Boundaries

This document defines the authoritative contract for the **Shift Custody Statement** (`كشف عهدة الشيفتات`) exposed inside the employee account details on the company dashboard surface.

### Crucial Architectural Rules (STMT-01..03, F01, F02):
1. **Strict Separation of Funds (STMT-02, F01):**
   - The shift cash custody statement is a projection over actual physical cash transactions (`cashier_custody_transactions` and `personal_ledger_transactions`).
   - It is strictly separate from the employee dues & deductions ledger (`asab_employee_movements` / `EmployeeLedgerService`).
   - Physical cash custody transactions are NEVER copied into `asab_employee_movements`, and payroll/settlement balance operations never touch or zero-out physical cash custody.
2. **Zero Writes Policy (MT-19):**
   - The statement endpoint is strictly read-only (`GET`).
   - It never invokes mutating or locking availability routines (e.g. `availableCashierMinor`, `lockCashierOwners`), never acquires `FOR UPDATE` row locks, and never mutates ledger rows or balances. Two consecutive GET requests yield identical database row counts across all tables.
3. **Receipt Provenance & Recipient Shift (MT-11, MT-12, F02):**
   - A handover confirmed between cashiers creates matching sent and received entries linked to the same `cashier_shift_handover_receipts.id`.
   - In `cashier_custody_transactions`, the recipient's `related_shift_id` records the *source* shift. The true *receiving shift* must be resolved from `receipt.receiving_cashier_shift_id`.
   - For branch managers, `receipt.receiving_branch_manager_shift_id` records the receiving manager shift.
4. **Manager Transfer Classification (MT-18, F02):**
   - A manager row stored with `transaction_type = 'Total Sales'` that carries a `receipt_id` represents physical cash received from a cashier (`"handover_received"` / «استلام من كاشير»), NOT new restaurant sales.
5. **Variance Non-Cash Exclusion (MT-18, F01):**
   - Rows representing variance/shortage allocations (`Variance`, `Shortage`, `Manager Variance`, `Variance from Cashier`) are excluded from physical cash balances and physical custody entries.
6. **Pending Requests Isolation (MT-15, STMT-03):**
   - Handover requests with status `pending` are returned separately in `pending[]`. They do NOT affect `opening_balance`, `closing_balance`, or any `running_balance`.

---

## 2. HTTP Endpoint Specification

- **Method:** `GET`
- **Path:** `/company/me/employees/{id}/shift-custody-statement`
- **Authentication:** Bearer token (Laravel Sanctum)
- **Authorization:** `asab.role:accountant,head,company-admin`
- **Scoping:** Tenant-scoped (`company_id`), branch-scoped to assigned branches (`scopeToAssignedBranches`). If the employee is outside scope, return `404 Not Found` (never leaking existence).

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `from` | `string (YYYY-MM-DD)` | No | null | Start of period (inclusive). If omitted, includes all historical entries. |
| `to` | `string (YYYY-MM-DD)` | No | null | End of period (inclusive). If omitted, open-ended to present. |
| `page` | `integer` | No | `1` | Page number (1-based). |
| `pageSize` | `integer` | No | `20` | Page size (`1` to `100`). |

---

## 3. Response Schema (JSON)

```json
{
  "unit": "halala",
  "identity_link_state": "linked",
  "identity": {
    "employee_id": "00000000-0000-0000-0000-000000000001",
    "name": "أحمد كاشير",
    "role": "cashier",
    "linked_type": "cashier",
    "linked_id": "11111111-1111-1111-1111-111111111111",
    "branch_id": "22222222-2222-2222-2222-222222222222",
    "branch_name": "فرع العليا الرئيسي"
  },
  "period": {
    "from": "2026-11-01",
    "to": "2026-11-30"
  },
  "balances": {
    "is_available": true,
    "opening_balance": 0,
    "closing_balance": 420000,
    "total_cash_in": 420000,
    "total_cash_out": 0,
    "net_period_change": 420000
  },
  "entries": [
    {
      "source_type": "cashier_custody",
      "source_id": "33333333-3333-3333-3333-333333333333",
      "event_at": "2026-11-11T14:30:00+03:00",
      "business_date": "2026-11-11",
      "branch": {
        "id": "22222222-2222-2222-2222-222222222222",
        "name": "فرع العليا الرئيسي"
      },
      "transaction_type": "Handover Received",
      "classification": "handover_received",
      "label_ar": "استلام من كاشير",
      "label_en": "Received from cashier",
      "source_shift_id": "44444444-4444-4444-4444-444444444444",
      "receiving_shift_id": "55555555-5555-5555-5555-555555555555",
      "receipt_id": "66666666-6666-6666-6666-666666666666",
      "counterparty": {
        "type": "cashier",
        "id": "77777777-7777-7777-7777-777777777777",
        "name": "محمد كاشير 1"
      },
      "cash_in": 420000,
      "cash_out": 0,
      "running_balance": 420000,
      "confirmation_state": "confirmed",
      "lineage_key": null,
      "reversal_id": null
    }
  ],
  "pending": [
    {
      "request_type": "handover_sent",
      "request_id": "88888888-8888-8888-8888-888888888888",
      "created_at": "2026-11-11T16:00:00+03:00",
      "business_date": "2026-11-11",
      "amount": 720000,
      "counterparty": {
        "type": "branch_manager",
        "id": "99999999-9999-9999-9999-999999999999",
        "name": "سعد مدير الفرع"
      },
      "source_shift_id": "55555555-5555-5555-5555-555555555555",
      "status": "pending"
    }
  ],
  "pagination": {
    "page": 1,
    "pageSize": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

---

## 4. Unlinked Identity State (`unlinked`)

If the employee has no associated mobile operational account (no link in `asab_identity_map` for cashier or branch_manager, and no valid `legacy_cashier_id` matching their branch):

```json
{
  "unit": "halala",
  "identity_link_state": "unlinked",
  "identity": {
    "employee_id": "00000000-0000-0000-0000-000000000002",
    "name": "خالد عامل",
    "role": "waiter",
    "linked_type": null,
    "linked_id": null,
    "branch_id": "22222222-2222-2222-2222-222222222222",
    "branch_name": "فرع العليا الرئيسي"
  },
  "period": {
    "from": null,
    "to": null
  },
  "balances": {
    "is_available": false,
    "opening_balance": null,
    "closing_balance": null,
    "total_cash_in": null,
    "total_cash_out": null,
    "net_period_change": null
  },
  "entries": [],
  "pending": [],
  "pagination": {
    "page": 1,
    "pageSize": 20,
    "total": 0,
    "totalPages": 0
  }
}
```

The UI displays the informative status banner: **«الحساب غير مرتبط بعهدة شيفتات»** / **"Account is not linked to shift custody"** with no fake numeric 0 balance.

---

## 5. Monetary Units & Acceptance Cases Mapping

All balances, `cash_in`, `cash_out`, and pending amounts are expressed in **integer halalas** (1 SAR = 100 Halalas):

| Acceptance Case | Real Amount (SAR) | API Value (`unit: "halala"`) | UI Formatted Value (AR / EN) |
|---|---|---|---|
| MT-11 Handover 1 | 4,200.00 SAR | `420000` | `4,200.00 ر.س` / `4,200.00 SAR` |
| MT-12 Cashier 2 Sales | 3,000.00 SAR | `300000` | `3,000.00 ر.س` / `3,000.00 SAR` |
| MT-12 Manager Handover | 7,200.00 SAR | `720000` | `7,200.00 ر.س` / `7,200.00 SAR` |
| MT-29 Reversal / Correction | 48.00 / 480.00 SAR | `4800` / `48000` | `48.00 ر.س` / `480.00 ر.س` |
| MT-30 Debt / Repayment | 20.00 / 5.00 SAR | `2000` / `500` | `20.00 ر.س` / `5.00 ر.س` |

---

## 6. Ordering & Pagination Invariant (MT-17)

1. **Deterministic Order:**
   Entries are strictly sorted in ascending chronological order:
   ```sql
   ORDER BY event_at ASC, source_type ASC, source_id ASC
   ```
2. **Balancing Invariant:**
   - `opening_balance`: Computed over all confirmed entries strictly before `from`.
   - `closing_balance`: Computed as `opening_balance + total_cash_in - total_cash_out` for the full period, remaining **identical across all pages**.
   - `running_balance`: Begins at `opening_balance` and accumulates per row. Row 1 of Page 2 begins with the `running_balance` resulting from the last row of Page 1.
