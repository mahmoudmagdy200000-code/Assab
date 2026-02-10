# Recurring Orders API (3.1.2.6)

## OpenAPI for ApiDog / Postman

- **File:** `recurring-orders-openapi.json` (OpenAPI 3.0)
- **Import in ApiDog:** Project → Import → OpenAPI → Upload `recurring-orders-openapi.json`
- **Base URL:** Set in ApiDog to your API root (e.g. `https://your-domain.com/api/v1/recurring-orders` or use server variable).
- **Auth:** Bearer token (Branch Manager Sanctum). Add in ApiDog as Bearer Token auth.

## Requirements Coverage

| Requirement | Implementation |
|-------------|----------------|
| 3.1.2.6.1 In Progress List | `GET /in-progress` – Order Name, Type (Direct Supplier / Via PO), Status (Generated, In Progress) |
| 3.1.2.6.1.1 Add Recurring Orders | `GET /suppliers`, `GET /purchasing-officers`, `POST /` with full body (items, repeat config, notifications, smart settings, schedule) |
| 3.1.2.6.1.2 View Details | `GET /{id}` – Status, Inspection Summary, Item Count & Availability, Details, History, Available Actions |
| 3.1.2.6.2 Next Scheduling List | `GET /next-scheduling` – Pending with next order message |
| 3.1.2.6.2.1 View Details | Same as 3.1.2.6.1.2 |
| 3.1.2.6.3 Paused List | `GET /paused` |
| 3.1.2.6.3.1 View Details | Same + Resume action |
| Pause / Resume / Update / Delete | `POST /{id}/pause`, `POST /{id}/resume`, `PUT /{id}`, `DELETE /{id}` |
| History | In detail response: `total_completed_orders`, `total_canceled_orders`, `list` (name, image, order_status: Completed/Canceled) |

## Scheduler & Job

- **Job:** `Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob`
- **Schedule:** Every 5 minutes (`bootstrap/app.php`). Creates purchase orders from recurring orders when `next_run_at <= now()`, sets status to Generated, and computes next run.
- **Listener:** When a purchase order linked to a recurring order is closed/canceled/rejected, the recurring order is set back to Pending and `next_run_at` is recomputed. When the PO is confirmed, recurring order status is set to In Progress.
