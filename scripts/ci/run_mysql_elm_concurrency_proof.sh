#!/usr/bin/env bash
# ==============================================================================
# ASSAB ERP — ELM-1 / ELM-2 Cross-Module Concurrency Proof Harness (Oracle MySQL 8.4 / InnoDB)
#
# Executes the 6 multi-process concurrency tests against an isolated, disposable
# Oracle MySQL 8.4 database within a secure CI runner environment.
#
# Security & Safety Guards:
# 1. Zero hardcoded credentials (strictly reads from CI environment vault).
# 2. Strict physical environment barriers (GitHub Actions or local sandbox only).
# 3. Fail-closed rejection of any staging, production, or shared environments.
# 4. Strictly validates Oracle MySQL 8.4.x (rejects MariaDB and other versions).
# 5. Programmatic verification of InnoDB (default engine + all migrated tables)
#    and REPEATABLE-READ transaction isolation.
# 6. Clears Laravel config/cache to guarantee clean runtime isolation.
# 7. Disposable schema name with mandatory prefix guard `assab_proof_elm_`.
# 8. Guaranteed cleanup trap on EXIT, INT, TERM dropping only the task schema.
# 9. Strict Zero-Skip policy: exactly 6 tests must pass, 0 skips, 0 failures.
# ==============================================================================
set -euo pipefail

# 1. Physical Environment Barriers
if [[ "${GITHUB_ACTIONS:-false}" != "true" && "${CI:-false}" != "true" && "${ELM_LOCAL_SANDBOX:-false}" != "true" ]]; then
    echo "::error title=ENVIRONMENT BARRIER::Execution blocked. Must run within GitHub Actions CI or explicit ELM_LOCAL_SANDBOX=true." >&2
    exit 1
fi

# Fail-closed check: Reject any environment contaminated with external production/staging vars
if env | grep -Eiq 'coolify|staging|production|hostinger|railway|live|prod_db'; then
    echo "::error title=SAFETY GUARD BLOCKED::Forbidden staging or production environment variable detected in runner." >&2
    exit 1
fi

# 2. Verify required environment variables from secure CI environment
: "${MYSQL_TEST_HOST:?MYSQL_TEST_HOST must be provided by secure CI environment}"
: "${MYSQL_TEST_PORT:=3306}"
: "${MYSQL_TEST_USER:?MYSQL_TEST_USER must be provided by secure CI environment}"
: "${MYSQL_TEST_PASSWORD:?MYSQL_TEST_PASSWORD must be provided by secure CI environment}"

# Network Barrier: Ensure host is strictly a local container or loopback address
if [[ "${MYSQL_TEST_HOST}" != "127.0.0.1" && "${MYSQL_TEST_HOST}" != "localhost" && "${MYSQL_TEST_HOST}" != "mysql" ]]; then
    echo "::error title=NETWORK BARRIER::Target host '${MYSQL_TEST_HOST}' is not a permitted loopback or local container address." >&2
    exit 1
fi

# Safety Guard: Ensure host does not target production or staging endpoints
for blocked in "prod" "production" "live" "staging" "shared"; do
    if [[ "${MYSQL_TEST_HOST,,}" == *"${blocked}"* ]]; then
        echo "::error title=SAFETY GUARD BLOCKED::Target host '${MYSQL_TEST_HOST}' contains blocked keyword '${blocked}'." >&2
        exit 1
    fi
done

# 3. Generate unique disposable schema name with mandatory prefix guard
RUN_ID="$(date +%s)_${RANDOM}"
DISPOSABLE_DB="assab_proof_elm_${RUN_ID}"

# Strict safety guard: Database name must match exact proof pattern
if [[ ! "${DISPOSABLE_DB}" =~ ^assab_proof_elm_[0-9]+_[0-9]+$ ]]; then
    echo "::error title=SAFETY GUARD BLOCKED::Target database name '${DISPOSABLE_DB}' violates safety prefix guard." >&2
    exit 1
fi

# Reject protected database names unconditionally
for protected_name in "assab" "production" "staging" "main" "master" "app" "forge" "default" "mysql" "sys"; do
    if [[ "${DISPOSABLE_DB,,}" == "${protected_name}" ]]; then
        echo "::error title=SAFETY GUARD BLOCKED::Attempted to target protected database name '${protected_name}'." >&2
        exit 1
    fi
done

echo "==> [ELM CI Proof] Target disposable schema: ${DISPOSABLE_DB}"
echo "==> [ELM CI Proof] Host: ${MYSQL_TEST_HOST}:${MYSQL_TEST_PORT}"
echo "==> [ELM CI Proof] Test User: ${MYSQL_TEST_USER}"

# 4. Setup robust cleanup trap (drops ONLY the task's disposable schema)
cleanup() {
    local exit_code=$?
    echo "==> [ELM CI Proof] Executing cleanup trap for: ${DISPOSABLE_DB}..."
    MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
        -e "DROP DATABASE IF EXISTS \`${DISPOSABLE_DB}\`;" 2>/dev/null || true
    echo "==> [ELM CI Proof] Cleanup finished with original exit code: ${exit_code}"
    exit "${exit_code}"
}
trap cleanup EXIT INT TERM

