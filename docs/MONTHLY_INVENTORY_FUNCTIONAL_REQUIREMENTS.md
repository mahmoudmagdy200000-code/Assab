# Monthly Inventory – Functional Requirements Specification

**Document Version:** 1.0  
**Author:** Senior Systems Analyst  
**Module:** Inventory  
**Feature:** Monthly Regular Inventory  
**Last Updated:** 2025-01-25  

---

## 1. Introduction

### 1.1 Purpose

This document defines the **functional requirements** for the **Monthly Regular Inventory** feature within the Assab Inventory module. It specifies user roles, user requirements, feature requirements, system flows, and inputs/outputs to serve as the authoritative reference for development, QA, and stakeholders.

### 1.2 Scope

- **In scope:** Setup, team assignment, product counting, progress tracking, save/review, approval workflow, export, month-over-month comparison, timelines, and management feedback for monthly inventory.
- **Out of scope:** Daily Quick Inventory (existing), real-time collaboration (e.g. WebSockets), and integration with external ERP systems.

### 1.3 Definitions

| Term | Definition |
|------|------------|
| Monthly Inventory | A branch-level, periodic inventory count performed by a team, with approval workflow and reporting. |
| Team Leader | Branch Manager who creates the inventory and leads the count. |
| Staff | Cashiers (or other branch personnel) assigned to perform the count. |
| Pending Products | Products that have not yet been counted in the current session. |
| Completed Products | Products for which a quantity has been recorded. |

---

## 2. User Roles and Personas

### 2.1 User Roles

| Role | Identifier | Description |
|------|------------|-------------|
| **Branch Manager** | `branch_manager` | Creates and owns monthly inventories, assigns team, submits for approval, corrects when returned, exports reports. |
| **Staff (Cashier)** | `cashier` | Performs product counting, claims/releases products, updates quantities. Assigned by Branch Manager. |
| **Finance / Management** | `finance` (placeholder) | Reviews submitted inventories, approves or returns to draft with feedback. |

### 2.2 User–Feature Matrix

| Feature | Branch Manager | Staff (Cashier) | Finance |
|---------|----------------|-----------------|---------|
| Create & start monthly inventory | ✓ | — | — |
| Assign team (incl. self as leader) | ✓ | — | — |
| View setup info, staff options, last team | ✓ | ✓ (limited) | — |
| View inventory list (by status) | ✓ | ✓ (assigned only) | ✓ |
| Count products (update quantity) | ✓ | ✓ | — |
| Claim / release product | ✓ | ✓ | — |
| Save progress | ✓ | ✓ | — |
| Review inventory | ✓ | ✓ | — |
| Submit for approval | ✓ | — | — |
| Approve inventory | — | — | ✓ |
| Return to draft (with feedback) | — | — | ✓ |
| Correct & re-submit (after return) | ✓ | — | — |
| Export report (PDF/Excel) | ✓ | ✓ (view) | ✓ |
| View timelines & feedback | ✓ | ✓ | ✓ |
| View monthly comparison | ✓ | ✓ | ✓ |

---

## 3. User Requirements

### 3.1 Branch Manager

| ID | Requirement | Priority |
|----|-------------|----------|
| U-BM-01 | I can create a new Monthly Regular Inventory and set the inventory date. | Must |
| U-BM-02 | I can assign myself as Team Leader and select at least three staff members. | Must |
| U-BM-03 | I can use “Use Same Team” to reuse the last inventory’s team. | Should |
| U-BM-04 | I can view inventory date, number of products, and expected time before starting. | Must |
| U-BM-05 | I can start the inventory and begin counting. | Must |
| U-BM-06 | I can view my monthly inventories filtered by In Progress, Draft, or Completed. | Must |
| U-BM-07 | I can save progress and move an in-progress inventory to Draft. | Must |
| U-BM-08 | I can review the inventory when all products are counted and proceed to submit. | Must |
| U-BM-09 | I can submit the inventory for approval (“Send To Management”). | Must |
| U-BM-10 | I can export the monthly inventory report as PDF or Excel. | Must |
| U-BM-11 | When finance returns the inventory, I can view feedback, correct, and re-submit. | Must |
| U-BM-12 | I can view details, timelines, and status for any of my inventories. | Must |

### 3.2 Staff (Cashier)

| ID | Requirement | Priority |
|----|-------------|----------|
| U-ST-01 | I can view pending products and my assigned inventory. | Must |
| U-ST-02 | I can enter quantity for a product (simple input or slider-style for volume). | Must |
| U-ST-03 | I can claim a product so others see “I am handling it.” | Should |
| U-ST-04 | I can release a product I claimed. | Should |
| U-ST-05 | I can save progress during the count. | Must |
| U-ST-06 | I can view completed products and edit my counts while the inventory is open. | Must |
| U-ST-07 | I can view inventory summary (date, products, expected time). | Must |

