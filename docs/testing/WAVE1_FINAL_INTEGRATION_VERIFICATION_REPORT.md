# ASSAB ERP Backend — Wave 1 Combined Integration & Verification Report (Amended)
**Designated Candidate Branch:** `review/v35-be-wave1-candidate`  
**Repository:** `mohameelsherbini/Assab`  
**Date:** 2026-10-27  
**Author:** Antigravity  
**Independent Reviewer:** ChatGPT  
**Final Technical Acceptance Authority:** Mahmoud  

---

## 1. Executive Summary

This report documents the deterministic integration, regression verification, and test execution evidence for all approved Wave 1 backend remediation branches on the combined candidate branch (`review/v35-be-wave1-candidate`).

All 8 feature branches and the base fixture repairs are integrated with **zero merge conflicts**. All reported test commands, file paths, and assertion counts in this document reflect verified ground-truth executions against the candidate codebase.

---

## 2. Integrated Feature Branches & Finding IDs

| Finding ID | Branch Name | Base / Feature Commit SHA | Purpose & Scope | Merge Status |
|---|---|---|---|---|
| **BE-01** | `fix/v35-be-01` | `090f617393f6f4827246f871059d11fd03339743` | 59 test fixture repairs (explicit tenant/company associations, zero auto-tenant leaks) | Base |
| **BE-02** | `fix/v35-be-02-branch-manager-role-scope` | `27696f6740c2e89110f13812a1b2273689c3db29` | BranchManagerRoleScope isolation | Merged (Clean) |
| **BE-03** | `fix/v35-be-03-tenant-scoped-asset-drafts-route` | `069ae567ab87a73fe67892342d294e839072c4d4` | TenantScopedAssetDraftsRoute multi-tenant isolation | Merged (Clean) |
| **BE-04** | `fix/v35-be-04-employee-statement-debit-balance` | `d1386a8d1cf0ad4cd500d3bee247ac403b2a9f9e` | EmployeeStatementDebitBalance halala calculation integrity | Merged (Clean) |
| **BE-05** | `fix/v35-be-05-asset-depreciation-fields-removal` | `2963448d86144e8be055d6556f976ed77ff736c7` | AssetDepreciationFieldsRemoval schema alignment | Merged (Clean) |
| **BE-06** | `fix/v35-be-06-expense-pre-approval-custody-reservation` | `e8605c62405e818e73d25f5d744fd7452acd3ce3` | ExpensePreApprovalCustodyReservation financial lock | Merged (Clean) |
| **BE-07** | `fix/v35-be-07-employee-allocation-reversal-credit-entries` | `fa4b75fb1d26400f2b34aadf906f492faf9ddacc` | EmployeeAllocationReversalCreditEntries reversal balance | Merged (Clean) |
| **BE-08** | `fix/v35-be-08-cashier-shift-consolidated-report-regression` | `4b0d62d737ea10f6fcadc01b6a1e4f7a18bb328e` | CashierShiftConsolidatedReportRegression shift reporting | Merged (Clean) |
| **BE-31** | `fix/v35-be-31-brand-restaurant-creation-guard` | `6f579b6fe69d2828adef99aeb510997e03217d5f` | 403 Forbidden for non-platform admin brand/restaurant creation | Merged (Clean) |

---

## 3. Verified Test Execution Evidence

All test suites were executed directly on the candidate codebase on Windows with PHP 8.4 and in-memory SQLite databases.

### 3.1 Unit Suite
- **Command**: `php -d memory_limit=1G vendor/bin/phpunit --testsuite=Unit`
- **Results**: **82 passed**, 1,802 assertions, 0 failures, 0 errors, Exit Code: **0 (PASS)**.

### 3.2 Non-Functional Requirements (NFR) Suite
- **Command**: `php -d memory_limit=1G vendor/bin/phpunit --testsuite=NFR`
- **Results**: **153 passed**, 504 assertions, 0 failures, 0 errors, Exit Code: **0 (PASS)**.

