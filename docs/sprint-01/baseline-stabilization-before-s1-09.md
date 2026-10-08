# S1-08 shared bad-identity triage before S1-09

## Basis and decision

Compared the accepted Phase 2 JUnit (`storage/logs/s1-08-phase2-full-suite-serial.xml`) with the accepted corrected final Unit/Feature/NFR reports by `classname::method`. The shared bad set contains exactly 73 identities: 71 NFR errors, one RecurringOrder unit failure, and one Procurement feature failure. Source and test inspection was used to classify each set; old summary counts alone were not used.

No shared identity is a S1-08 shift/custody/personal-ledger/calculation regression. The 71 NFR errors fail during SQLite test database setup, before the named NFR methods execute. The two non-NFR cases are outside the Sprint 01 financial and replay paths. Accordingly there are no P0 blockers for S1-09. Recommendation: **START S1-09**, using focused replay tests and the visible-progress/JUnit runner below. The NFR harness should be stabilized as a P1 follow-up.

| Classification | Identities | Priority | Code change | Blocks S1-09? |
|---|---:|---|---|---|
| A. Product defect | 1 | DEFER | Separate RecurringOrder product fix | No |
| B. Test environment / SQLite / migration | 71 | P1 | Test harness only | No |
| C. Flaky / time-sensitive | 0 | — | — | No |
| D. Legacy or out-of-scope module | 0 | — | — | No |
| E. Test fixture / test assumption | 1 | DEFER | Correct stale test expectation | No |
| F. Tooling / configuration | 0 | — | — | No |

Priority counts: P0 = 0, P1 = 71, deferred = 2. Nothing was corrected in this triage pass.

## A. PRODUCT DEFECT — DEFER (1)

### `Tests.Unit.Modules.RecurringOrder.RecurringOrderServiceTest::test_compute_next_run_at_from_model_weekly_returns_future_date`

- Observed failure: assertion that the computed date is future/today is false (`RecurringOrderServiceTest.php:35`).
- Root cause from source: `computeNextRunAtFromModel()` uses `start_date` at start-of-day as the search cursor when `next_run_at` is null; `computeNextRunAt()` can select a configured weekday/time already elapsed since that start date. On a Wednesday run with Wednesday configured at 10:00, it can return that past Wednesday 10:00.
- Classification: **A. PRODUCT DEFECT**; this is deterministic scheduling behavior, not a test runner or financial defect.
- Priority: **DEFER** (RecurringOrder is outside S1-08/S1-09 and is not financial/replay infrastructure).
- Code change required: yes, in a separately scoped RecurringOrder task; first freeze time in the unit test and agree whether scheduling means next occurrence after `now` or after `start_date`.
- Blocks S1-09: **No**.
- Recommended next action: create a separate RecurringOrder issue and leave its service untouched during S1-09.

## B. TEST ENVIRONMENT / SQLITE / MIGRATION — P1 (71)

All identities in this section share the same inspected setup signature. The accepted final JUnit shows **70** setup errors creating a second `migrations` table (`table "migrations" already exists`) and **one** SQLite `VACUUM` error (`cannot VACUUM from within a transaction`). Stack traces terminate in Laravel's `RefreshDatabase` → `migrate:fresh` setup path in these test classes, before their named test methods run. The same identities/errors exist in Phase 2. This makes most of the NFR suite unexecuted and noisy, but does not fail or conceal the focused Unit/Feature Sprint 01 financial tests that S1-09 should use.

- Classification for every identity below: **B. TEST ENVIRONMENT / SQLITE / MIGRATION**.
- Priority: **P1**; improve the NFR suite's harness before relying on its coverage as a broad NFR gate.
- Proposed fix: isolate NFR database lifecycle from Laravel's in-memory `RefreshDatabase` transaction. Evaluate an NFR-specific disposable file-backed SQLite configuration with explicit per-class/per-run migration cleanup, and remove the nested VACUUM-in-transaction setup path. Preserve the global `phpunit.xml` financial test behavior and never point this at a developer/local database.
- Code change required: test configuration/harness changes only; no product code change.
- Blocks S1-09: **No**. S1-09 can proceed with focused Unit/Feature replay tests and JUnit evidence. Do not reinterpret these setup failures as passed NFR assertions.
- Recommended next action: separately prototype an NFR-only database bootstrap and prove it on one affected class before expanding to the NFR suite. Avoid broadly changing `RefreshDatabase` or SQLite behavior for all Feature tests.

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

## E. TEST FIXTURE / TEST ASSUMPTION — DEFER (1)

### `Tests.Feature.ProcurementOperationsTest::test_update_order_cannot_set_final_approved`

- Observed failure: test expects HTTP 409 but current route returns 422.
- Root cause from source: `ProcurementCompanyController::transition()` intentionally rejects an attempted direct transition to final approval with `OP_STATUS_TRANSITION_FORBIDDEN` / 422. Its comment states this is a forbidden transition on a still-pending operation, not a conflict indicating an already-final record. The test's 409 expectation is stale.
- Classification: **E. TEST FIXTURE / TEST ASSUMPTION**.
- Priority: **DEFER** (Procurement behavior is outside S1-09 replay and financial foundation scope).
- Code change required: test-only expectation update to 422 in the Procurement work item; no product code change indicated by this audit.
- Blocks S1-09: **No**.
- Recommended next action: update the expectation when that module next receives test maintenance; retain the current 422 contract meanwhile.

## Financial / replay signal assessment

The 71 NFR cases fail before their assertions and are not financial-path tests. Procurement and RecurringOrder are out-of-scope identities. No shared bad identity touches Shift, Custody, Personal Ledger, financial calculations, receipt writers, or migration logic used by S1-08/S1-09. Focused S1-09 tests should still cover repeated requests, duplicate receipts/effects, transaction rollback, and replay behavior directly.

**Can the 71 NFR errors be reduced safely?** Yes, likely through an NFR-only database lifecycle fix, without changing product behavior. It is not done here: the fix affects test harness isolation, should first be proven on one NFR class, and is not a P0 prerequisite to S1-09.