### 3.3 Finance / Management

| ID | Requirement | Priority |
|----|-------------|----------|
| U-FN-01 | I can view submitted monthly inventories for review. | Must |
| U-FN-02 | I can approve an inventory to finalize it. | Must |
| U-FN-03 | I can return an inventory to draft with written feedback. | Must |
| U-FN-04 | I can view full report, team performance, and value breakdown. | Must |

---

## 4. Feature Requirements

### 4.1 Setup & Start

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-01 | Setup information | System displays date, number of products, expected time. | Date = inventory date; product count from branch’s closed PO items; expected time derived from product count (e.g. 45–60 min). |
| F-02 | Staff options | System provides a list of available staff for selection. | Branch Cashiers (and optionally Branch Managers), excluding current user when acting as team leader. |
| F-03 | Last team suggestion | System suggests the last inventory’s team. | “Use Same Team” fills staff selections with previous team members. |
| F-04 | Create & start inventory | User creates monthly inventory with selected team and starts count. | Inventory created; status = In Progress; team stored; products initialized from closed PO items with quantity 0. |

### 4.2 Counting

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-05 | Pending products list | User sees products to be counted, with search/filter. | List shows product name, unit, quantity field; search by name; pagination. |
| F-06 | Simple quantity input | User enters counted quantity (numeric). | Quantity stored; product moves to “completed” when saved. |
| F-07 | Slider / volume count | For applicable products, user enters full containers, volume per container, and partial %. | System computes total (e.g. liters); stored as quantity plus optional metadata. |
| F-08 | Product locking | User can claim a product; others see “X is handling it.” | Claim sets handled_by, locked_at; release clears them; only handler (or team leader) can update. |
| F-09 | Update quantity | User can update quantity for completed products while inventory is open. | Updates allowed only when status is In Progress or Draft; validated against unit. |

### 4.3 Progress & Save

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-10 | Progress display | System shows elapsed time, completed/total products, progress bar. | Timer from start_time; completed = products with quantity recorded; total = all products. |
| F-11 | Completed products list | User sees counted products with quantity and unit; searchable. | List supports search by name; shows quantity, unit, edit option. |
| F-12 | Save progress | User saves current state. | All counts persisted; optional transition to Draft; success message. |
| F-13 | Review inventory | User marks inventory as ready for review. | All products must be counted; status → Completed; “Review” enabled. |

### 4.4 Approval Workflow

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-14 | Submit for approval | Branch Manager submits counted inventory to management/finance. | Status → Submitted; inventory locked for staff edits; timeline recorded. |
| F-15 | Approve | Finance approves the inventory. | Status → Approved; becomes official record; timeline updated. |
| F-16 | Return to draft | Finance returns inventory with feedback. | Status → Returned to Draft; feedback stored; only Branch Manager can edit and re-submit. |
| F-17 | Re-submit | Branch Manager corrects and submits again. | Same rules as F-14; timeline records re-submission. |

### 4.5 Reporting & Export

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-18 | Full report | System provides summary, team contributions, value, categories. | Products complete, time taken, participants; per-person contribution; total value; value by category. |
| F-19 | Export PDF | User exports report as PDF. | PDF generated (e.g. via job); download link or stream. |
| F-20 | Export Excel | User exports report as Excel. | Excel generated (e.g. via job); download link or stream. |

### 4.6 Comparison & History

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-21 | Monthly comparison | User views this month vs previous month by product. | For each product: current qty, previous qty, % change; “Not In Current Report” when applicable. |
| F-22 | Timelines | User views event history (status changes, submissions, feedback). | List of events with actor, title, description, timestamp. |
| F-23 | Management feedback | User views feedback when inventory is returned. | Author, message, date; displayed prominently when action required. |

### 4.7 List & Detail

| ID | Feature | Description | Acceptance Criteria |
|----|---------|-------------|---------------------|
| F-24 | Inventory list | User sees monthly inventories filtered by status. | Tabs: In Progress, Draft, Completed; each card shows date, start time, team, status. |
| F-25 | Inventory detail | User sees full details, summary, team, products. | Details + Timelines tabs; status card; summary metrics; team contribution; product list. |

---

## 5. System Flows

