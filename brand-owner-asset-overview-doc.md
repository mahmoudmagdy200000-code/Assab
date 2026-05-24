# Brand Owner Asset Overview — Backend Specification

---

## Table of Contents

1. [Overview](#1-overview)
2. [Get Overview](#2-get-overview)
3. [Get Performance Summary](#3-get-performance-summary)
4. [Get Branch Details](#4-get-branch-details)
5. [Export Report](#5-export-report)
6. [Get Control Panel](#6-get-control-panel)

---

## 1. Overview

This document defines the full backend contract for the **Brand Owner Asset Overview** module. It provides a dashboard-level view of all branches and their fixed assets within the brand owner's network.

**General rules:**

- No pagination on any endpoint.
- All timestamps are string-formatted (no ISO 8601 constraint in this module).
- All `GET` endpoints return `200 OK` with the resource in a `data` wrapper.
- The `format` field in the export endpoint is a free-text `String` — no enum.

| #   | Endpoint                                          | Method | Description                                                  |
| --- | ------------------------------------------------- | ------ | ------------------------------------------------------------ |
| 1   | `/brand-owner/asset-overview`                     | GET    | Full dashboard: branches, network stats, performance summary |
| 2   | `/brand-owner/asset-overview/performance-summary` | GET    | Performance metrics only                                     |
| 3   | `/brand-owner/asset-overview/branch/{branchId}`   | GET    | Single branch with asset list                                |
| 4   | `/brand-owner/asset-overview/export`              | POST   | Export a branch report                                       |
| 5   | `/brand-owner/control-panel`                      | GET    | Control panel dashboard: overview, branch perf, summary      |

---

## 2. Get Overview

```
GET /brand-owner/asset-overview
```

**Response 200:**

```json
{
  "data": {
    "branches": [ ... ],
    "network_statistics": { ... },
    "performance_summary": { ... }
  }
}
```

**Field notes — root:**

| Field                 | Type                       | Nullable | Default |
| --------------------- | -------------------------- | -------- | ------- |
| `branches`            | array of `BranchObject`    | no       | `[]`    |
| `network_statistics`  | `NetworkStatisticsObject`  | no       | —       |
| `performance_summary` | `PerformanceSummaryObject` | no       | —       |

### `BranchObject`

```json
{
  "id": "branch-1",
  "branch_name": "Jeddah Central",
  "manager_name": "Ahmed Al Harbi",
  "manager_image_url": null,
  "assets_count": 84,
  "excellent_count": 78,
  "attention_count": 4,
  "problem_count": 2,
  "assets": [ ... ]
}
```

| Field               | Type                   | Nullable | Default |
| ------------------- | ---------------------- | -------- | ------- |
| `id`                | string                 | no       | `""`    |
| `branch_name`       | string                 | no       | `""`    |
| `manager_name`      | string                 | no       | `""`    |
| `manager_image_url` | string                 | yes      | `null`  |
| `assets_count`      | int                    | no       | `0`     |
| `excellent_count`   | int                    | no       | `0`     |
| `attention_count`   | int                    | no       | `0`     |
| `problem_count`     | int                    | no       | `0`     |
| `assets`            | array of `AssetObject` | no       | `[]`    |

### `AssetObject`

```json
{
  "id": "asset-1",
  "name": "Espresso Machine",
  "code": "FA-ESP-101",
  "zone_name": "Beverage Area",
  "custodian_name": "Ahmed Al Harbi",
  "custody_duration": "9 Months",
  "last_audit_date": "Dec 20, 2025",
  "photo_date": "Today",
  "transfers_count": 2,
  "image_url": ""
}
```

| Field              | Type   | Nullable | Default |
| ------------------ | ------ | -------- | ------- |
| `id`               | string | no       | `""`    |
| `name`             | string | no       | `""`    |
| `code`             | string | no       | `""`    |
| `zone_name`        | string | no       | `""`    |
| `custodian_name`   | string | no       | `""`    |
| `custody_duration` | string | no       | `""`    |
| `last_audit_date`  | string | no       | `""`    |
| `photo_date`       | string | no       | `""`    |
| `transfers_count`  | int    | no       | `0`     |
| `image_url`        | string | no       | `""`    |

### `NetworkStatisticsObject`

```json
{
  "total_assets": 150,
  "excellent_status": "92%",
  "photo_accuracy": "85%",
  "average_asset_age": "2.3 Years"
}
```

| Field               | Type   | Nullable | Default |
| ------------------- | ------ | -------- | ------- |
| `total_assets`      | int    | no       | `0`     |
| `excellent_status`  | string | no       | `""`    |
| `photo_accuracy`    | string | no       | `""`    |
| `average_asset_age` | string | no       | `""`    |

### `PerformanceSummaryObject`

```json
{
  "assets_in_excellent_condition": 41,
  "excellent_percent": "98%",
  "photo_compliance": "88%",
  "average_resolution_time": "30:12 Mins",
  "last_audit_score": 95
}
```

| Field                           | Type   | Nullable | Default |
| ------------------------------- | ------ | -------- | ------- |
| `assets_in_excellent_condition` | int    | no       | `0`     |
| `excellent_percent`             | string | no       | `""`    |
| `photo_compliance`              | string | no       | `""`    |
| `average_resolution_time`       | string | no       | `""`    |
| `last_audit_score`              | int    | no       | `0`     |

---

## 3. Get Performance Summary

```
GET /brand-owner/asset-overview/performance-summary
```

Returns the same `PerformanceSummaryObject` described in [Section 2 — `PerformanceSummaryObject`](#networkstatisticsobject).

**Response 200:**

```json
{
  "data": {
    "assets_in_excellent_condition": 41,
    "excellent_percent": "98%",
    "photo_compliance": "88%",
    "average_resolution_time": "30:12 Mins",
    "last_audit_score": 95
  }
}
```

---

## 4. Get Branch Details

```
GET /brand-owner/asset-overview/branch/{branchId}
```

**Path Parameters:**

| Parameter  | Type   | Description                  |
| ---------- | ------ | ---------------------------- |
| `branchId` | string | ID of the branch to retrieve |

Returns the same `BranchObject` described in [Section 2 — `BranchObject`](#branchobject).

**Response 200:**

```json
{
  "data": {
    "id": "branch-1",
    "branch_name": "Jeddah Central",
    "manager_name": "Ahmed Al Harbi",
    "manager_image_url": null,
    "assets_count": 84,
    "excellent_count": 78,
    "attention_count": 4,
    "problem_count": 2,
    "assets": [
      {
        "id": "asset-1",
        "name": "Espresso Machine",
        "code": "FA-ESP-101",
        "zone_name": "Beverage Area",
        "custodian_name": "Ahmed Al Harbi",
        "custody_duration": "9 Months",
        "last_audit_date": "Dec 20, 2025",
        "photo_date": "Today",
        "transfers_count": 2,
        "image_url": ""
      }
    ]
  }
}
```

**Error 404:**

```json
{
  "error": "Branch not found: {branchId}"
}
```

---

## 5. Export Report

```
POST /brand-owner/asset-overview/export
```

**Request Body:**

```json
{
  "branch_id": "branch-1",
  "format": "PDF"
}
```

| Field       | Type   | Nullable | Default | Notes                                |
| ----------- | ------ | -------- | ------- | ------------------------------------ |
| `branch_id` | string | no       | `""`    | Target branch ID                     |
| `format`    | string | no       | `""`    | Supported values: `"PDF"`, `"Excel"` |

**Response 200:**

```json
{
  "data": {
    "branch_id": "branch-1",
    "branch_name": "Jeddah Central",
    "format": "PDF",
    "message": "Report exported successfully in PDF format."
  }
}
```

| Field         | Type   | Nullable | Default |
| ------------- | ------ | -------- | ------- |
| `branch_id`   | string | no       | `""`    |
| `branch_name` | string | no       | `""`    |
| `format`      | string | no       | `""`    |
| `message`     | string | no       | `""`    |

---

## 6. Get Control Panel

```
GET /brand-owner/control-panel
```

Returns the control panel dashboard data including strategic overview, branch performance rankings, and executive summary metrics.

**Response 200:**

```json
{
  "data": {
    "strategic_overview": {
      "total_chain_assets": 401,
      "excellent_status": "91%",
      "branches_count": 5,
      "total_investment": "4.1M",
      "critical_value_impact": "Warning"
    },
    "branch_performance": {
      "items": [
        {
          "name": "North",
          "percentage": "98%",
          "status": "Ideal"
        },
        {
          "name": "East",
          "percentage": "95%",
          "status": "Excellent"
        },
        {
          "name": "Central",
          "percentage": "89%",
          "status": "Good"
        },
        {
          "name": "South",
          "percentage": "87%",
          "status": "Needs Improvement"
        }
      ]
    },
    "executive_summary": {
      "photo_accuracy": "97%",
      "audit_completion": "4 of 5 Branches"
    }
  }
}
```

### `StrategicOverviewObject`

| Field                   | Type   | Nullable | Default |
| ----------------------- | ------ | -------- | ------- |
| `total_chain_assets`    | int    | no       | `0`     |
| `excellent_status`      | string | no       | `""`    |
| `branches_count`        | int    | no       | `0`     |
| `total_investment`      | string | no       | `""`    |
| `critical_value_impact` | string | no       | `""`    |

### `BranchPerformanceObject`

| Field   | Type                            | Nullable | Default |
| ------- | ------------------------------- | -------- | ------- |
| `items` | array of `BranchPerfItemObject` | no       | `[]`    |

### `BranchPerfItemObject`

| Field        | Type   | Nullable | Default |
| ------------ | ------ | -------- | ------- |
| `name`       | string | no       | `""`    |
| `percentage` | string | no       | `""`    |
| `status`     | string | no       | `""`    |

### `ExecutiveSummaryObject`

| Field              | Type   | Nullable | Default |
| ------------------ | ------ | -------- | ------- |
| `photo_accuracy`   | string | no       | `""`    |
| `audit_completion` | string | no       | `""`    |
