# S1-08 deployment readiness (decisions 2026-10-08)

S1-08 is not authorized for deployment by this document. Run these **read-only** checks against the intended environment before scheduling rollout. Do not run them against production from the correction task.

## D12 assigned manager preflight

Each branch must have one uniquely assigned active manager for manager-addressed receipts and rejection. Investigate any result from either query before rollout.

```sql
SELECT branch_id, COUNT(*) AS managers
FROM branch_managers
WHERE is_active = 1 AND deleted_at IS NULL
GROUP BY branch_id
HAVING COUNT(*) > 1;
```

```sql
SELECT h.id, h.handover_to_id, s.branch_id
FROM cashier_shift_handovers h
JOIN cashier_shifts cs ON cs.id = h.cashier_shift_id
JOIN shifts s ON s.id = cs.shift_id
LEFT JOIN branch_managers bm ON bm.id = h.handover_to_id
WHERE h.handover_to_type = 'branch_manager'
  AND h.status = 'pending'
  AND (
    bm.id IS NULL
    OR bm.is_active = 0
    OR bm.deleted_at IS NOT NULL
    OR bm.branch_id <> s.branch_id
  );
```

## S8-12 drain-before-deploy gate

Resolve every pending handover on the current system by confirmation or rejection. After enabling maintenance mode, run:

```sql
SELECT COUNT(*)
FROM cashier_shift_handovers
WHERE status = 'pending';
```

The required result is **0**. A nonzero result blocks deployment; postpone until pending requests are resolved. Normal deployment has **no backfill**. If old pending records cannot be resolved manually, stop and separately design an idempotent evidence-preserving backfill as a contingency.

## MySQL/MariaDB concurrency gate

SQLite transaction tests are not row-lock or deadlock proof. A deployment-equivalent MySQL/MariaDB run must cover concurrent confirmation of one handover, correction racing confirmation, manager review racing recipient confirmation, close/report mutation racing confirmation, and opposing cashier-pair request orders. Verify one receipt/effect, no duplicate custody or ledger rows, no partial financial state, deterministic final state, and bounded safe handling of deadlocks. Production DDL impact for additive receipt migrations also needs review.

S8-09 additionally forbids release before S1-11 posts a self-declared shortage to the cashier personal ledger exactly once on final branch-manager liability approval. Receipt confirmation is never that posting point.
