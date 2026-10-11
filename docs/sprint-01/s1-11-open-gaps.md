# S1-11 open gaps and carry-overs (2026-10-11)

OG-01..05 and OG-07..09 do not block S1-11 re-audit or S1-12 on safety grounds; each is absent or fails closed. **OG-06 is different:** the independent re-audit (RA-42-01) classed it as a source-demonstrated defect and a conditional P1 acceptance blocker until a read-only data census and an owner decision are recorded.

| ID | Gap | Class | Nature | Needs decision from | Blocks S1-11 | Blocks S1-12 |
|---|---|---|---|---|---|---|
| OG-01 | NEW-03 authorized cashier expenses | v3.2 carry-over | Feature absent | Mahmoud (schedule) | No | No |
| OG-02 | NEW-04 expense-custody tie to personal balance | v3.2 carry-over | Partial; depends on OG-01 | Mahmoud | No | No |
| OG-03 | NEW-05 dues repayment via manager handover | v3.2 carry-over | Feature absent | Mahmoud | No | No |
| OG-04 | NEW-06 return-to-branch-manager choreography after Head return | v3.2 carry-over | Partially evidenced; finality enforced | Mahmoud / Mohamed (scope) | No | No |
| OG-05 | NEW-07 receipt correction (reverse + re-record, linked) | v3.2 carry-over | Fail-closed (`SETTLEMENT_AUTHORITY_UNRESOLVED`). **Generic settlement writer deliberately NOT opened.** | Mohamed (authority), Mahmoud | No | No |
| OG-06 | Untracked (legacy, no `transfer_attempt`) rejection with D11 evidence can never be corrected through `handover/edit`: `PHYSICAL_RETURN_REQUIRED` is thrown but a return can only be recorded against an attempt. Observed while updating fixtures. Fail-closed (cash is never released), but such a request is stranded. Whether any such rows exist in real data is **NOT VERIFIED**. | Source-demonstrated defect (RA-42-01), P1 conditional | Open: needs a read-only census of rejected D11 handovers without an attempt in the target schema; any recovery must not invent cash-return evidence | Mahmoud, after the census (scoped recovery/backfill vs. explicit written deferral) | **Conditional** (until census and decision) | No |
| OG-07 | MySQL transactional / concurrency tests | Release gate | **NOT_RUN** — no isolated MySQL in this environment. Developer-reported MySQL evidence from earlier phases is not re-asserted. | Mahmoud (environment) | No | Release gate |
| OG-08 | AssabAPP and Dashboard must adopt the contract changes in `api-contract.md` | Release gate | Not started; no client edits in this pass | Mahmoud | No | No |
| OG-09 | Pinned Project instructions still cite Business Rules v2.0 / Plan v2.0 | Governance | Doc hygiene | Mahmoud | No | No |