### 5.1 Flow 1: Setup and Start Inventory

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | Branch Manager | Opens “Monthly Regular Inventory Setup” | — | — | — |
| 2 | System | Loads setup info | — | `branch_id` (from auth) | Date, number_of_products, expected_time |
| 3 | System | Loads staff options | — | `branch_id` | List of staff (id, name, ...) |
| 4 | System | Loads last team | — | `branch_id` | Last team member IDs (for “Use Same Team”) |
| 5 | Branch Manager | Selects team (min 3 staff), optionally “Use Same Team” | — | — | — |
| 6 | Branch Manager | Clicks “Start Inventory” | Create monthly inventory | `inventory_date`, `staff[]` | — |
| 7 | System | Validates, creates inventory, initializes products | — | Validated request | `monthly_inventory` (id, status=in_progress, ...) |
| 8 | System | Redirects / returns success | — | — | Inventory id, success message |

**Inputs (API):**

- `GET /inventory/monthly/setup-info`  
  - Input: none (branch from auth)  
  - Output: `{ date, number_of_products, expected_time }`

- `GET /inventory/monthly/staff-options`  
  - Input: none  
  - Output: `[{ id, name, ... }]`

- `GET /inventory/monthly/last-team`  
  - Input: none  
  - Output: `[{ id, name, ... }]`

- `POST /inventory/monthly`  
  - Input: `{ inventory_date, staff: [uuid, ...] }`  
  - Output: `{ id, status, branch, team, ... }`

---

### 5.2 Flow 2: Count Products and Save Progress

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | Staff / BM | Opens “Pending Products” | — | — | — |
| 2 | System | Returns pending products | — | `inventory_id`, optional `search` | List of products (id, name, unit, quantity, handling_info) |
| 3 | User | Optionally claims product | Claim | `inventory_id`, `product_id` | — |
| 4 | System | Sets handled_by, locked_at | — | — | Success |
| 5 | User | Enters quantity (simple or slider) | Update quantity | `inventory_id`, `product_id`, `quantity`, optional `count_metadata` | — |
| 6 | System | Validates, updates product | — | Validated input | Updated product |
| 7 | User | Clicks “Save Progress” | Save | `inventory_id` | — |
| 8 | System | Persists all, optionally sets draft | — | — | Success message |

**Inputs (API):**

- `GET /inventory/monthly/{id}/products?search=`  
  - Input: `id`, optional `search`  
  - Output: `{ pending: [...], completed: [...] }` or paginated list

- `POST /inventory/monthly/{id}/products/{productId}/claim`  
  - Input: —  
  - Output: `{ success }`

- `POST /inventory/monthly/{id}/products/{productId}/release`  
  - Input: —  
  - Output: `{ success }`

- `PUT /inventory/monthly/{id}/products/{productId}`  
  - Input: `{ quantity_inventory, count_method?, count_metadata? }`  
  - Output: `{ product }`

- `POST /inventory/monthly/{id}/save-progress`  
  - Input: optional `{ move_to_draft: true }`  
  - Output: `{ success, message }`

---

### 5.3 Flow 3: Review and Submit for Approval

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | Branch Manager | Opens inventory, sees “Review Inventory” enabled | — | — | — |
| 2 | System | Ensures all products counted | — | `inventory_id` | Progress (completed/total, etc.) |
| 3 | User | Clicks “Review Inventory” | Review | `inventory_id` | — |
| 4 | System | Sets status = completed | — | — | Success |
| 5 | User | Views “Inventory Complete” summary | — | — | Summary, team contribution, products |
| 6 | User | Clicks “Send To Management” (after Approve confirmation) | Submit | `inventory_id` | — |
| 7 | System | Validates, sets submitted, locks edits, records timeline | — | — | Success |

**Inputs (API):**

- `GET /inventory/monthly/{id}/progress`  
  - Input: `id`  
  - Output: `{ elapsed, completed, total, completed_products[] }`

- `POST /inventory/monthly/{id}/review`  
  - Input: —  
  - Output: `{ success }`

- `POST /inventory/monthly/{id}/submit`  
  - Input: —  
  - Output: `{ success }`

---

### 5.4 Flow 4: Approve or Return to Draft (Finance)

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | Finance | Views submitted inventory | — | — | Detail, report summary |
| 2a | Finance | Clicks “Approve” | Approve | `inventory_id` | — |
| 3a | System | Sets status = approved, timeline | — | — | Success |
| 2b | Finance | Clicks “Return to Draft”, enters feedback | Return | `inventory_id`, `feedback` | — |
| 3b | System | Sets status = returned_to_draft, stores feedback, timeline | — | — | Success |
| 4 | Branch Manager | Sees “Issue Raised”, reads feedback | — | — | Feedback (author, message, date) |
| 5 | Branch Manager | Corrects and re-submits | Submit | — | Same as Flow 3 |

