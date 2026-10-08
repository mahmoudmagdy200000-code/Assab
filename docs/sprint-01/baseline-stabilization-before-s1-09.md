# S1-08 shared bad-identity triage before S1-09

## Basis and decision

The earlier comparison baseline at c018fa01 identified exactly 73 bad identities: 71 NFR errors, one RecurringOrder unit failure, and one Procurement feature failure. The original failure identities and source-backed classifications are retained below. The later Phase 2 comparison baseline is a separate report; current exact comparison against it appears in the final result section.

No shared identity is a S1-08 shift/custody/personal-ledger/calculation regression. The original 71 NFR errors shared an SQLite connection-recovery/setup cascade; after isolating that probe, six NFR method-level fixture/expectation issues also surfaced and were corrected. The RecurringOrder defect and Procurement assertion were corrected as well. The fresh serial suite now reports all 73 identities passing. There are no P0 S1-09 blockers; S1-09 remains outside the current correction scope pending Mahmoud’s quick recheck.

| Classification | Identities | Priority | Code change | Blocks S1-09? |
|---|---:|---|---|---|
| A. Product defect | 1 | RESOLVED | RecurringOrder schedule cursor now uses current time; frozen-time regression added | No |
| B. Test environment / SQLite / migration | 71 | P3 | NFR connection-recovery probe isolated from shared RefreshDatabase connection | No |
| C. Flaky / time-sensitive | 0 | — | — | No |
| D. Legacy or out-of-scope module | 0 | — | — | No |
| E. Test fixture / test assumption | 1 | RESOLVED | Stale Procurement 409 expectation aligned to the existing 422 contract; follow-on NFR fixture/expectation repairs are recorded below | No |
| F. Tooling / configuration | 0 | — | — | No |

Priority counts: P0 = 0, P1 = 0, P3 = 71, deferred = 0. All 73 identities are resolved on the current worktree. P3 denotes the low-priority NFR harness reliability issue; it was not an S1-09 blocker. During follow-on verification, the now-executing NFR methods also required behavior-preserving repairs: separate same-branch manager fixtures (D12), a missing DB facade import, a route warm-up for a timing-sensitive assertion, valid UTF-8 body validation without requiring a charset parameter, and Laravel FormRequest error-shape expectations. These did not add new identities to the original 73.

## A. PRODUCT DEFECT — RESOLVED (1)

### `Tests.Unit.Modules.RecurringOrder.RecurringOrderServiceTest::test_compute_next_run_at_from_model_weekly_returns_future_date`

- Observed failure: assertion that the computed date is future/today is false (`RecurringOrderServiceTest.php:35`).
- Root cause from source: `computeNextRunAtFromModel()` uses `start_date` at start-of-day as the search cursor when `next_run_at` is null; `computeNextRunAt()` can select a configured weekday/time already elapsed since that start date. On a Wednesday run with Wednesday configured at 10:00, it can return that past Wednesday 10:00.
- Classification: **A. PRODUCT DEFECT**; this is deterministic scheduling behavior, not a test runner or financial defect.
- Priority: **DEFER** from S1-08/S1-09 scope; the authorized baseline hard-close pass corrected it without changing financial behavior.
- Code change required: yes; `$after` now defaults to `now()`, and the regression freezes time and checks the next weekly run.
- Blocks S1-09: **No**.
- Recommended next action: retain the focused regression; no further action before S1-09.

## B. TEST ENVIRONMENT / SQLITE / MIGRATION — P3 (71)

All identities in this section share the same inspected setup signature. The final comparison-baseline JUnit shows **70** setup errors creating a second `migrations` table (`table "migrations" already exists`) and **one** SQLite `VACUUM` error (`cannot VACUUM from within a transaction`). Stack traces terminate in Laravel's `RefreshDatabase` → `migrate:fresh` setup path in these test classes, before their named test methods run. The same identities/errors exist in Phase 2. This makes most of the NFR suite unexecuted and noisy, but does not fail or conceal the focused Unit/Feature Sprint 01 financial tests that S1-09 should use.

- Classification for every identity below: **B. TEST ENVIRONMENT / SQLITE / MIGRATION**.
- Priority: **P3**, not P1; this was NFR harness reliability, not a blocker for focused financial/replay tests.
- Fix applied: `FaultToleranceTest::test_database_connection_recovery` now uses and purges a disposable SQLite connection alias instead of reconnecting Laravel's shared in-memory connection while `RefreshDatabase` owns a transaction. The NFR suite completed normally afterward; the six method-level issues listed in the same original identities were also corrected.
- Code change required: test-only harness and assertion/fixture changes; no broad SQLite or global test-bootstrap change.
- Blocks S1-09: **No**.
- Recommended next action: retain the serial NFR JUnit as evidence and keep the recovery probe isolated.

### `Tests.NFR.Reliability.FaultToleranceTest` (3 identities)

- `test_concurrent_request_handling` — `VACUUM` attempted inside a SQLite transaction during migration setup.
- `test_memory_limit_handling` — duplicate `migrations` table during `RefreshDatabase` setup.
- `test_timeout_handling` — duplicate `migrations` table during `RefreshDatabase` setup.

### `Tests.NFR.Scalability.DataGrowthTest` (7 identities)

- `test_concurrent_writes_with_data_growth`
- `test_data_archiving_readiness`
- `test_database_index_effectiveness`
- `test_eager_loading_performance`
- `test_memory_efficiency_large_results`
- `test_pagination_effectiveness`
- `test_query_performance_with_large_datasets`

Each fails while `RefreshDatabase` attempts to create the already-existing SQLite `migrations` table; the method body is not reached.

