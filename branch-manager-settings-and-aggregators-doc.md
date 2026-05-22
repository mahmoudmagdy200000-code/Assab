# Branch Manager Settings and Aggregators API

## Scope

These APIs back the branch manager settings screen. They are **implemented** in the
`BranchManagers` module and live under the global `/api` prefix.

This API covers:

- Branch manager account details
- Settings snapshot
- Available aggregators
- Assigned aggregators
- Add, remove, and enable or disable aggregators
- Notification toggles

### Conventions

- **Envelope:** every endpoint returns the unified format
  `{ "success": true, "message": "...", "data": { ... } }`. The shapes below are the `data` payload.
- **Auth:** all endpoints require a Sanctum bearer token. `account-details` and
  `reset-password` are open to any authenticated user; the aggregator and notification
  endpoints require the `branch.manager` role and operate on the caller's own branch.
- **Identifiers:** `id` / `aggregator_id` are the aggregator UUID primary keys. The
  slug-like values in the examples below (`jahez`, `hunger-station`) are illustrative.

---

## 1. Get Branch Manager Account Details

#### GET `/branch-manager/settings/account-details`

Returns the account details shown in the branch manager settings screen.
All Types of users can access this endpoint to get the details of their account by token.

- **Response Object:** `BranchManagerMyAccountDetailsResponse`

##### Response Shape

```json
{
  "first_name": "Omar",
  "last_name": "Ahmed",
  "email_address": "ahmed@example.com",
  "role": "Branch Manager",
  "assigned_to": "Branch 1",
  "weekdays": "Mon - Fri",
  "work_shift": "Morning (6 Am - 12 PM)"
}
```

---

## 2. Get Settings Snapshot

#### GET `/branch-manager/settings/aggregators`

Returns the full settings snapshot used by the branch manager settings home section.
in available_aggregators the backend should return a aggregator not added to the branch.
notifications by default is true for all types.

- **Response Object:** `BranchManagerSettingAndAggregatorsResponse`

##### Response Shape

```json
{
  "branch_info": {
    "branch_id": "branch-001",
    "branch_name": "Riyadh Central Branch",
    "branch_manager_name": "Sami Al-Hakim",
    "branch_manager_image_url": "https://example.com/manager.png",
    "branch_image_url": "https://example.com/branch.png",
    "branch_opening": "Mon - Fri / 9Am - 8Pm",
    "total_cashiers": 12,
    "total_aggregators": 3
  },
  "available_aggregators": [
    {
      "id": "jahez",
      "name": "Jahez",
      "logo_url": "https://example.com/jahez.png",
      "enabled": false
    }
  ],
  "added_aggregators": [
    {
      "id": "hunger-station",
      "name": "Hunger Station",
      "logo_url": "https://example.com/hunger-station.png",
      "enabled": true
    }
  ],
  "notifications": [
    {
      "type": "shiftVarianceAlerts",
      "enabled": true
    },
    {
      "type": "dailyInventoryReminders",
      "enabled": true
    },
    {
      "type": "approvedAggregatorsOnly",
      "enabled": true
    },
    {
      "type": "assetTransferRequests",
      "enabled": false
    },
    {
      "type": "allowSplitShiftHandovers",
      "enabled": true
    }
  ]
}
```

##### Notification Types

| Type                       | Title                       | Description                                                                         |
| -------------------------- | --------------------------- | ----------------------------------------------------------------------------------- |
| `shiftVarianceAlerts`      | Shift Variance Alerts       | Get notified whenever a handover report shows sales or payment discrepancies.       |
| `dailyInventoryReminders`  | Daily Inventory Reminders   | Send push/email reminders to me & staff to start or complete daily inventory tasks. |
| `approvedAggregatorsOnly`  | Approved Aggregators Only   | Only allow payment platforms you’ve approved, unauthorized ones won’t show up.      |
| `assetTransferRequests`    | Asset Transfer Requests     | Get notified instantly when assets are being transferred in or out of your branch.  |
| `allowSplitShiftHandovers` | Allow Split Shift Handovers | Enable cashiers to hand over in segments (e.g., lunch shift vs. dinner shift).      |

---

## 3. Get Available Aggregators

#### GET `/branch-manager/settings/aggregators/available`

the backend should return a aggregator not added to the branch in the added_aggregators list.

- **Response Object:** `BranchManagerAggregatorListResponse`

##### Response Shape

```json
[
  {
    "id": "jahez",
    "name": "Jahez",
    "logo_url": "https://example.com/jahez.png",
    "enabled": false
  },
  {
    "id": "careem",
    "name": "Careem",
    "logo_url": "https://example.com/careem.png",
    "enabled": false
  }
]
```

---

## 4. Get Branch Aggregators

#### GET `/branch-manager/settings/aggregators/assigned`

Returns the aggregators already added to the branch.

- **Response Object:** `BranchManagerAggregatorListResponse`

##### Response Shape

