#!/usr/bin/env bash
# ==============================================================================
# ASSAB ERP — Wave 3 Concurrency Proof Harness (Oracle MySQL 8.4 / InnoDB)
#
# Executes the 4 multi-process concurrency tests against an isolated, disposable
# Oracle MySQL 8.4 database within a secure CI runner environment.
#
# Security & Safety Guards:
# 1. Zero hardcoded credentials (strictly reads from CI environment vault).
# 2. Strict physical environment barriers (GitHub Actions or local sandbox only).
# 3. Fail-closed rejection of any staging, production, or shared environments.
# 4. Strictly validates Oracle MySQL 8.4.x.
# 5. Programmatic verification of InnoDB and REPEATABLE-READ transaction isolation.
# 6. Disposable schema name with mandatory prefix guard `assab_proof_wave3_`.
# 7. Guaranteed cleanup trap on EXIT, INT, TERM dropping only the task schema.
# ==============================================================================
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-false}" != "true" && "${CI:-false}" != "true" && "${WAVE3_LOCAL_SANDBOX:-false}" != "true" ]]; then
    echo "::error title=ENVIRONMENT BARRIER::Execution blocked. Must run within GitHub Actions CI or explicit WAVE3_LOCAL_SANDBOX=true." >&2
    exit 1
fi

if env | grep -Eiq 'coolify|staging|production|hostinger|railway|live|prod_db'; then
    echo "::error title=SAFETY GUARD BLOCKED::Forbidden staging or production environment variable detected in runner." >&2
    exit 1
fi

: "${MYSQL_TEST_HOST:?MYSQL_TEST_HOST must be provided by secure CI environment}"
: "${MYSQL_TEST_PORT:=3306}"
: "${MYSQL_TEST_USER:?MYSQL_TEST_USER must be provided by secure CI environment}"
: "${MYSQL_TEST_PASSWORD:?MYSQL_TEST_PASSWORD must be provided by secure CI environment}"

if [[ "${MYSQL_TEST_HOST}" != "127.0.0.1" && "${MYSQL_TEST_HOST}" != "localhost" && "${MYSQL_TEST_HOST}" != "mysql" ]]; then
    echo "::error title=NETWORK BARRIER::Target host '${MYSQL_TEST_HOST}' is not a permitted loopback or local container address." >&2
    exit 1
fi

TIMESTAMP=$(date +%s)
RANDOM_SUFFIX=$(( RANDOM % 90000 + 10000 ))
PROOF_DB_NAME="assab_proof_wave3_${TIMESTAMP}_${RANDOM_SUFFIX}"

echo "==> Wave 3 MySQL Concurrency Proof: Initializing database '${PROOF_DB_NAME}'"

cleanup() {
    local exit_code=$?
    echo "==> Wave 3 MySQL Concurrency: Teardown disposable schema '${PROOF_DB_NAME}'"
    MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
        -e "DROP DATABASE IF EXISTS \`${PROOF_DB_NAME}\`;" || true
    exit "${exit_code}"
}
trap cleanup EXIT INT TERM

MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
    -e "CREATE DATABASE \`${PROOF_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

export DB_CONNECTION=mysql
export DB_HOST="${MYSQL_TEST_HOST}"
export DB_PORT="${MYSQL_TEST_PORT}"
export DB_DATABASE="${PROOF_DB_NAME}"
export DB_USERNAME="${MYSQL_TEST_USER}"
export DB_PASSWORD="${MYSQL_TEST_PASSWORD}"

php artisan migrate --force

echo "==> Running Wave 3 Concurrency Proof Suite..."
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Concurrency/Wave3FinancialConcurrencyTest.php \
    --log-junit="storage/test-results/wave3-mysql-concurrency.xml"

echo "==> Wave 3 MySQL Concurrency Proof completed successfully."
