#!/usr/bin/env bash
set -euo pipefail

repo="$(git rev-parse --show-toplevel)"
cd "$repo"
artifact_dir="${1:-artifacts/assab-harness-mysql84}"
mkdir -p "$artifact_dir"

: "${ASSAB_HARNESS_MYSQL_OPT_IN:?Set ASSAB_HARNESS_MYSQL_OPT_IN=1 for a disposable MySQL 8.4 service}"
[[ "$ASSAB_HARNESS_MYSQL_OPT_IN" == "1" ]] || { echo 'MySQL harness opt-in must equal 1' >&2; exit 2; }
: "${ASSAB_HARNESS_RUN_ID:?Set ASSAB_HARNESS_RUN_ID to digits identifying the disposable run}"
: "${ASSAB_HARNESS_RUN_ATTEMPT:=1}"
[[ "$ASSAB_HARNESS_RUN_ID" =~ ^[0-9]+$ && "$ASSAB_HARNESS_RUN_ATTEMPT" =~ ^[0-9]+$ ]] || { echo 'Run id/attempt must be numeric' >&2; exit 2; }

export APP_ENV=testing APP_DEBUG=false
export APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
export APP_URL='http://127.0.0.1'
export DB_CONNECTION=mysql CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_USERNAME:=root}" "${DB_PASSWORD:=}"
export DB_HOST DB_PORT DB_USERNAME DB_PASSWORD
# Explicit empty exports prevent a developer's .env or process DSN/socket from
# overriding the guarded loopback fields when Laravel boots.
export DB_URL='' DATABASE_URL='' DB_SOCKET=''
[[ "$DB_HOST" == '127.0.0.1' ]] || { echo 'Refusing MySQL host other than exact TCP loopback 127.0.0.1' >&2; exit 2; }
[[ "$DB_PORT" == '3306' ]] || { echo 'Refusing MySQL port other than the disposable service port 3306' >&2; exit 2; }
if [[ -z "$DB_PASSWORD" ]]; then
  [[ "${GITHUB_ACTIONS:-}" == 'true' && "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' \
      && "${ASSAB_HARNESS_ALLOW_EMPTY_CI_PASSWORD:-}" == '1' ]] \
    || { echo 'An empty DB password is allowed only for the ephemeral GitHub-hosted MySQL service' >&2; exit 2; }
fi

version="$(php -r '$pdo=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));echo $pdo->query("SELECT VERSION()")->fetchColumn();')"
printf 'mysql_version=%s\n' "$version" | tee "$artifact_dir/runtime.txt"
[[ "$version" =~ ^8\.4\.[0-9]+ ]] || { echo "Expected MySQL 8.4.x, got $version" >&2; exit 2; }
[[ "$version" != *MariaDB* ]] || { echo 'MariaDB is not accepted by the exact MySQL 8.4 harness' >&2; exit 2; }

create_database() {
  local database="$1"
  [[ "$database" =~ ^(assab_harness_ci|s111_phase234_audit|s111_phase567_test|s111_phase8910_test|pkg7_test|pkg6_disposable|s12_at02|s12_fs12)_[0-9]+_[0-9]+$ ]] \
    || { echo "Refusing unexpected disposable database name: $database" >&2; exit 2; }
  DB_DATABASE="$database" php -r '
    $db=getenv("DB_DATABASE");
    if (preg_match("/(staging|prod)/i",$db)) { throw new RuntimeException("unsafe database name"); }
    $pdo=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE `".$db."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  '
}

run_suite() {
  local label="$1" database="$2"
  shift 2
  create_database "$database"
  export DB_DATABASE="$database"
  set +e
  php scripts/harness/assert_mysql84_target.php 2>&1 | tee "$artifact_dir/$label.target-guard.txt"
  local guard_rc=${PIPESTATUS[0]}
  set -e
  printf '%s\n' "$guard_rc" > "$artifact_dir/$label.target-guard.exit"
  [[ "$guard_rc" -eq 0 ]] || return "$guard_rc"

  set +e
  php artisan migrate:fresh --force 2>&1 | tee "$artifact_dir/$label.migrate.log"
  local migrate_rc=${PIPESTATUS[0]}
  set -e
  printf '%s\n' "$migrate_rc" > "$artifact_dir/$label.migrate.exit"
  [[ "$migrate_rc" -eq 0 ]] || return "$migrate_rc"

  set +e
  vendor/bin/phpunit "$@" --colors=never --log-junit "$artifact_dir/$label.junit.xml" 2>&1 | tee "$artifact_dir/$label.log"
  local rc=${PIPESTATUS[0]}
  set -e
  printf '%s\n' "$rc" > "$artifact_dir/$label.exit"
  return "$rc"
}

run_id="${ASSAB_HARNESS_RUN_ID}_${ASSAB_HARNESS_RUN_ATTEMPT}"
overall=0

run_suite harness "assab_harness_ci_${run_id}" \
  tests/Unit/StagingHarnessGuardTest.php \
  tests/Feature/Harness/StagingHarnessProvisionerTest.php \
  tests/Feature/Harness/AssabHarnessAuthenticationTest.php \
  tests/Feature/Harness/ShiftCycleFixtureTest.php \
  tests/Feature/ShiftCommandIdempotencyHttpTest.php \
  tests/Feature/ShiftCorrectionCommandIdempotencyTest.php \
  tests/Feature/ShiftShortagePostingIdempotencyTest.php || overall=1

run_suite shift-234 "s111_phase234_audit_${run_id}" tests/Feature/ShiftPhases234MySqlConcurrencyTest.php || overall=1
export S111_PHASE567_MYSQL_TESTS=1
run_suite shift-567 "s111_phase567_test_${run_id}" tests/Feature/ShiftPhases567MySqlConcurrencyTest.php || overall=1
unset S111_PHASE567_MYSQL_TESTS
export S111_PHASE8910_MYSQL_TESTS=1
run_suite shift-8910 "s111_phase8910_test_${run_id}" tests/Feature/ShiftPhases8910MySqlConcurrencyTest.php || overall=1
unset S111_PHASE8910_MYSQL_TESTS
export PKG7_MYSQL_TESTS=1
run_suite not-worked "pkg7_test_${run_id}" tests/Feature/ShiftNotWorkedMySqlConcurrencyTest.php || overall=1
unset PKG7_MYSQL_TESTS
export PKG6_MYSQL_TESTS=1
run_suite review "pkg6_disposable_${run_id}" tests/Feature/Pkg6ReviewMySqlConcurrencyTest.php || overall=1
unset PKG6_MYSQL_TESTS
run_suite auth-refresh "s12_at02_${run_id}" tests/Feature/AdminLegacyRefreshConcurrencyTest.php || overall=1
run_suite auth-logout "s12_fs12_${run_id}" tests/Feature/AdminSessionRevocationTest.php --filter=legacy_logout_concurrency || overall=1

junit_args=()
while IFS= read -r file; do junit_args+=(--junit "$file"); done < <(find "$artifact_dir" -maxdepth 1 -name '*.junit.xml' ! -name 'business-rules-evidence.junit.xml' | sort)
exit_args=()
while IFS= read -r file; do exit_args+=(--runner-exit-file "$file"); done < <(find "$artifact_dir" -maxdepth 1 -name '*.exit' | sort)
python3 -I scripts/harness/summarize_evidence.py \
  --registry docs/testing/brc34_acceptance_registry.json \
  --coverage docs/ci/BRC34_AC_COVERAGE_MATRIX.md \
  "${junit_args[@]}" "${exit_args[@]}" \
  --tested-sha "$(git rev-parse HEAD)" --engine mysql-8.4 --out-dir "$artifact_dir" || overall=1

(cd "$artifact_dir" && find . -type f ! -name SHA256SUMS.txt -print0 | sort -z | xargs -0 -r sha256sum) > "$artifact_dir/SHA256SUMS.txt"
exit "$overall"