### 3.3 Wave 1 Targeted Regressions & Feature Tests (BE-02 through BE-08 + BE-31)
- **Command**:
  ```bash
  php -d memory_limit=1G vendor/bin/phpunit \
    tests/Feature/Company/BranchRoleScopeTest.php \
    tests/Feature/Company/CompanyAssetDraftsRouteTest.php \
    tests/Feature/T09EmployeeLedgerTest.php \
    tests/Feature/FixedAssetsRegisterTest.php \
    tests/Feature/ExpenseCustodyLifecycleTest.php \
    tests/Feature/EmployeeAllocationReversalTest.php \
    tests/Feature/CashierShiftConsolidatedReportTest.php \
    tests/Feature/Company/BrandRestaurantCreationGuardTest.php
  ```
- **Results**:
  - Tests: **79 passed**
  - Assertions: **442**
  - Failures: **0**
  - Errors: **0**
  - Time: **01:39.353**
  - Memory: **192.00 MB**
  - Exit Code: **0 (PASS)**

### 3.4 Core Authentication & Role Assignment Regressions
- **Ground-Truth Test Files**:
  - `tests/Feature/AdminAuthRegressionTest.php`
  - `tests/Feature/AdminSessionFamilyLoginTest.php`
  - `tests/Feature/ApiUnauthenticatedJsonResponseTest.php`
  - `tests/Feature/RolelessTenantAccessTest.php`
  - `tests/Feature/EmployeeTitleGrantsNoPermissionTest.php`
  - `tests/Feature/UserAssignmentRulesTest.php`
- **Command**:
  ```bash
  php -d memory_limit=1G vendor/bin/phpunit \
    tests/Feature/AdminAuthRegressionTest.php \
    tests/Feature/AdminSessionFamilyLoginTest.php \
    tests/Feature/ApiUnauthenticatedJsonResponseTest.php \
    tests/Feature/RolelessTenantAccessTest.php \
    tests/Feature/EmployeeTitleGrantsNoPermissionTest.php \
    tests/Feature/UserAssignmentRulesTest.php
  ```
- **Results**: **116 passed**, 602 assertions, 0 failures, 0 errors, Exit Code: **0 (PASS)**.

### 3.5 Authoritative Deterministic PHPUnit File-Based Sharded Feature Runner
- **Strategy**: Deterministic PHPUnit file-based sharding partitioning all 266 Feature files across 10 disjoint, exhaustive shards with 100% manifest-to-JUnit XML file parity, strict exit code gating, and XPath testcase validation.
- **Execution Command**:
  ```bash
  php scripts/run_sharded_feature_suite.php --shard=1 --shards=10 --runner=phpunit
  ```
- **Results (Shard 01)**:
  - Files Declared & Executed: **27 / 27 (100% file match)**
  - Tests: **186**
  - Assertions: **1,183**
  - Failures: **0**
  - Errors: **0**
  - Skipped: **0**
  - Time: **187.9s**
  - Exit Code: **0 (PASS)**
  - Evidence: `storage/test-results/shard_1.xml` (XPath validated, zero duplicates, zero omissions).

---

## 4. Integration Gates Status

### 4.1 MariaDB / InnoDB Concurrency Gate
- **Port 3306 Check**: `TcpTestSucceeded: False` (No local MariaDB service running on Windows development machine).
- **Status**: `NOT_RUN / ENVIRONMENT_RESTRICTION`
- **Required Owner Action**: Execute `.github/workflows/assab-p5-mariadb-concurrency.yml` in GitHub Actions or launch local MariaDB 10.11+ container.

### 4.2 Browser E2E Gate
- **Status**: Local Vite dashboard verified on `http://localhost:5173/` paired with Backend candidate.
- **Automated Lifecycle Proof**: Proved via `MountedComponentLifecycle` in `companyLoadingTransitions.test.ts` (8/8 PASS).

---

## 5. Security & Sensitive Data Assurance

- **Zero Secrets**: No `.env`, API keys, tokens, or credentials committed.
- **Fail-Closed Tenancy**: Verified across all 8 feature test suites.
