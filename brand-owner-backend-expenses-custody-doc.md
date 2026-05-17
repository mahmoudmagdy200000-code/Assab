# Brand Owner Backend API Documentation (Proposed)

> This document covers only the brand-owner flows for expenses, custody, cash sales transfer, and the owner payment form.

## Scope

- Brand owner expenses views
- Brand owner custody requests and request details
- Brand owner cash sales transfer details
- Owner payment form

## Access Rules

Brand owner screens currently reuse a mix of brand-owner-specific and branch-manager APIs. If a brand owner screen uses a branch-manager endpoint, that endpoint must explicitly allow brand-owner access.

### Reused Branch-Manager Endpoints That Need Brand-Owner Access

| Method | Endpoint                                          | Used By                                               | Access Rule              | Hint                                                                                                    |
| ------ | ------------------------------------------------- | ----------------------------------------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------- |
| GET    | `/branch-manager/expenses`                        | Brand owner expenses list/history                     | Grant brand-owner access | Do not expose drafts to brand-owner access. Keep `/branch-manager/expenses/drafts` branch-manager only. |
| GET    | `/branch-manager/expenses/{id}`                   | Expense detail reuse if opened from brand-owner views | Grant brand-owner access |                                                                                                         |
| GET    | `/branch-manager/expenses/summary`                | Brand owner expenses summary cards/charts             | Grant brand-owner access |                                                                                                         |
| GET    | `/branch-manager/expenses/recent`                 | Brand owner recent expenses widgets                   | Grant brand-owner access |                                                                                                         |
| GET    | `/branch-manager/ledger/personal-balance-only`    | Owner payment form / custody balance summary          | Grant brand-owner access |                                                                                                         |
| GET    | `/branch-manager/ledger/personal-custody-balance` | Owner payment form / custody balance details          | Grant brand-owner access |                                                                                                         |
| GET    | `/branch-manager/ledger/transactions`             | Owner payment form / custody transactions             | Grant brand-owner access |                                                                                                         |
| GET    | `/custody/recipients`                             | Owner payment form recipient picker                   | Grant brand-owner access | get branch manager only to brand-owner access                                                           |
| POST   | `/custody/request-cashin`                         | Owner payment form submit action                      | Grant brand-owner access | Request must be sent as multipart/form-data.                                                            |
| GET    | `/custody/request-cashin/history`                 | Owner payment form history reuse                      | Grant brand-owner access |                                                                                                         |
| GET    | `/custody/requests`                               | Brand owner custody request list                      | Grant brand-owner access |                                                                                                         |
| GET    | `/custody/requests/{id}`                          | Brand owner custody request details                   | Grant brand-owner access |                                                                                                         |

## Approval / Rejection Actions

The brand-owner flows need explicit approval and rejection endpoints for expenses, custody requests, and cash sales transfers.

### Expenses

#### POST `/branch-manager/expenses/{id}/approve`

Approves a brand-owner expense request that is being reused from the branch-manager contract.

#### POST `/branch-manager/expenses/{id}/reject`

Rejects a brand-owner expense request.

##### Request Body

```json
{
  "reason": "string"
}
```

### Custody Requests

#### POST `/custody/requests/{id}/approve`

Approves a custody request.

#### POST `/custody/requests/{id}/reject`

Rejects a custody request.

##### Request Body

```json
{
  "reason": "string"
}
```

### Cash Sales Transfer

#### POST `/brand-owner/cash-sales-transfers/{id}/approve`

Approves the brand-owner cash sales transfer request.

#### POST `/brand-owner/cash-sales-transfers/{id}/reject`

Rejects the brand-owner cash sales transfer request.

##### Request Body

```json
{
  "reason": "string"
}
```

## Existing Endpoint Contract Notes

### Custody Request Details

Existing custody request detail responses must allow the following status values:

- `pending`
- `approved`
- `rejected`

This applies to both the existing custody detail endpoint and the cash sales transfer details endpoint described below.

### Custody Request Detail Endpoint

#### GET `/custody/requests/{id}`

Used by the branch-manager custody detail flow and any brand-owner reuse of the same contract.

#### Response Object: `CustodyRequestDetailsResponse`

```json
{
  "data": {
    "requestId": "string",
    "status": "pending|approved|rejected",
    "details": {
      "requestedAmount": 0,
      "purpose": "string",
      "preferredReceiptMethod": "string",
      "attachments": [
        {
          "filename": "string",
          "url": "string",
          "uploadedAt": "ISO-8601"
        }
      ],
      "additionalNotes": "string"
    },
    "timelines": [],
    "approval": null
  }
}
```