# 5. Programmatic Pre-flight Verification: Oracle MySQL 8.4 Server Identity & Isolation
echo "==> [ELM CI Proof] Verifying Oracle MySQL 8.4 server identity, version, and transaction isolation..."
DB_INSPECTION=$(MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" -N -B \
    -e "SELECT @@version, @@default_storage_engine, @@transaction_isolation;")

read -r DB_VERSION DB_ENGINE DB_ISOLATION <<< "${DB_INSPECTION}"

echo "    Database Version:   ${DB_VERSION}"
echo "    Storage Engine:     ${DB_ENGINE}"
echo "    Isolation Level:    ${DB_ISOLATION}"

# Strict Oracle MySQL 8.4 verification
if [[ ! "${DB_VERSION}" =~ ^8\.4\. ]]; then
    echo "::error title=INCORRECT MYSQL VERSION::Expected Oracle MySQL 8.4.x, got '${DB_VERSION}'." >&2
    exit 1
fi

if [[ "${DB_VERSION,,}" =~ mariadb ]]; then
    echo "::error title=MARIADB DETECTED::Expected Oracle MySQL 8.4, detected MariaDB '${DB_VERSION}'." >&2
    exit 1
fi

if [[ "${DB_ENGINE,,}" != "innodb" ]]; then
    echo "::error title=INCORRECT STORAGE ENGINE::Default engine is '${DB_ENGINE}', must be InnoDB." >&2
    exit 1
fi

if [[ "${DB_ISOLATION,,}" != "repeatable-read" && "${DB_ISOLATION,,}" != "repeatable_read" ]]; then
    echo "::error title=INCORRECT ISOLATION::Transaction isolation is '${DB_ISOLATION}', must be REPEATABLE-READ." >&2
    exit 1
fi

# 6. Create disposable database
echo "==> [ELM CI Proof] Creating disposable schema..."
MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
    -e "CREATE DATABASE \`${DISPOSABLE_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 7. Clear Laravel configuration caches to ensure clean runtime isolation
echo "==> [ELM CI Proof] Clearing framework caches..."
php artisan config:clear || true
php artisan cache:clear || true

# 8. Execute Laravel Migrations on the disposable schema
echo "==> [ELM CI Proof] Running migrations on disposable schema..."
DB_CONNECTION=mysql \
DB_HOST="${MYSQL_TEST_HOST}" \
DB_PORT="${MYSQL_TEST_PORT}" \
DB_DATABASE="${DISPOSABLE_DB}" \
DB_USERNAME="${MYSQL_TEST_USER}" \
DB_PASSWORD="${MYSQL_TEST_PASSWORD}" \
php artisan migrate --force

# 9. Programmatic Table Storage Engine Verification (Assert all created tables use InnoDB)
echo "==> [ELM CI Proof] Verifying table storage engines in schema ${DISPOSABLE_DB}..."
NON_INNODB_TABLES=$(MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" -D "${DISPOSABLE_DB}" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DISPOSABLE_DB}' AND engine != 'InnoDB';")

if [[ "${NON_INNODB_TABLES}" -gt 0 ]]; then
    echo "::error title=NON-INNODB TABLES DETECTED::Found ${NON_INNODB_TABLES} table(s) in schema '${DISPOSABLE_DB}' not using InnoDB." >&2
    MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" -D "${DISPOSABLE_DB}" \
        -e "SELECT table_name, engine FROM information_schema.tables WHERE table_schema = '${DISPOSABLE_DB}' AND engine != 'InnoDB';"
    exit 1
fi
echo "    Verified: All tables in schema '${DISPOSABLE_DB}' use InnoDB engine."

# 10. Execute the 6 Concurrency Proof Tests
mkdir -p storage/logs
JUNIT_REPORT="storage/logs/elm_concurrency_proof_junit.xml"
RAW_LOG="storage/logs/elm_concurrency_proof_raw.log"

echo "==> [ELM CI Proof] Executing 6 Multi-Process Concurrency Proof Tests..."
set +e
DB_CONNECTION=mysql \
DB_HOST="${MYSQL_TEST_HOST}" \
DB_PORT="${MYSQL_TEST_PORT}" \
DB_DATABASE="${DISPOSABLE_DB}" \
DB_USERNAME="${MYSQL_TEST_USER}" \
DB_PASSWORD="${MYSQL_TEST_PASSWORD}" \
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Concurrency/CrossModuleEmployeeLedgerConcurrencyTest.php \
    --log-junit="${JUNIT_REPORT}" | tee "${RAW_LOG}"
TEST_EXIT_CODE=$?
set -e

if [[ "${TEST_EXIT_CODE}" -ne 0 ]]; then
    echo "::error title=TEST EXECUTION FAILED::PHPUnit exited with code ${TEST_EXIT_CODE}." >&2
    exit "${TEST_EXIT_CODE}"
fi

# 11. Strict JUnit Verification (Zero-Skip Policy & Test Count)
echo "==> [ELM CI Proof] Inspecting JUnit XML for Zero-Skip enforcement and exact test count..."
if [[ ! -f "${JUNIT_REPORT}" ]]; then
    echo "::error title=MISSING JUNIT::JUnit XML report '${JUNIT_REPORT}' was not generated." >&2
    exit 1
fi

# Extract test count from root testsuite
TEST_COUNT=$(grep -o 'tests="[0-9]*"' "${JUNIT_REPORT}" | head -1 | grep -o '[0-9]*' || true)
if [[ "${TEST_COUNT}" -ne 6 ]]; then
    echo "::error title=INCORRECT TEST COUNT::Expected exactly 6 tests in JUnit report, got '${TEST_COUNT}'." >&2
    exit 1
fi

if grep -q '<skipped' "${JUNIT_REPORT}"; then
    echo "::error title=SKIPPED TESTS DETECTED::Concurrency tests were SKIPPED. In CI MySQL harness, SKIP is a FAILURE." >&2
    exit 1
fi

if grep -q '<failure' "${JUNIT_REPORT}" || grep -q '<error' "${JUNIT_REPORT}"; then
    echo "::error title=TEST FAILURES DETECTED::JUnit report contains failures or errors." >&2
    exit 1
fi

echo "==> [ELM CI Proof] SUCCESS: All 6 concurrency tests PASSED on Oracle MySQL 8.4 / InnoDB with independent connections."
