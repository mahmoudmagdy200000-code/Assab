# Brand Owner Fixed Assets Requests — Backend Specification

---

## Table of Contents

1. [Overview](#1-overview)
2. [Status Reference](#2-status-reference)
3. [List Endpoints](#3-list-endpoints)
4. [Detail Endpoints](#4-detail-endpoints)
5. [Action Endpoints](#5-action-endpoints)
6. [Settings Endpoints](#6-settings-endpoints)
7. [Business Rules & Lifecycles](#7-business-rules--lifecycles)
8. [Branch Manager Side — Required Changes](#8-branch-manager-side--required-changes)
9. [Authorization & Idempotency](#9-authorization--idempotency)
10. [Implementation Checklist](#10-implementation-checklist)
11. [Open Items](#11-open-items)

---

## 1. Overview

This document defines the full backend contract for the **Brand Owner Fixed Assets Requests** module. It covers all five request types and the brand owner settings endpoints.

| #   | Request Type                      | Tab Label                     |
| --- | --------------------------------- | ----------------------------- |
| 1   | Modification                      | Modifications                 |
| 2   | Transfer (Branch-to-Branch)       | Transfers                     |
| 3   | Disposal & External Transfer      | Disposal & External Transfers |
| 4   | Major Discrepancy (from Handover) | Handover Reports              |
| 5   | Review & Audit                    | Review & Audit Requests       |

**General rules that apply to all endpoints:**

- No pagination on any list endpoint.
- All timestamps are ISO 8601 UTC.
- All action endpoints require Brand Owner authorization (role-based).
- Action endpoints are idempotent — repeating the same call must not produce duplicate side effects.
- All action endpoints return `200 OK` with the updated resource in the `data` field and a `side_effects` array listing any follow-up tasks performed.

---

## 2. Status Reference

| Status                   | Meaning                                                             |
| ------------------------ | ------------------------------------------------------------------- |
| `pending`                | Created; awaiting the next actor's action                           |
| `pending_final_approval` | Approved by the first actor; awaiting Brand Owner's final decision  |
| `approved`               | Fully approved by Brand Owner                                       |
| `rejected`               | Rejected by Branch Manager or Brand Owner (depends on request type) |
| `salary_deduction`       | Brand Owner chose salary deduction on a Major Discrepancy request   |

---

## 3. List Endpoints

All list endpoints return a flat array under the `data` key. No pagination.

---

### 3.1 Modification Requests

```
GET /brand-owner/fixed-assets/requests/modification
```

**Response 200:**

```json
{
  "data": [
    {
      "id": "mod-123",
      "branch_name": "Riyadh Branch",
      "asset_name": "Laptop",
      "status": "pending | approved | rejected",
      "created_at": "2026-05-20T10:00:00.000Z"
    }
  ]
}
```

---

### 3.2 Transfer Requests

```
GET /brand-owner/fixed-assets/requests/transfer
```

> Only returns requests at `pending_final_approval`, `approved`, or `rejected`. Requests still `pending` at the destination Branch Manager are not visible to the Brand Owner.

**Response 200:**

```json
{
  "data": [
    {
      "id": "trf-987",
      "asset_name": "Projector",
      "from_branch_name": "Branch A",
      "to_branch_name": "Branch B",
      "status": "pending | pending_final_approval | approved | rejected",
      "created_at": "2026-05-18T09:30:00.000Z"
    }
  ]
}
```

---

### 3.3 Disposal & External Transfer Requests

```
GET /brand-owner/fixed-assets/requests/disposal-and-external-transfer
```

**Response 200:**

```json
{
  "data": [
    {
      "id": "disp-33",
      "branch_name": "Dammam Branch",
      "asset_name": "Air Conditioner",
      "status": "pending | approved | rejected",
      "created_at": "2026-05-12T11:45:00.000Z"
    }
  ]
}
```

---

### 3.4 Handover Report Requests (Major Discrepancy)

```
GET /brand-owner/fixed-assets/requests/handover-reports
```

> These are **Major Discrepancy** requests auto-created from a completed Handover. They are **not** the original Handover Request. See [Section 7.4](#74-handover-report--major-discrepancy-request) for full lifecycle.

**Response 200:**

```json
{
  "data": [
    {
      "id": "hand-201",
      "branch_name": "Riyadh Branch",
      "asset_name": "Laptop",
      "status": "pending | approved | salary_deduction",
      "created_at": "2026-05-15T14:20:00.000Z"
    }
  ]
}
```

---

### 3.5 Review & Audit Requests

```
GET /brand-owner/fixed-assets/requests/review-audit
```

> Only returns requests at `pending_final_approval`, `approved`, or `rejected`. Requests still `pending` are not visible to the Brand Owner.

**Response 200:**

```json
{
  "data": [
    {
      "id": "aud-55",
      "branch_name": "Dammam Branch",
      "asset_name": "Forklift",
      "status": "pending | pending_final_approval | approved | rejected",
      "created_at": "2026-05-10T08:00:00.000Z"
    }
  ]
}
```

---

## 4. Detail Endpoints

Each request type has a `GET /{id}` endpoint. Response model names match the repository.

---

### 4.1 Modification — `BrandOwnerFixedAssetsModificationDetailsResponse`

```
GET /brand-owner/fixed-assets/requests/modification/{id}
```

**Response 200:**

```json
{
  "data": {
    "id": "mod-123",
    "status": "pending | approved | rejected",
    "branch_name": "Riyadh Branch",
    "approved_on": "2026-05-21T12:00:00.000Z",
    "cancellation": null,
    "financial_impact_analysis": {
      "financial_impact": "string",
      "operational_impact": "string",
      "critical_assets": "string",
      "brand_impact_level": "string"
    },
    "asset": {
      "image_url": "string",
      "name": "string",
      "code": "string",
      "branch_name": "string",
      "manager_name": "string",
      "current_status": "string",
      "new_status": "string",
      "reason": "string",
      "value": "string",
      "critical_asset": false,
      "brand_impact": "string",
      "documentation_photo_url": "string",
      "additional_notes": "string"
    },
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": null,
        "occurred_at": "2026-05-20T10:00:00.000Z"
      }
    ]
  }
}
```

---

### 4.2 Transfer — `BrandOwnerFixedAssetsTransferDetailsResponse`

```
GET /brand-owner/fixed-assets/requests/transfer/{id}
```

**Response 200:**

```json
{
  "data": {
    "id": "trf-987",
    "status": "pending | pending_final_approval | approved | rejected",
    "from_branch_name": "Branch A",
    "to_branch_name": "Branch B",
    "approved_on": "2026-05-19T09:00:00.000Z",
    "submitted_by_manager_name": "string",
    "supported_by_manager_name": "string",
    "submitted_on": "2026-05-18T09:30:00.000Z",
    "cancellation": null,
    "asset": {
      "image_url": "string",
      "name": "Projector",
      "code": "string",
      "condition": "excellent | need_attention | problem",
      "is_critical": false,
      "affected_value": "string",
      "last_service": "string",
      "brand_standard": "string"
    },
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": null,
        "occurred_at": "2026-05-18T09:30:00.000Z"
      }
    ]
  }
}
```

**Field notes — `asset`:**

| Field            | Type          | Nullable | Default                               |
| ---------------- | ------------- | -------- | ------------------------------------- |
| `image_url`      | string        | yes      | `""`                                  |
| `name`           | string        | no       | —                                     |
| `code`           | string        | no       | —                                     |
| `condition`      | string (enum) | no       | parsed via `FixedAssetsConditionEnum` |
| `is_critical`    | boolean       | yes      | `false`                               |
| `affected_value` | string        | yes      | `"-"`                                 |
| `last_service`   | string        | yes      | `"-"`                                 |
| `brand_standard` | string        | yes      | `"-"`                                 |

**Field notes — root:**

| Field                       | Type   | Nullable |
| --------------------------- | ------ | -------- |
| `approved_on`               | string | yes      |
| `submitted_by_manager_name` | string | no       |
| `supported_by_manager_name` | string | no       |
| `submitted_on`              | string | no       |
| `cancellation`              | object | yes      |

---

### 4.3 Disposal & External Transfer — `BrandOwnerFixedAssetsDisposalAndExternalTransferDetailsResponse`

```
GET /brand-owner/fixed-assets/requests/disposal-and-external-transfer/{id}
```

**Response 200:**

```json
{
  "data": {
    "id": "disp-33",
    "status": "pending | approved | rejected",
    "branch_name": "Dammam Branch",
    "type": "disposal | external_transfer",
    "approved_on": "2026-05-12T12:00:00.000Z",
    "submitted_by_branch_name": "string",
    "submitted_by_manager_name": "string",
    "submitted_on": "2026-05-12T11:00:00.000Z",
    "cancellation": null,
    "asset": {
      "image_url": "string",
      "name": "string",
      "code": "string",
      "zone_name": "string",
      "condition": "excellent | need_attention | problem",
      "reason_for_disposal": "string",
      "affected_value": "string",
      "evidence_image_url": "string",
      "additional_notes": "string"
    },
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": null,
        "occurred_at": "2026-05-12T11:45:00.000Z"
      }
    ]
  }
}
```

**Field notes — `asset`:**

| Field                 | Type          | Nullable | Default                                   |
| --------------------- | ------------- | -------- | ----------------------------------------- |
| `image_url`           | string        | yes      | `""`                                      |
| `name`                | string        | no       | —                                         |
| `code`                | string        | no       | —                                         |
| `zone_name`           | string        | yes      | `"-"`                                     |
| `condition`           | string (enum) | no       | parsed via `FixedAssetsConditionStrategy` |
| `reason_for_disposal` | string        | yes      | `"-"`                                     |
| `affected_value`      | string        | yes      | `"-"`                                     |
| `evidence_image_url`  | string        | yes      | `""`                                      |
| `additional_notes`    | string        | yes      | `"-"`                                     |

**Field notes — root:**

| Field                       | Type          | Nullable | Notes                                                                                           |
| --------------------------- | ------------- | -------- | ----------------------------------------------------------------------------------------------- |
| `type`                      | string (enum) | no       | parsed via `FixedAssetsTransfersAndDisposalEnum` — distinguishes disposal vs. external transfer |
| `approved_on`               | string        | yes      | —                                                                                               |
| `submitted_by_branch_name`  | string        | no       | —                                                                                               |
| `submitted_by_manager_name` | string        | no       | —                                                                                               |
| `submitted_on`              | string        | no       | —                                                                                               |
| `cancellation`              | object        | yes      | —                                                                                               |

---

### 4.4 Handover Reports (Major Discrepancy) — `BrandOwnerFixedAssetsHandoverReportDetailsResponse`

```
GET /brand-owner/fixed-assets/requests/handover-reports/{id}
```

**Response 200:**

```json
{
  "data": {
    "id": "hand-201",
    "status": "pending | approved | salary_deduction",
    "approved_on": "string",
    "cancellation": null,
    "route_details": {
      "request_id": "string",
      "session_code": "string",
      "from_branch_name": "Riyadh Branch",
      "to_branch_name": "Branch B",
      "success_rate": "string",
      "discrepancies": "string",
      "handover_date": "string",
      "session_duration": "string"
    },
    "asset_details": {
      "image_url": "string",
      "name": "string",
      "subtitle": "string",
      "affected_value": "string",
      "evidence_caption": "string",
      "evidence_image_url": "string"
    },
    "signature_details": {
      "sender": {
        "name": "string",
        "role_label": "string",
        "signed_at": "string",
        "note": "string",
        "image_url": "string"
      },
      "receiver": {
        "name": "string",
        "role_label": "string",
        "signed_at": "string",
        "note": "string",
        "image_url": "string"
      }
    },
    "financial_analysis": {
      "total_affected_value": "string",
      "impact_ratio": "string",
      "employee_record": "string"
    },
    "employee_responsible": "string",
    "warning_note": "string",
    "salary_deduction_amount": "string",
    "salary_deduction_reason": "string",
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": null,
        "occurred_at": "2026-05-15T14:20:00.000Z"
      }
    ]
  }
}
```

---

### 4.5 Review & Audit — `BrandOwnerReviewAuditDetailsResponse`

```
GET /brand-owner/fixed-assets/requests/review-audit/{id}
```

**Response 200:**

```json
{
  "data": {
    "id": "aud-55",
    "status": "pending | pending_final_approval | approved | rejected",
    "branch_name": "Dammam Branch",
    "approved_on": "2026-05-11T10:00:00.000Z",
    "cancellation": null,
    "summary": {
      "manager_name": "string",
      "zone_name": "string",
      "value": "string",
      "total_assets": "string",
      "audit_date": "string"
    },
    "asset": {
      "image_url": "string",
      "name": "string",
      "code": "string",
      "type": "string",
      "zone": "string",
      "conditions": [
        { "condition": "excellent | need_attention | problem", "count": 0 }
      ],
      "value": "string",
      "additional_notes": "string"
    },
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": null,
        "occurred_at": "2026-05-10T08:00:00.000Z"
      }
    ]
  }
}
```

---

## 5. Action Endpoints

All action endpoints are `POST`. Request bodies use the exact keys expected by the repository methods. All reject actions share the same body shape.

---

### 5.1 Modification Actions

```
POST /brand-owner/fixed-assets/requests/modification/{id}/approve
POST /brand-owner/fixed-assets/requests/modification/{id}/reject
```

Reject body:

```json
{ "reason": "string" }
```

---

### 5.2 Transfer Actions

```
POST /brand-owner/fixed-assets/requests/transfer/{id}/approve
POST /brand-owner/fixed-assets/requests/transfer/{id}/reject
```

> Brand Owner may only act when status is `pending_final_approval`.

Reject body:

```json
{ "reason": "string" }
```

---

### 5.3 Disposal & External Transfer Actions

```
POST /brand-owner/fixed-assets/requests/disposal-and-external-transfer/{id}/approve
POST /brand-owner/fixed-assets/requests/disposal-and-external-transfer/{id}/reject
```

Reject body:

```json
{ "reason": "string" }
```

---

### 5.4 Handover Report (Major Discrepancy) Actions

```
POST /brand-owner/fixed-assets/requests/handover-reports/{id}/approve
POST /brand-owner/fixed-assets/requests/handover-reports/{id}/salary-deduction
POST /brand-owner/fixed-assets/requests/handover-reports/{id}/reject
```

**Approve** (`approveHandoverReportRequest`):

```json
{ "warningNote": "string" }
```

**Salary Deduction** (`salaryDeductionHandoverReportRequest`):

```json
{ "amount": "string", "reason": "string" }
```

**Reject:**

```json
{ "reason": "string" }
```

> The detail response maps request fields as: `warningNote` → `warning_note`, `amount` → `salary_deduction_amount`, `reason` → `salary_deduction_reason`.

**Side effects on `approve`** — writes to the Major Discrepancy record:

```json
{ "employee_name": "string", "reason": "string" }
```

**Side effects on `salary-deduction`** — writes to the Major Discrepancy record:

```json
{
  "employee_name": "string",
  "amount": "number",
  "reason": "string",
  "note": "string"
}
```

Also flags the related Handover record on the Branch Manager side:

```json
{
  "isDeducted": true,
  "deduction": {
    "employee_name": "string",
    "amount": "number",
    "reason": "string",
    "note": "string"
  }
}
```

---

### 5.5 Review & Audit Actions

```
POST /brand-owner/fixed-assets/requests/review-audit/{id}/approve
POST /brand-owner/fixed-assets/requests/review-audit/{id}/reject
```

Reject body:

```json
{ "reason": "string" }
```

---

## 6. Settings Endpoints

All settings endpoints live under `/brand-owner/settings`. `GET` returns the current stored values; `PATCH` accepts the same shape to update them. Model names match the `BrandOwnerSettingsRepository`.

---

### 6.1 Approval Settings — `BrandOwnerSettingApprovalEntity`

```
GET  /brand-owner/settings/approval
PATCH /brand-owner/settings/approval
```

**Request / Response 200:**

```json
{
  "response_time_hours": 24,
  "initial_audit_minimum_assets": 50,
  "initial_audit_excellent_ratio": 85,
  "personal_approval_for_all_new_branches": false,
  "auto_approve_transfers_within": 20000,
  "auto_approve_modifications_within": 15000,
  "auto_approve_disposals_within": 10000,
  "critical_assets_always_require_approval": false
}
```

**Field notes:**

| Field                                     | Type | Nullable | Default |
| ----------------------------------------- | ---- | -------- | ------- |
| `response_time_hours`                     | int  | no       | `24`    |
| `initial_audit_minimum_assets`            | int  | no       | `50`    |
| `initial_audit_excellent_ratio`           | int  | no       | `85`    |
| `personal_approval_for_all_new_branches`  | bool | no       | `false` |
| `auto_approve_transfers_within`           | int  | no       | `20000` |
| `auto_approve_modifications_within`       | int  | no       | `15000` |
| `auto_approve_disposals_within`           | int  | no       | `10000` |
| `critical_assets_always_require_approval` | bool | no       | `false` |

---

### 6.2 Report Settings — `BrandOwnerReportSettingsEntity`

```
GET  /brand-owner/settings/report
PATCH /brand-owner/settings/report
```

**Request / Response 200:**

```json
{
  "monthly_reports": ["asset_status", "branch_performance"],
  "quarterly_reports": ["asset_status", "overall_investment"],
  "annual_reports": ["branch_performance", "overall_investment"]
}
```

**Field notes:**

| Field               | Type          | Nullable | Default |
| ------------------- | ------------- | -------- | ------- |
| `monthly_reports`   | array of enum | no       | `[]`    |
| `quarterly_reports` | array of enum | no       | `[]`    |
| `annual_reports`    | array of enum | no       | `[]`    |

**Enum values (`BrandOwnerReportSettingMonthlyReportEnum`):**

| Value                | Label              |
| -------------------- | ------------------ |
| `asset_status`       | Asset Status       |
| `branch_performance` | Branch Performance |
| `overall_investment` | Overall Investment |

---

### 6.3 Security Settings — `BrandOwnerSecuritySettingsEntity`

```
GET  /brand-owner/settings/security
PATCH /brand-owner/settings/security
```

**Request / Response 200:**

```json
{
  "data_encryption": true,
  "daily_backup_enabled": true,
  "daily_backup_interval_hours": 24,
  "monthly_security_audit": false
}
```

**Field notes:**

| Field                         | Type | Nullable | Default |
| ----------------------------- | ---- | -------- | ------- |
| `data_encryption`             | bool | no       | `true`  |
| `daily_backup_enabled`        | bool | no       | `true`  |
| `daily_backup_interval_hours` | int  | no       | `24`    |
| `monthly_security_audit`      | bool | no       | `false` |

---

### 6.4 Retention Settings — `BrandOwnerRetentionSettingsEntity`

```
GET  /brand-owner/settings/retention
PATCH /brand-owner/settings/retention
```

**Request / Response 200:**

```json
{
  "photo_retention_years": 3,
  "handover_reports_retention_years": 5
}
```

**Field notes:**

| Field                              | Type | Nullable | Default |
| ---------------------------------- | ---- | -------- | ------- |
| `photo_retention_years`            | int  | no       | `3`     |
| `handover_reports_retention_years` | int  | no       | `5`     |

---

### 6.5 Notifications Settings — `BrandOwnerNotificationsSettingsEntity`

```
GET  /brand-owner/settings/notifications
PATCH /brand-owner/settings/notifications
```

**Request / Response 200:**

```json
{
  "notifications": [
    { "type": "audit", "enabled": true },
    { "type": "transfer", "enabled": true },
    { "type": "status_modification", "enabled": false },
    { "type": "handover", "enabled": true },
    { "type": "major_discrepancies", "enabled": false }
  ]
}
```

**Field notes — array item (`BrandOwnerNotificationEntity`):**

| Field     | Type | Nullable | Default |
| --------- | ---- | -------- | ------- |
| `type`    | enum | no       | `audit` |
| `enabled` | bool | no       | `false` |

**Enum values (`BrandOwnerNotificationSettingType`):**

| Value                 | Meaning                           |
| --------------------- | --------------------------------- |
| `audit`               | Audit notifications               |
| `transfer`            | Transfer notifications            |
| `status_modification` | Modification status notifications |
| `handover`            | Handover notifications            |
| `major_discrepancies` | Major discrepancy notifications   |

---

## 7. Business Rules & Lifecycles

### 7.1 Modification Request

**Flow:** Branch Manager → Brand Owner

| Step | Actor          | Action                       | Resulting Status |
| ---- | -------------- | ---------------------------- | ---------------- |
| 1    | Branch Manager | Creates modification request | `pending`        |
| 2    | Brand Owner    | Approves                     | `approved`       |
| 2    | Brand Owner    | Rejects                      | `rejected`       |

- Each modification is a **single, self-contained request** — no batching.
- Sent directly to the Brand Owner upon creation.

---

### 7.2 Transfer Request (Branch-to-Branch)

**Flow:** Branch Manager (origin) → Branch Manager (destination) → Brand Owner

| Step | Actor                        | Action                                      | Resulting Status         |
| ---- | ---------------------------- | ------------------------------------------- | ------------------------ |
| 1    | Branch Manager (origin)      | Creates transfer request per item           | `pending`                |
| 2    | Branch Manager (destination) | Approves                                    | `pending_final_approval` |
| 2    | Branch Manager (destination) | Rejects                                     | `rejected`               |
| 3    | Brand Owner                  | Approves (only if `pending_final_approval`) | `approved`               |
| 3    | Brand Owner                  | Rejects (only if `pending_final_approval`)  | `rejected`               |

- **Each asset item is a separate, independent request.** Items are never batched.
- The Brand Owner list endpoint surfaces only `pending_final_approval` and terminal statuses. Requests at `pending` are not visible to the Brand Owner.
- Brand Owner **cannot act** on a request still at `pending`.

---

### 7.3 Disposal & External Transfer Request

**Flow:** Branch Manager → Brand Owner

| Step | Actor          | Action                                     | Resulting Status |
| ---- | -------------- | ------------------------------------------ | ---------------- |
| 1    | Branch Manager | Creates disposal/external transfer request | `pending`        |
| 2    | Brand Owner    | Approves                                   | `approved`       |
| 2    | Brand Owner    | Rejects                                    | `rejected`       |

- **Each asset item is a separate, independent request.** Items are never batched.
- Sent directly to the Brand Owner upon creation.

---

### 7.4 Handover Report → Major Discrepancy Request

This is a **two-stage process**. The Handover Request and the Major Discrepancy Request are **distinct entities**.

#### Stage 1 — Handover (Branch Manager side)

1. Branch Manager creates a Handover Request.
2. Handover is processed to `completed` status.
3. For every **rejected item**, the system auto-creates a separate **Major Discrepancy Request**.

#### Stage 2 — Major Discrepancy (Brand Owner side)

**Flow:** System (auto-created) → Brand Owner

| Step | Actor       | Action                                                   | Resulting Status   |
| ---- | ----------- | -------------------------------------------------------- | ------------------ |
| 1    | System      | Auto-creates Major Discrepancy request per rejected item | `pending`          |
| 2    | Brand Owner | Approves                                                 | `approved`         |
| 2    | Brand Owner | Chooses Salary Deduction                                 | `salary_deduction` |
| 2    | Brand Owner | Rejects                                                  | `rejected`         |

- **Each rejected item becomes its own Major Discrepancy request.** Items are never batched.
- See [Section 5.4](#54-handover-report-major-discrepancy-actions) for full side-effect details per action.

---

### 7.5 Review & Audit Request

**Flow:** (initiator TBD) → Brand Owner

**Supported statuses:** `pending | pending_final_approval | approved | rejected`

> Full lifecycle and initiator details to be provided in a follow-up document.

---

## 8. Branch Manager Side — Required Changes

### 8.1 Transfer Requests — Dashboard Fetch Behavior

| Current (incorrect)                                             | Required                                                                               |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| All items from a transfer request returned in a single response | Each item returned as a **separate, independent request object** — one record per item |

### 8.2 Transfer Requests — Status Transitions

| Event                               | New Status               |
| ----------------------------------- | ------------------------ |
| Destination Branch Manager approves | `pending_final_approval` |
| Destination Branch Manager rejects  | `rejected`               |
| Brand Owner approves                | `approved`               |
| Brand Owner rejects                 | `rejected`               |

### 8.3 Disposal & External Transfer — Item Separation

Each asset must be stored and transmitted as a **separate request record**. Multi-item batching is not permitted.

### 8.4 Transfer Branch-to-Branch — Item Separation

Each asset must be stored and transmitted as a **separate request record**. Multi-item batching is not permitted.

### 8.5 Handover — Post-Completion: Major Discrepancy Auto-Creation

After a handover reaches `completed`:

- For every rejected item, auto-create a separate Major Discrepancy Request.
- This request is independent of the Handover Request and appears under the Brand Owner's **Handover Reports** tab.

### 8.6 Handover Detail — Salary Deduction Flag

When the Brand Owner applies salary deduction, the Handover detail record for that item must be updated:

```json
{
  "isDeducted": true,
  "deduction": {
    "employee_name": "string",
    "amount": "number",
    "reason": "string",
    "note": "string"
  }
}
```

This flag must be exposed on the Branch Manager's handover detail view.

---

## 9. Implementation Checklist

### Fixed Assets — Brand Owner Side

- [ ] `GET  /brand-owner/fixed-assets/requests/modification`
- [ ] `GET  /brand-owner/fixed-assets/requests/modification/{id}`
- [ ] `POST /brand-owner/fixed-assets/requests/modification/{id}/approve`
- [ ] `POST /brand-owner/fixed-assets/requests/modification/{id}/reject`
- [ ] `GET  /brand-owner/fixed-assets/requests/transfer` (filter: exclude `pending` from destination Branch Manager)
- [ ] `GET  /brand-owner/fixed-assets/requests/transfer/{id}`
- [ ] `POST /brand-owner/fixed-assets/requests/transfer/{id}/approve` (gate on `pending_final_approval`)
- [ ] `POST /brand-owner/fixed-assets/requests/transfer/{id}/reject`
- [ ] `GET  /brand-owner/fixed-assets/requests/disposal-and-external-transfer`
- [ ] `GET  /brand-owner/fixed-assets/requests/disposal-and-external-transfer/{id}`
- [ ] `POST /brand-owner/fixed-assets/requests/disposal-and-external-transfer/{id}/approve`
- [ ] `POST /brand-owner/fixed-assets/requests/disposal-and-external-transfer/{id}/reject`
- [ ] `GET  /brand-owner/fixed-assets/requests/handover-reports` (Major Discrepancy requests only)
- [ ] `GET  /brand-owner/fixed-assets/requests/handover-reports/{id}`
- [ ] `POST /brand-owner/fixed-assets/requests/handover-reports/{id}/approve` + side effect: write to Major Discrepancy record
- [ ] `POST /brand-owner/fixed-assets/requests/handover-reports/{id}/salary-deduction` + side effects: write to Major Discrepancy record + flag Handover with `isDeducted`
- [ ] `POST /brand-owner/fixed-assets/requests/handover-reports/{id}/reject`
- [ ] `GET  /brand-owner/fixed-assets/requests/review-audit`
- [ ] `GET  /brand-owner/fixed-assets/requests/review-audit/{id}`
- [ ] `POST /brand-owner/fixed-assets/requests/review-audit/{id}/approve`
- [ ] `POST /brand-owner/fixed-assets/requests/review-audit/{id}/reject`

### Settings — Brand Owner Side

- [ ] `GET  /brand-owner/settings/approval`
- [ ] `PATCH /brand-owner/settings/approval`
- [ ] `GET  /brand-owner/settings/report`
- [ ] `PATCH /brand-owner/settings/report`
- [ ] `GET  /brand-owner/settings/security`
- [ ] `PATCH /brand-owner/settings/security`
- [ ] `GET  /brand-owner/settings/retention`
- [ ] `PATCH /brand-owner/settings/retention`
- [ ] `GET  /brand-owner/settings/notifications`
- [ ] `PATCH /brand-owner/settings/notifications`

### Fixed Assets — Branch Manager Side

- [ ] Fix transfer dashboard fetch: one record per item, not per multi-item request
- [ ] Fix disposal & external transfer: one record per item
- [ ] Fix transfer branch-to-branch: one record per item
- [ ] Implement status transitions for transfer requests (see Section 8.2)
- [ ] Implement auto-creation of Major Discrepancy requests on handover completion per rejected item
- [ ] Expose `isDeducted` + deduction object on handover detail view when salary deduction has been applied

---

## 11. Open Items

- [ ] Clarify who initiates Review & Audit requests and confirm whether `pending_final_approval` applies.
- [ ] Provide full Review & Audit lifecycle details (follow-up document).
- [ ] Confirm request body shape for Review & Audit approve action (not yet specified).
- [ ] Confirm whether settings `PATCH` endpoints return the full updated resource or only changed fields.
