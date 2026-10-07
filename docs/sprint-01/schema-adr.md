# S1-03: Minimal Schema and Compatibility Decision (ADR)

> **S1-03 status: Needs correction/review; not Accepted.** R01/R05/R07 findings remain unresolved. The proposed schema change and examples below are a published working ADR only; they are not approved implementation authority. Do not implement them until the ADR has been reviewed and corrected.

## 1. Reconcile Units (The Limited Adapter Pattern)
**Decision:** We will **NOT** modify existing database `decimal(12,2)` columns or apply a global SQL `UPDATE ... / 100` to legacy records.
**Implementation:** We will use a **Limited Adapter** at the system boundary (API Resources and Bridge Services).
- **Backend (Read):** `ShiftDetailResource` and `ShiftCloseService` will cast database `decimal` values to integer halalas exactly before returning the JSON payload to the dashboard:
  ```php
  'salesHalalas' => (int) round(((float) $this->sales_amount) * 100),
  ```
- **Backend (Write):** When receiving data from the dashboard (in halalas), the Request class or Service will perform the exact division before saving to the DB:
  ```php
  $shift->sales_amount = round(((int) $request->salesHalalas) / 100, 2);
  ```
This ensures the Database and Legacy Mobile App remain perfectly compatible (`SAR` floats), while the Dashboard receives the integer `halalas` it expects, isolated entirely within the DTO/Resource layer.

## 2. Additive Migrations (Targeted Additions)
Based on `ASSAB_DATABASE_SCHEMA.md`, the `cashier_shift_handovers` table lacks fields to properly satisfy Mohamed's rules (preserving prior values on rejection, receipt tracking, and idempotency).

**Required Additive Migration:** We will create a new migration (e.g., `2026_10_xx_add_tracking_fields_to_cashier_shift_handovers.php`) to append the following:

```php
Schema::table('cashier_shift_handovers', function (Blueprint $table) {
    // 1. Revisions: To preserve prior values when a cashier resubmits after rejection
    $table->unsignedInteger('revision_number')->default(1)->after('id');

    // 2. Receipt References: Physical or digital receipt tracking for the handover
    $table->string('receipt_reference', 120)->nullable()->after('handover_notes');

    // 3. Financial-operation identity: Tie the handover to the centralized operations ledger
    $table->uuid('operation_id')->nullable()->after('receipt_reference');

    // 4. Idempotency: Prevent duplicate submissions (double-clicks) over unstable networks
    $table->string('idempotency_key', 64)->nullable()->after('operation_id');

    // Unique constraint ensuring we don't have duplicate revisions for the same shift handover
    $table->unique(['cashier_shift_id', 'revision_number'], 'uk_shift_handover_revision');
    // Unique idempotency per shift to prevent duplicate creations
    $table->unique(['cashier_shift_id', 'idempotency_key'], 'uk_shift_handover_idempotency');
});
```

## 3. Prohibited Actions (محاذير صارمة)
To guarantee system stability during Sprint 01, the following actions are **strictly prohibited**:
1. **يُمنع تعديل الـ migrations المُطبقة مسبقاً:** Do not edit, delete, or rewrite any existing historical migration files. Any database changes must be done strictly via new additive (forward) migrations.
2. **يُمنع قسمة السجلات عالمياً على 100:** No global `UPDATE` queries or DB-level triggers should divide historical or existing money records by 100. The conversion happens *only* at the API serialization boundary.
3. **يُمنع ملء الاستلامات المؤكدة بأثر رجعي:** Confirmed receipts (الاستلامات المؤكدة) must never be backfilled or altered retroactively based on global settings changes or resubmissions. A confirmed handover amount is immutable.

## 4. Migration & Verification Plan
**Decision:** **Change** (via additive migration only).
- **Before/After Counts:** We will count `SELECT COUNT(*) FROM cashier_shift_handovers` before and after. The count should remain identical immediately after the migration, as we are only adding nullable/default columns.
- **Isolated Rollback:** The new migration will contain a `down()` method that safely drops the unique constraints `uk_shift_handover_revision` / `uk_shift_handover_idempotency` and the 4 specific columns (`revision_number`, `receipt_reference`, `operation_id`, `idempotency_key`), leaving legacy data untouched.
- **Resumable Backfill:** No massive data backfill is required because `revision_number` defaults to `1` (which accurately represents all existing single-pass legacy handovers). `idempotency_key` and `receipt_reference` can safely remain `null` for historical data.
- **Testing Compatibility:** We will run existing baseline tests (e.g., `ShiftCloseChainTest` and `SalesVarianceAllocationTest`) using the existing fixtures. Since we haven't touched the `decimal` fields or legacy required columns, the legacy tests will pass without modification.
