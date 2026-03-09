# Feature Specification: Recurring Order Scheduled Execution (3.1.2.6.1.1)

**Feature Branch**: `003-recurring-order-scheduled-execution`  
**Created**: 2026-03-09  
**Status**: Draft  
**Input**: User description: "3.1.2.6.1.1 Create and activate a new recurring order. System will send requests per Repeat Frequency and Scheduled Time. Notification options and Smart Settings apply. Recurring orders do not execute at the scheduled time (مش بيجي والاوردر مش بيتعمل في المعاد الي بكون محدده)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Recurring Order Executes at Scheduled Time (Priority: P1)

As a branch manager, when I create and activate a recurring order with a repeat frequency and scheduled time, the system must create and send the order at that scheduled time so that the order appears in the correct list (e.g. in progress when due) and is actionable by the purchasing officer.

**Why this priority**: Without execution at the scheduled time, recurring orders provide no value; this is the core fix for the reported issue.

**Independent Test**: Create a recurring order with a near-future scheduled time (e.g. in 2 minutes), wait until that time passes, then verify the order appears in the in-progress list (or equivalent) and an actual order request exists.

**Acceptance Scenarios**:

1. **Given** an active recurring order with next schedule date = today and a defined scheduled time, **When** that scheduled time is reached, **Then** the system creates the order request and the recurring order moves to (or appears in) the in-progress list.
2. **Given** an active recurring order with repeat frequency (e.g. weekly) and scheduled time, **When** each occurrence date and time is reached, **Then** the system creates an order request for that occurrence.
3. **Given** a recurring order that has been created and activated via the API, **When** the next schedule date and scheduled time arrive, **Then** the system triggers execution so the order is no longer only in the "next scheduling" list and is visible as in progress or completed for that occurrence.

---

### User Story 2 - Notifications and Smart Settings Apply on Execution (Priority: P2)

As a branch manager, when a recurring order is triggered at its scheduled time, I expect the notification options and Smart Settings I configured to be applied (e.g. propagated to the created order request so notifications and smart rules can be used; actual notification delivery may be handled downstream) so that stakeholders are informed and business rules are followed.

**Why this priority**: Ensures the full 3.1.2.6.1.1 behavior (not just creation) is correct once execution runs on time.

**Independent Test**: Create a recurring order with notifications and Smart Settings enabled, set a near-future schedule, and after execution verify that notifications were sent and Smart Settings were applied as configured.

**Acceptance Scenarios**:

1. **Given** a recurring order with notification options configured, **When** the order is triggered at the scheduled time, **Then** the system applies them to the created order request (e.g. stores notification_options and uses notification_channels so notifications can be sent).
2. **Given** a recurring order with Smart Settings configured, **When** the order is triggered at the scheduled time, **Then** the system applies those settings to the created order request (e.g. stores smart_settings on the purchase order for downstream use).

---

### User Story 3 - Paused and Inactive Orders Do Not Run (Priority: P3)

As a branch manager, when I pause a recurring order or it is inactive, the system must not create orders at the scheduled time so that I retain control over when recurring orders run.

**Why this priority**: Prevents incorrect execution and maintains data consistency.

**Independent Test**: Pause a recurring order that would be due soon; after the scheduled time passes, verify no order was created for that occurrence.

**Acceptance Scenarios**:

1. **Given** a recurring order in paused status, **When** the scheduled time is reached, **Then** the system does not create an order for that occurrence.
2. **Given** a recurring order that is not active, **When** the scheduled time is reached, **Then** the system does not create an order.

---

### Edge Cases

- What happens when multiple recurring orders are due at the same time? The system should process each due order without losing any; order of processing may be undefined as long as all are handled.
- How does the system handle the scheduled time when the server or scheduler is offline? The system should either run missed occurrences on next run (catch-up) or skip and use the next scheduled occurrence; behavior must be consistent and documented.
- What happens when the next schedule date is in the past (e.g. after a long outage)? The system should either skip past dates and use the next future date or run catch-up for a bounded period; the rule must be clear and consistent.
- How are time zones handled for "scheduled time"? Scheduled time is interpreted in a defined time zone (e.g. branch or server); users must be able to rely on the same time every day/week as configured.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST create and send order requests for active recurring orders when their scheduled date and scheduled time are reached, according to the configured repeat frequency.
- **FR-002**: The system MUST use the recurring order’s Repeat Frequency and Scheduled Time to determine when to trigger each occurrence; execution MUST occur at the defined time (within an acceptable window, e.g. same minute or same 5-minute window).
- **FR-003**: When a recurring order is triggered at the scheduled time, the system MUST update its state so that the order appears in the correct list (e.g. in progress for that occurrence) and is no longer only in the "next scheduling" list for that occurrence.
- **FR-004**: The system MUST apply the configured notification options when a recurring order is triggered at the scheduled time.
- **FR-005**: The system MUST apply the configured Smart Settings when creating the order request for a recurring order at the scheduled time.
- **FR-006**: The system MUST NOT trigger recurring orders that are paused or inactive at the scheduled time.
- **FR-007**: The system MUST compute and persist the next schedule date (and time) for each recurring order after each execution so that the next occurrence is clearly defined and used for "next scheduling" and future runs.

### Key Entities

- **Recurring order**: The template/definition for repeated orders; includes order name, type, status (e.g. active, paused, pending), repeat frequency, scheduled time, next schedule date, notification options, and Smart Settings.
- **Order request (per occurrence)**: The concrete order created when a recurring order is triggered at a scheduled time; linked to the recurring order and subject to notification and Smart Settings.
- **Next schedule date / scheduled time**: The date and time at which the next occurrence of a recurring order should run; used by the scheduler to decide when to execute.

## Assumptions

- "Scheduled time" is a time-of-day (and possibly day-of-week/month) value stored with the recurring order; the exact interpretation (e.g. single daily time vs. day-specific) follows existing product behavior.
- The system has or will have a scheduling mechanism that runs at least as often as the smallest repeat frequency granularity so that scheduled times can be honored within a reasonable window.
- "In progress" and "next scheduling" lists are defined by existing business rules; this feature ensures that when execution runs, the recurring order and created orders move or appear in those lists correctly.
- Smart Settings and notification options are already defined elsewhere; this feature only requires that they are applied at execution time, not re-specified here.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Recurring orders that are active and due run at the scheduled time: at least 95% of due recurring orders have their order request created within the agreed time window (e.g. within 5 minutes of the scheduled time) under normal system load.
- **SC-002**: Users can rely on lists: when a recurring order’s scheduled time has passed, it appears in the in-progress (or equivalent) list and not only in next scheduling for that occurrence, so users see that the order was executed.
- **SC-003**: Notifications and Smart Settings are applied for executed recurring orders: 100% of triggered recurring orders have their configured notification options and Smart Settings applied to the created order request.
- **SC-004**: No unintended execution: paused or inactive recurring orders do not generate order requests at the scheduled time (zero false triggers).
