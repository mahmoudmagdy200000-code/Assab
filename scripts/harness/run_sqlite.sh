#!/usr/bin/env bash
set -euo pipefail

repo="$(git rev-parse --show-toplevel)"
cd "$repo"
artifact_dir="${1:-artifacts/assab-harness-sqlite}"
mkdir -p "$artifact_dir"

export APP_ENV=testing APP_DEBUG=false
export APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
export APP_URL='http://127.0.0.1'
export DB_CONNECTION=sqlite DB_DATABASE=':memory:'
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_URL DB_SOCKET DATABASE_URL
unset MYSQL_HOST MYSQL_PWD MYSQL_DATABASE MYSQL_USER MYSQL_ROOT_PASSWORD MARIADB_ROOT_PASSWORD

if [[ "${GITHUB_ACTIONS:-}" != "true" ]]; then
  export ASSAB_CI_LOCAL_SELFTEST=1
fi
bash scripts/ci/assert_isolated_env.sh | tee "$artifact_dir/isolation.txt"

test -x vendor/bin/paratest || { echo 'vendor/bin/paratest is missing; run composer install first' >&2; exit 2; }
config="$(mktemp "$repo/phpunit.assab-harness.XXXXXX.xml")"
trap 'rm -f "$config"' EXIT
python3 -I scripts/ci/make_phpunit_config.py fast \
  --list scripts/ci/brc34_fast_suite.txt \
  --also-changed-since 3c85fd9721b49dad0222831a1992c5a0816761c5 \
  --out "$config" | tee "$artifact_dir/phpunit-config.txt"
cp "$config" "$artifact_dir/phpunit.harness.generated.xml"

set +e
vendor/bin/paratest -c "$config" --processes=4 --display-skipped --display-warnings --colors=never \
  --log-junit "$artifact_dir/harness.junit.xml" 2>&1 | tee "$artifact_dir/harness.log"
phpunit_rc=${PIPESTATUS[0]}
set -e
printf '%s\n' "$phpunit_rc" > "$artifact_dir/harness.exit"

junit_args=()
[[ -f "$artifact_dir/harness.junit.xml" ]] && junit_args+=(--junit "$artifact_dir/harness.junit.xml")
evidence_rc=0
python3 -I scripts/harness/summarize_evidence.py \
  --registry docs/testing/brc34_acceptance_registry.json \
  --coverage docs/ci/BRC34_AC_COVERAGE_MATRIX.md \
  "${junit_args[@]}" --runner-exit-file "$artifact_dir/harness.exit" \
  --tested-sha "$(git rev-parse HEAD)" --engine sqlite-memory --out-dir "$artifact_dir" || evidence_rc=$?

(cd "$artifact_dir" && find . -type f ! -name SHA256SUMS.txt -print0 | sort -z | xargs -0 -r sha256sum) > "$artifact_dir/SHA256SUMS.txt"
[[ "$phpunit_rc" -eq 0 ]] || exit "$phpunit_rc"
exit "$evidence_rc"