**Inputs (API):**

- `POST /inventory/monthly/{id}/approve`  
  - Input: —  
  - Output: `{ success }`

- `POST /inventory/monthly/{id}/return-to-draft`  
  - Input: `{ feedback: string }`  
  - Output: `{ success }`

- `GET /inventory/monthly/{id}/feedback`  
  - Input: `id`  
  - Output: `[{ author, message, created_at }]`

---

### 5.5 Flow 5: Export Report

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | User | Clicks “Export Report” | — | — | — |
| 2 | System | Shows format options (PDF, Excel) | — | — | — |
| 3 | User | Selects format, clicks “Export Now” | Export | `inventory_id`, `format` | — |
| 4 | System | Queues job, generates file | — | — | — |
| 5 | System | Returns download link or stream | — | — | `{ download_url }` or file |

**Inputs (API):**

- `POST /inventory/monthly/{id}/export`  
  - Input: `{ format: "pdf" | "excel" }`  
  - Output: `{ download_url }` or stream

---

### 5.6 Flow 6: View List and Detail

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | User | Opens “Monthly Inventory” list | — | — | — |
| 2 | System | Returns list by status | — | `status`, `page` | Paginated list (id, date, team, status, ...) |
| 3 | User | Selects tab (In Progress / Draft / Completed) | — | `status` | Filtered list |
| 4 | User | Clicks an inventory | — | — | — |
| 5 | System | Returns detail | — | `id` | Detail, summary, team, products, timelines |

**Inputs (API):**

- `GET /inventory/monthly?status=&page=`  
  - Input: `status`, `page`, `per_page`  
  - Output: `{ data: [...], meta: { ... } }`

- `GET /inventory/monthly/{id}`  
  - Input: `id`  
  - Output: `{ inventory, summary, team, products, ... }`

- `GET /inventory/monthly/{id}/timelines`  
  - Input: `id`  
  - Output: `[{ event_type, actor, title, description, occurred_at }]`

---

### 5.7 Flow 7: Monthly Comparison

| Step | Actor | Action | System | Input | Output |
|------|-------|--------|--------|-------|--------|
| 1 | User | Opens “Monthly Inventory Comparison” | — | — | — |
| 2 | System | Loads comparison data | — | `branch_id`, `year`, `month` | This month vs previous |
| 3 | User | Toggles “This Month” / “Previous Month” | — | — | Same data, UI emphasis changes |
| 4 | System | Returns per-product comparison | — | — | `[{ product, current_qty, previous_qty, change_pct, not_in_current }]` |

**Inputs (API):**

- `GET /inventory/monthly/comparison?branch_id=&year=&month=`  
  - Input: `branch_id`, `year`, `month`  
  - Output: `{ items: [...], period }`

---

## 6. Business Rules

| ID | Rule | Applies To |
|----|------|------------|
| BR-01 | Team must include at least one Team Leader (Branch Manager) and at least three Staff. | Create |
| BR-02 | Product list is sourced from closed purchase order items for the branch. | Setup, Products |
| BR-03 | Only the user who claimed a product (or Team Leader) can update its quantity while locked. | Count |
| BR-04 | “Review Inventory” is allowed only when all products have a recorded quantity. | Review |
| BR-05 | After “Submit”, staff cannot edit counts; only Branch Manager can edit after “Return to Draft”. | Workflow |
| BR-06 | Expected time is computed from product count (e.g. configurable formula). | Setup |
| BR-07 | Total inventory value = sum over (quantity × unit_price) per product; unit_price from PO item or branch. | Report |
| BR-08 | Month-over-month % change = ((current − previous) / previous) × 100; “Not In Current Report” when previous has no current match. | Comparison |

---

## 7. Data Requirements (High-Level)

- **Monthly inventory:** branch, creator, date, start/end time, status, product count, expected time, submitted_at, approved_at.
- **Team:** inventory–user association with role (team_leader, staff).
- **Products:** inventory–product linkage, quantity, unit, unit_price, category, optional count metadata, handled_by, locked_at.
- **Timelines:** event type, actor, title, description, occurred_at.
- **Feedback:** author, message, created_at.

---

## 8. Traceability

- User requirements (Section 3) map to feature requirements (Section 4) and system flows (Section 5).
- Features map to API endpoints and service operations in the technical design.
- Business rules (Section 6) apply across flows and must be enforced by the implementation.

---

## 9. Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2025-01-25 | Senior Systems Analyst | Initial release |