#### Field Notes

- `status` is required and must be one of `pending`, `approved`, or `rejected`.
- `timelines` is reused by the brand-owner details UI.
- `approval` may be `null` until the request is reviewed.

## Brand Owner Expenses

The brand-owner expenses views currently reuse the branch-manager expenses list/history contract.

### GET `/branch-manager/expenses`

Returns the paginated expenses list used by the brand-owner expenses screen.

#### Access Hint

- Brand owner access should include only non-draft expenses.
- Draft items must remain excluded from brand-owner access and stay on the branch-manager draft path.

#### Query Parameters

| Field | Type | Required | Notes                  |
| ----- | ---- | -------- | ---------------------- |
| page  | int  | yes      | Pagination page number |

#### Response Object: `BrandOwnerExpensesListResponse`

```json
{
  "data": {
    "data": [
      {
        "id": "string",
        "type": "string",
        "status": "pending|approved|rejected",
        "submittedBy": "string",
        "dateTime": "ISO-8601",
        "amount": 0,
        "preferredReceiptMethod": "string",
        "purpose": "string"
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "total": 0
    }
  }
}
```

### GET `/branch-manager/expenses/{id}`

Returns the selected expense details for brand-owner detail screens that reuse the branch-manager contract.

#### Response Object: `BrandOwnerExpenseDetailsResponse`

Use the existing branch-manager expense detail model for the matching expense type, and keep the status field aligned with `pending|approved|rejected`.

## Brand Owner Cash Sales Transfer

The brand owner currently has a details screen for cash sales transfer, but no dedicated backend endpoint. This endpoint should be created.

### GET `/brand-owner/cash-sales-transfers/{id}`

Returns the details payload for the brand-owner cash sales transfer details screen.

#### Path Parameters

| Field | Type   | Required | Notes                          |
| ----- | ------ | -------- | ------------------------------ |
| id    | string | yes      | Cash sales transfer request id |

#### Response Object: `BrandOwnerCashSalesTransferDetailsResponse`

```json
{
  "data": {
    "requestId": "string",
    "status": "pending|approved|rejected",
    "summary": {
      "transferFrom": "string",
      "recipient": "string",
      "handOverAmount": 0,
      "handoverMethod": "string",
      "handoverDate": "ISO-8601"
    },
    "senderDetails": {
      "name": "string",
      "image": "string",
      "branchName": "string"
    },
    "timelines": [],
    "approval": {
      "status": "pending|approved|rejected",
      "approvedBy": "string",
      "approvedAt": "ISO-8601",
      "rejectedReason": "string"
    }
  }
}
```

#### Field Notes

- `status` must use the same `pending|approved|rejected` contract as `custody/requests/{id}`.
- `approval.status` must also use the same enum values.
- This endpoint is brand-owner specific and should not be treated as a branch-manager-only route.

## Owner Payment Form

The owner payment form is a brand-owner entry flow that submits a custody cash-in request.

### POST `/custody/request-cashin`

This is the submit endpoint for the owner payment form.

#### Request Object: `OwnerPaymentFormRequest` as `multipart/form-data`

| Field                  | Type     | Required | Notes                                              |
| ---------------------- | -------- | -------- | -------------------------------------------------- |
| recipientEmployeeId    | string   | yes      | Recipient employee id                              |
| amount                 | number   | yes      | Request amount                                     |
| preferredReceiptMethod | string   | yes      | `cash` or `bank_transfer`                          |
| handoverDate           | ISO-8601 | yes      | Selected payment date                              |
| note                   | string   | yes      | Optional note text in the UI, required by contract |
| attachments[]          | file     | no       | One or more receipt files                          |

#### Response Object: `OwnerPaymentFormResponse`

```json
{
  "data": {
    "requestId": "string",
    "status": "pending|approved|rejected",
    "submittedAt": "ISO-8601"
  }
}
```

#### Field Notes

- Keep the request body aligned with the current owner payment form UI fields.
- The request must be submitted as form data, not JSON.
- If the backend prefers a multipart payload, the same logical fields should still be returned in the response contract above.
- The response `status` should match the custody request status enum.

## Recommended Backend Additions

If the current backend does not already expose brand-owner access to the reused branch-manager endpoints, the access policy must be added before the UI is wired.

If the brand-owner cash sales transfer details endpoint is not added, the UI will continue to depend on the branch-manager custody detail contract and the screen will remain partially stubbed.
