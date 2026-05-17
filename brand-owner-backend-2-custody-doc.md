## Owner Payment Form

### POST `/brand-owner/payment-form`

This is the submit endpoint for the owner payment form.

#### Request Object: `OwnerPaymentFormRequest` as `multipart/form-data`

| Field                  | Type     | Required | Notes                                                   |
| ---------------------- | -------- | -------- | ------------------------------------------------------- |
| recipientEmployeeId    | string   |          | Recipient employee id                                   |
| amount                 | number   |          | Request amount                                          |
| preferredReceiptMethod | string   |          | `cash_handover` or `bank_transfer`                      |
| handoverDate           | ISO-8601 |          | required if `preferredReceiptMethod` is `cash_handover` |
| transferDate           | ISO-8601 |          | required if `preferredReceiptMethod` is `bank_transfer` |
| purpose                | string   |          | optional                                                |
| note                   | string   |          | Optional note text in the UI, required by contract      |
| attachments[]          | file     |          | optional One receipt files                              |

## Goals

- **Payment logs endpoint:** `GET /brand-owner/payment-logs` — return owner payment logs with optional filters: `type`, `sortByDate`, `branchId`, plus pagination (`page`).
- **Cash sales transfers endpoints:**
  - `GET /brand-owner/cash-sales-transfers` — list cash sales transfers (summary) with filters: `fromDate`, `toDate`, `storeId`, `status`, plus pagination.
  - `GET /brand-owner/cash-sales-transfers/{transferId}` — retrieve cash sales transfer details including related cash sales items and amounts.

  ## Exact filter keys, enums and DTOs used by the screens

  The app currently exposes a limited, explicit set of filter keys for the payment log screen (these are what the UI sends in practice via `BrandOwnerPaymentLogFilterQuery`). Use these exact keys in the API contract to remain compatible with the client.
  - `PaymentLogFilterKey` (string keys sent as query params): `type`, `sortByDate`, `branchId`
    - `type` — maps to `CustodyPreferredReceiptMethod` values: `bank_transfer`, `cash_handover`.
    - `sortByDate` — maps to `TimeKeys` keys: `last_24_hours`, `last_7_days`, `last_30_days`, `last_6_months`.
    - `branchId` — branch identifier (string) selected from the branch list (optional).

  ### Enums referenced by the client
  - `CustodyPreferredReceiptMethod`: `bank_transfer`, `cash_handover` (client uses `value` strings).
  - `TimeKeys` (sort presets): `last_24_hours` (`last24Hours` UI), `last_7_days`, `last_30_days`, `last_6_months`.

  ### GET `/brand-owner/payment-logs`

  Query params accepted by the backend to match client filters: `type`, `sortByDate`, `branchId`, `page`, `pageSize`.

  Response (client model): `BrandOwnerPaymentLogResponse`

  ```json
  {
    "summary": {
      "totalCashIn": 0,
      "totalCashOut": 0
    },
    "data": [
      {
        "id": "string",
        "title": "string",
        "branchName": "string",
        "amount": 0,
        "dateTime": "ISO-8601",
        "isIncome": true,
        "methodLabel": "bank_transfer|cash_handover",
        "submittedBy": "string"
      }
    ]
  }
  ```

  Notes: this matches `BrandOwnerPaymentLogResponse`, `BrandOwnerPaymentLogSummaryModel`, and `BrandOwnerPaymentLogItemModel` in the client code.

  ### GET `/brand-owner/payment-logs/{paymentLogId}`

  Response (client model): `BrandOwnerPaymentLogDetailsModel`

  ```json
  {
    "id": "string",
    "title": "string",
    "description": "string",
    "amount": 0,
    "branchName": "string",
    "managerName": "string",
    "dateTime": "ISO-8601",
    "methodLabel": "bank_transfer|cash_handover",
    "isIncome": true
  }
  ```

  ### GET `/brand-owner/cash-sales-transfers`

  Response (client list model): `BrandOwnerRequestsCustodySalesTransfersResponse`

  ```json
  {
    "data": [
      {
        "id": "string",
        "submittedBy": "string",
        "amount": 0,
        "dateTime": "ISO-8601",
        "status": "string"
      }
    ]
  }
  ```

  ### GET `/brand-owner/cash-sales-transfers/{transferId}`

  Response (client model): `BrandOwnerSalesTransferDetailsModel`

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
      "approval": [
        {
          "id": "string",
          "name": "string",
          "role": "string",
          "imageUrl": "string?",
          "status": "approved|rejected|pending"
        }
      ]
    }
  }
  ```