### `Tests.NFR.Scalability.LoadTest` (7 identities)

- `test_concurrent_user_handling`
- `test_database_connection_pooling`
- `test_graceful_resource_exhaustion_handling`
- `test_memory_usage_under_load`
- `test_peak_load_handling_month_end`
- `test_performance_degradation_under_load`
- `test_response_time_consistency`

Each fails while `RefreshDatabase` attempts to create the already-existing SQLite `migrations` table; the method body is not reached.

### `Tests.NFR.Security.ApplicationSecurityTest` (13 identities)

- `test_api_key_security`
- `test_input_validation`
- `test_owasp_a01_broken_access_control`
- `test_owasp_a02_cryptographic_failures`
- `test_owasp_a03_injection`
- `test_owasp_a04_insecure_design`
- `test_owasp_a05_security_misconfiguration`
- `test_owasp_a06_vulnerable_components`
- `test_owasp_a07_authentication_failures`
- `test_owasp_a08_data_integrity_failures`
- `test_owasp_a09_logging_failures`
- `test_owasp_a10_ssrf_protection`
- `test_sensitive_data_exposure`

Each fails during SQLite `migrations` table creation in test setup; the method body is not reached.

### `Tests.NFR.Security.AuthenticationTest` (11 identities)

- `test_cross_user_access_prevention`
- `test_invalid_credentials_rejection`
- `test_invalid_token_rejection`
- `test_logout_invalidates_token`
- `test_password_hashing_security`
- `test_permission_granularity`
- `test_role_based_access_control`
- `test_session_timeout_15_minutes`
- `test_token_expiration_24_hours`
- `test_token_refresh_capability`
- `test_unauthorized_access_without_token`

Each fails during SQLite `migrations` table creation in test setup; the method body is not reached.

### `Tests.NFR.Security.DataProtectionTest` (10 identities)

- `test_csrf_protection`
- `test_data_masking_in_logs`
- `test_https_enforcement`
- `test_input_sanitization`
- `test_password_not_in_responses`
- `test_rate_limiting_on_auth`
- `test_sensitive_data_encryption`
- `test_sensitive_headers_not_exposed`
- `test_sql_injection_prevention`
- `test_xss_prevention`

Each fails during SQLite `migrations` table creation in test setup; the method body is not reached.

### `Tests.NFR.Usability.AccessibilityTest` (10 identities)

- `test_api_structure_for_rtl`
- `test_content_type_headers`
- `test_cultural_date_formats`
- `test_currency_format_support`
- `test_date_format_localization`
- `test_error_messages_localization`
- `test_language_support_in_responses`
- `test_number_format_localization`
- `test_timezone_handling`
- `test_unicode_character_support`

Each fails during SQLite `migrations` table creation in test setup; the method body is not reached.

### `Tests.NFR.Usability.UserExperienceTest` (10 identities)

- `test_api_response_consistency`
- `test_data_validation_feedback`
- `test_empty_state_handling`
- `test_error_message_clarity`
- `test_field_level_error_messages`
- `test_pagination_usability`
- `test_response_data_structure_clarity`
- `test_search_functionality_performance`
- `test_success_message_clarity`
- `test_task_completion_steps`

Each fails during SQLite `migrations` table creation in test setup; the method body is not reached.

## E. TEST FIXTURE / TEST ASSUMPTION — RESOLVED (1)

### `Tests.Feature.ProcurementOperationsTest::test_update_order_cannot_set_final_approved`

- Observed failure: test expects HTTP 409 but current route returns 422.
- Root cause from source: `ProcurementCompanyController::transition()` intentionally rejects an attempted direct transition to final approval with `OP_STATUS_TRANSITION_FORBIDDEN` / 422. Its comment states this is a forbidden transition on a still-pending operation, not a conflict indicating an already-final record. The test's 409 expectation is stale.
- Classification: **E. TEST FIXTURE / TEST ASSUMPTION**.
- Priority: **DEFER** from S1-09 scope; this was a test-only contract correction.
- Code change required: test-only expectation update to the existing 422 response; no product behavior change.
- Blocks S1-09: **No**.
- Recommended next action: retain the corrected assertion; no further action before S1-09.

## Financial / replay signal assessment

The 71 NFR cases fail before their assertions and are not financial-path tests. Procurement and RecurringOrder are out-of-scope identities. No shared bad identity touches Shift, Custody, Personal Ledger, financial calculations, receipt writers, or migration logic used by S1-08/S1-09. Focused S1-09 tests should still cover repeated requests, duplicate receipts/effects, transaction rollback, and replay behavior directly.

**Final baseline result:** the latest fresh serial run reports Unit 62 tests / 1,764 assertions, Feature 1,181 / 5,702, and NFR 153 / 504. All stages have zero failures/errors; Feature has one skip. Combined: 1,396 tests / 7,970 assertions. Compared by exact `classname::method` with the Phase 2 comparison baseline, all 96 Phase 2 bad identities are now passing, with zero shared bad identities and zero current-only identities. The original 73 pre-S1-09 bad identities are included in the cleared set. Exact reports and status changes are recorded in `verification.md`. S1-09 was not started at this baseline checkpoint; the recommendation was to start only after Mahmoud’s quick recheck.

## Known procurement and API-contract items

- The `/purchase/orders` response-envelope deviation remains known: `UserExperienceTest::test_api_response_consistency` no longer asserts `success:false`, because the actual Purchase FormRequest 422 response does not use the unified error envelope. This is a procurement/API behavior deviation, not an S1-08 financial behavior change.
- The `RecurringOrderService` `now()`-based scheduling cursor is a known procurement behavior change. It corrected future-run calculation and is not an S1-08 financial behavior change.
