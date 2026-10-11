# S1-11 open gaps and carry-overs (2026-10-11)

OG-01..05 and OG-07..09 do not block S1-11 re-audit or S1-12 on safety grounds; each is absent or fails closed. **OG-06 is different:** the independent re-audit (RA-42-01) classed it as a source-demonstrated defect. The live path is now closed in code (see row); acceptance remains Mahmoud's after an independent re-audit.

| ID | Gap | Class | Nature | Needs decision from | Blocks S1-11 | Blocks S1-12 |
|---|---|---|---|---|---|---|
| OG-01 | NEW-03 authorized cashier expenses | v3.2 carry-over | Feature absent | Mahmoud (schedule) | No | No |
| OG-02 | NEW-04 expense-custody tie to personal balance | v3.2 carry-over | Partial; depends on OG-01 | Mahmoud | No | No |
| OG-03 | NEW-05 dues repayment via manager handover | v3.2 carry-over | Feature absent | Mahmoud | No | No |
| OG-04 | NEW-06 return-to-branch-manager choreography after Head return | v3.2 carry-over | Partially evidenced; finality enforced | Mahmoud / Mohamed (scope) | No | No |
| OG-05 | NEW-07 receipt correction (reverse + re-record, linked) | v3.2 carry-over | Fail-closed (`SETTLEMENT_AUTHORITY_UNRESOLVED`). **Generic settlement writer deliberately NOT opened.** | Mohamed (authority), Mahmoud | No | No |
| OG-06 | **Live path CLOSED (S111-FINAL-OG06-N1, this pass).** A new D11 amount-mismatch rejection without a current `transfer_attempt` is now refused with 409 `TRANSFER_PRESENTATION_REQUIRED` at both live entry points (`HandoverService::rejectHandoverForAmountCorrection`, covering cashier→cashier and cashier→manager; `ShiftTransferReceiptService::rejectManagerCashTransfer`), after the row locks and before any write; a stale/foreign/omitted attempt id fails closed as `STALE_TRANSFER_ATTEMPT`. `correctManagerCashTransfer` now also requires the retained cash to be returned and sender-confirmed (`PHYSICAL_RETURN_REQUIRED`). **Residual:** pre-existing untracked rejection evidence (if any) remains immutable and stranded by design; no return is ever synthesized. Whether such rows exist is **NOT VERIFIED**; the owner stated the ERP has not launched, so none are expected, but any future import must be assessed separately. | Source-demonstrated defect (RA-42-01), fix applied, awaiting independent re-audit | Live creation closed; legacy census only if pre-launch data is ever imported | Mahmoud (final acceptance) | Pending independent re-audit | No |
| OG-07 | MySQL transactional / concurrency tests | Release gate | **NOT_RUN** — no isolated MySQL in this environment. Developer-reported MySQL evidence from earlier phases is not re-asserted. | Mahmoud (environment) | No | Release gate |
| OG-08 | AssabAPP and Dashboard must adopt the contract changes in `api-contract.md` | Release gate | Not started; no client edits in this pass | Mahmoud | No | No |
| OG-09 | Pinned Project instructions still cite Business Rules v2.0 / Plan v2.0 | Governance | Doc hygiene | Mahmoud | No | No |
