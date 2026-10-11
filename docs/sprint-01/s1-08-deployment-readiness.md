# S1-08 deployment readiness (decisions 2026-10-08)

S1-08 is not authorized for deployment by this document. Run these **read-only** checks against the intended environment before scheduling rollout. Do not run them against production from the correction task.

## D12 assigned manager preflight

Each branch must have one uniquely assigned active manager for manager-addressed handovers. Investigate any result from either query before rollout. Manager deactivation, branch transfer, and deletion must be blocked while pending or correctable-rejected handovers addressed to that manager remain open.

```sql
SELECT b.id AS branch_id, COUNT(bm.id) AS managers
FROM branches b
LEFT JOIN branch_managers bm
  ON bm.branch_id = b.id
 AND bm.status = 'active'
 AND bm.is_active = 1
 AND bm.deleted_at IS NULL
WHERE b.is_active = 1
GROUP BY b.id
HAVING COUNT(bm.id) <> 1;
```

```sql
SELECT h.id, h.handover_to_id, s.branch_id
FROM cashier_shift_handovers h
JOIN cashier_shifts cs ON cs.id = h.cashier_shift_id
JOIN shifts s ON s.id = cs.shift_id
LEFT JOIN branch_managers bm ON bm.id = h.handover_to_id
WHERE h.handover_to_type = 'branch_manager'
  AND h.status IN ('pending', 'rejected')
  AND NOT EXISTS (
    SELECT 1 FROM cashier_shift_handover_receipts r
    WHERE r.cashier_shift_handover_id = h.id
  )
  AND (
    bm.id IS NULL
    OR bm.status <> 'active'
    OR bm.is_active = 0
    OR bm.deleted_at IS NOT NULL
    OR bm.branch_id <> s.branch_id
  );
```

Before manager deactivation, branch transfer, or deletion, check for addressed open work:

```sql
SELECT bm.id AS branch_manager_id, h.id AS handover_id, h.status, s.branch_id
FROM branch_managers bm
JOIN cashier_shift_handovers h
  ON h.handover_to_type = 'branch_manager'
 AND h.handover_to_id = bm.id
JOIN cashier_shifts cs ON cs.id = h.cashier_shift_id
JOIN shifts s ON s.id = cs.shift_id
WHERE h.status IN ('pending', 'rejected')
  AND NOT EXISTS (
    SELECT 1 FROM cashier_shift_handover_receipts r
    WHERE r.cashier_shift_handover_id = h.id
  );
```

The query is read-only preflight evidence. Runtime mutation guards return `409 MANAGER_HAS_OPEN_HANDOVERS` when a manager lifecycle change would orphan these requests.

## S8-12 drain-before-deploy gate

Resolve every pending handover on the current system by confirmation or rejection. After enabling maintenance mode, run:

```sql
SELECT COUNT(*)
FROM cashier_shift_handovers
WHERE status = 'pending';
```

The required result is **0**. A nonzero result blocks deployment; postpone until pending requests are resolved. Normal deployment has **no backfill**. If old pending records cannot be resolved manually, stop and separately design an idempotent evidence-preserving backfill as a contingency.

## MySQL/MariaDB concurrency gate

SQLite transaction tests are not row-lock or deadlock proof. A deployment-equivalent MySQL/MariaDB run must cover:

- two confirmations of the same request;
- amount correction/rejection racing recipient confirmation;
- manager review racing actual recipient confirmation;
- report close/correction racing confirmation;
- opposing cashier-pair request orders;
- manager deactivation, branch transfer, or delete racing addressed handover creation and manager confirmation;
- manager close / cashier confirmation / new cashier shift creation in another branch; `lockCashierFinancialInputs` uses a date-range lock and InnoDB may lock rows from other branches;
- zero and duplicate active-manager recipient discovery returning 200 with valid cashier choices, while omitting an ambiguous/unavailable manager;
- an unaddressed manager attempting reject and amount correction, returning 403 `ONLY_ADDRESSED_RECIPIENT` without state or financial changes.
- S1-09: two same-key requests on the same command, including one on `/api` and one on `/api/v1`, returning one effect and one replayed response;
- S1-09 `transaction` mode: reservation lock plus business locks (reservation → workday → cashier shifts → request) under deadlock, including nested manual transactions, where MySQL rolls back the whole transaction rather than the savepoint.

Verify one receipt/effect, no duplicate custody or ledger rows, no partial financial state, deterministic final state, lifecycle guard behavior, and bounded safe handling of deadlocks. Production DDL impact for additive receipt migrations also needs review.

S8-09 additionally forbids release before S1-11 posts a self-declared shortage to the cashier personal ledger exactly once on final branch-manager liability approval. Receipt confirmation is never that posting point.