```json
[
  {
    "id": "hunger-station",
    "name": "Hunger Station",
    "logo_url": "https://example.com/hunger-station.png",
    "enabled": true
  },
  {
    "id": "delivery-hero",
    "name": "Delivery Hero",
    "logo_url": "https://example.com/delivery-hero.png",
    "enabled": false
  }
]
```

---

## 5. Add Aggregator

#### POST `/branch-manager/settings/aggregators`

Adds an aggregator to the branch.

- **Request Body:** `BranchManagerAggregatorRequest`
- **Response Object:** `BranchManagerAggregatorResponse`

##### Request Body

| Field         | Type   | Required | Rules                           |
| ------------- | ------ | -------- | ------------------------------- |
| aggregator_id | string | required | Existing aggregator identifier. |

##### Request Shape

```json
{
  "aggregator_id": "jahez"
}
```

##### Response Shape

```json
{
  "id": "jahez",
  "name": "Jahez",
  "logo_url": "https://example.com/jahez.png",
  "enabled": true
}
```

---

## 6. Delete Aggregator

#### DELETE `/branch-manager/settings/aggregators/{aggregatorId}`

Removes an aggregator from the branch.

- **Path Parameters:** `aggregatorId` (string, required)
- **Response Object:** `BranchManagerAggregatorResponse`

##### Response Shape

```json
{
  "id": "hunger-station",
  "name": "Hunger Station",
  "logo_url": "https://example.com/hunger-station.png",
  "enabled": true
}
```

---

## 7. Set Aggregator Enabled

#### PATCH `/branch-manager/settings/aggregators/{aggregatorId}/status`

Updates the enabled state of an assigned aggregator.

- **Path Parameters:** `aggregatorId` (string, required)
- **Request Body:** `BranchManagerAggregatorStatusRequest`
- **Response Object:** `BranchManagerAggregatorResponse`

##### Request Body

| Field   | Type    | Required | Rules                                 |
| ------- | ------- | -------- | ------------------------------------- |
| enabled | boolean | required | `true` to enable, `false` to disable. |

##### Request Shape

```json
{
  "enabled": true
}
```

##### Response Shape

```json
{
  "id": "hunger-station",
  "name": "Hunger Station",
  "logo_url": "https://example.com/hunger-station.png",
  "enabled": true
}
```

---

## 8. Update Shift Variance Alerts

#### PATCH `/branch-manager/settings/notifications/shift-variance-alerts`

Updates the shift variance alerts toggle.

- **Request Body:** `BranchManagerNotificationRequest`
- **Response Object:** `BranchManagerNotificationResponse`

##### Request Shape

```json
{
  "enabled": true
}
```

##### Response Shape

```json
{
  "type": "shiftVarianceAlerts",
  "title": "Shift Variance Alerts",
  "description": "Get notified whenever a handover report shows sales or payment discrepancies.",
  "enabled": true
}
```

---

## 9. Update Daily Inventory Reminders

#### PATCH `/branch-manager/settings/notifications/daily-inventory-reminders`

Updates the daily inventory reminders toggle.

- **Request Body:** `BranchManagerNotificationRequest`
- **Response Object:** `BranchManagerNotificationResponse`

##### Request Shape

```json
{
  "enabled": false
}
```

---

## 10. Update Approved Aggregators Only

#### PATCH `/branch-manager/settings/notifications/approved-aggregators-only`

Updates the approved aggregators only toggle.

- **Request Body:** `BranchManagerNotificationRequest`
- **Response Object:** `BranchManagerNotificationResponse`

---

## 11. Update Asset Transfer Requests

#### PATCH `/branch-manager/settings/notifications/asset-transfer-requests`

Updates the asset transfer requests toggle.

- **Request Body:** `BranchManagerNotificationRequest`
- **Response Object:** `BranchManagerNotificationResponse`

---

## 12. Update Allow Split Shift Handovers

#### PATCH `/branch-manager/settings/notifications/allow-split-shift-handovers`

Updates the split shift handovers toggle.

- **Request Body:** `BranchManagerNotificationRequest`
- **Response Object:** `BranchManagerNotificationResponse`

##### Request Shape

```json
{
  "enabled": true
}
```

## 13. Reset My Password

#### POST `/branch-manager/settings/reset-password`

Resets the current branch manager password.
All Types of users can access this endpoint to reset their password by token.

- **Request Body:** `BranchManagerResetMyPasswordRequest`
- **Response Object:** `BranchManagerResetMyPasswordResponse`

##### Request Body

| Field                 | Type   | Required | Rules                  |
| --------------------- | ------ | -------- | ---------------------- |
| old_password          | string | required | Current password.      |
| password              | string | required | New password value.    |
| password_confirmation | string | required | Must match `password`. |

##### Request Shape

```json
{
  "old_password": "OldPassword123!",
  "password": "NewPassword123!",
  "password_confirmation": "NewPassword123!"
}
```

##### Response Shape

```json
{
  "message": "Password updated successfully"
}
```

---
